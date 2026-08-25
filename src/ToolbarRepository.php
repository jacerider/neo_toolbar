<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;

/**
 * Toolbar repository manager.
 */
final class ToolbarRepository {

  /**
   * The toolbar.
   *
   * @var \Drupal\neo_toolbar\ToolbarInterface|null
   */
  protected ToolbarInterface|null $toolbar;

  /**
   * Constructs a ToolbarRepository object.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * Gets the toolbar this request renders.
   *
   * A route carrying a `neo_toolbar` parameter wins outright and is put into
   * edit mode; otherwise the enabled toolbars are filtered by view access,
   * sorted with \Drupal\neo_toolbar\Entity\Toolbar::sort() and the first one
   * wins. The answer is memoised for the life of this object.
   *
   * @param bool $checkAccess
   *   TRUE to filter the candidate toolbars by view access.
   *
   * @return \Drupal\neo_toolbar\ToolbarInterface|null
   *   The active toolbar, or NULL if there is none.
   */
  public function getActive($checkAccess = TRUE):ToolbarInterface|null {
    if (!isset($this->toolbar)) {
      $this->toolbar = NULL;
      $toolbar = $this->routeMatch->getParameter('neo_toolbar');
      if ($toolbar instanceof ToolbarInterface) {
        $toolbar->setEditMode();
        $this->toolbar = $toolbar;
      }
      else {
        $toolbars = $this->entityTypeManager->getStorage('neo_toolbar')->loadByProperties([
          'status' => TRUE,
        ]);
        if ($checkAccess) {
          $toolbars = array_filter($toolbars, function (ToolbarInterface $toolbar) {
            return $toolbar->access('view');
          });
          if ($toolbars) {
            uasort($toolbars, 'Drupal\neo_toolbar\Entity\Toolbar::sort');
            $this->toolbar = reset($toolbars);
          }
        }
      }
    }
    return $this->toolbar;
  }

  /**
   * Runs the item pipeline for a toolbar.
   *
   * This is the module's densest piece of logic and it used to live inside
   * `Toolbar::getItems()`, which is a config entity — the one class in Drupal
   * that should hold as little of this as possible. It loads the toolbar's
   * enabled items in weight order, filters them by view access while
   * accumulating cacheability, collapses a derived region whose items have all
   * gone (taking the item that opens it with it), drops items their immediate
   * siblings forbid, and restores a triggering item whose derived region still
   * has children.
   *
   * It is stateless: it takes a toolbar, runs the pass, fills the cacheable
   * metadata the caller handed it, and remembers nothing. The memo stays on the
   * toolbar entity, where it has always been, so one entity instance still
   * computes once and two instances still compute separately.
   *
   * @param \Drupal\neo_toolbar\ToolbarInterface $toolbar
   *   The toolbar whose items to answer.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheableMetadata
   *   Cacheable metadata to fill with every item the pass examined, including
   *   the ones it dropped — a caller cannot recover those from the answer.
   *
   * @return \Drupal\neo_toolbar\ToolbarItemInterface[]
   *   The surviving items, keyed by item id.
   */
  public function getToolbarItems(ToolbarInterface $toolbar, ?CacheableMetadata $cacheableMetadata = NULL): array {
    $cacheableMetadata = $cacheableMetadata ?? new CacheableMetadata();
    $items = [];
    $ids = $this->entityTypeManager->getStorage('neo_toolbar_item')->getQuery()
      ->accessCheck(TRUE)
      ->condition('toolbar', $toolbar->id())
      ->condition('status', TRUE)
      ->sort('weight')
      ->execute();
    if ($ids) {
      /** @var \Drupal\neo_toolbar\ToolbarItemInterface[] $items */
      $items = $this->entityTypeManager->getStorage('neo_toolbar_item')->loadMultiple($ids);

      if (!$toolbar->isEditMode()) {
        // Temporarily group items by region to check sibling access. This
        // grouping has all items prior to access checks.
        $allItemsByRegion = [];
        foreach ($items as $key => $item) {
          $allItemsByRegion[$item->getRegionId()][$key] = $item;
        }

        // Check access for each item.
        $items = array_filter($items, function ($item) use ($cacheableMetadata) {
          $access = $item->access('view', NULL, TRUE);
          $cacheableMetadata->addCacheableDependency($item);
          $cacheableMetadata->addCacheableDependency($access);
          return $access->isAllowed();
        });

        // Temporarily group items by region to check sibling access.
        $itemsByRegion = [];
        foreach ($items as $key => $item) {
          $itemsByRegion[$item->getRegionId()][$key] = $item;
        }

        // Check to see if we have empty regions after access checks.
        foreach ($allItemsByRegion as $rid => $regionItems) {
          if (!isset($itemsByRegion[$rid])) {
            // We have an empty region which may be toggled by an item.
            $regionItemId = str_replace('item:', '', $rid);
            if (isset($items[$regionItemId])) {
              // If we do have a triggering item, we need to remove it from
              // the items list as well as the items by region list.
              unset($itemsByRegion[$items[$regionItemId]->getRegionId()][$regionItemId]);
              unset($items[$regionItemId]);
            }
          }
        }

        // Check sibling access for each item in a region.
        foreach ($itemsByRegion as $rid => $regionItems) {
          $removeRegionItems = array_filter($regionItems, function ($item, $key) use ($regionItems) {
            $keys = array_keys($regionItems);
            $found_index = array_search($key, $keys);
            if ($found_index !== FALSE) {
              $previous = $found_index > 0 ? $keys[$found_index - 1] : NULL;
              $next = $found_index < count($keys) - 1 ? $keys[$found_index + 1] : NULL;
              $siblingAccess = $item->accessBySiblings($regionItems[$previous] ?? NULL, $regionItems[$next] ?? NULL);
              if ($siblingAccess->isForbidden()) {
                return TRUE;
              }
            }
            return FALSE;
          }, ARRAY_FILTER_USE_BOTH);
          $items = array_diff_key($items, $removeRegionItems);
        }

        // Handle region items that may need to be restored based on their
        // children state.
        foreach ($itemsByRegion as $rid => $regionItems) {
          // Check if this is a dynamically generated region created by an
          // item. If region is in this list it has items.
          if (strpos($rid, 'item:region') === 0) {
            // Extract the ID of the item that created this region.
            $triggeringItemId = substr($rid, 5);

            // Check if the triggering item exists in any region.
            $triggeringItemExists = FALSE;
            foreach ($itemsByRegion as $rid => $i) {
              if (isset($items[$triggeringItemId])) {
                $triggeringItemExists = TRUE;
                break;
              }
            }

            // If the triggering item doesn't exist in any active region but
            // its region has items, we need to restore the triggering item.
            if (!$triggeringItemExists) {
              foreach ($allItemsByRegion as $rid => $originalItems) {
                if (isset($originalItems[$triggeringItemId])) {
                  $items[$triggeringItemId] = $originalItems[$triggeringItemId];
                  break;
                }
              }
            }
          }
        }

      }
    }
    return $items;
  }

  /**
   * Gets the active toolbar's items that use a given item plugin.
   *
   * This goes through the toolbar entity's accessor rather than
   * \Drupal\neo_toolbar\ToolbarRepository::getToolbarItems() because it wants
   * that accessor's memo: neo_toolbar_block_access() calls this twice per
   * block, and the pipeline method is stateless.
   *
   * @param string $plugin_type
   *   The toolbar item plugin id.
   *
   * @return \Drupal\neo_toolbar\ToolbarItemInterface[]
   *   The matching items, in the order the toolbar answers them.
   */
  public function getToolbarItemsOfType($plugin_type) {
    $items = [];
    if ($toolbar = $this->getActive()) {
      foreach ($toolbar->getItems() as $item) {
        if ($item->getPluginId() == $plugin_type) {
          $items[] = $item;
        }
      }
    }
    return $items;
  }

  /**
   * Checks whether the active toolbar has an item of a given plugin type.
   *
   * @param string $plugin_type
   *   The toolbar item plugin id.
   *
   * @return bool
   *   TRUE if the active toolbar has at least one item of that type.
   */
  public function hasToolbarItemsOfType($plugin_type) {
    return !empty($this->getToolbarItemsOfType($plugin_type));
  }

}
