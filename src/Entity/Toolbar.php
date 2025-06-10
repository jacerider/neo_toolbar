<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar\Entity;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Plugin\DefaultLazyPluginCollection;
use Drupal\neo\VisibilityEntityTrait;
use Drupal\neo_toolbar\ToolbarInterface;

/**
 * Defines the toolbar entity type.
 *
 * @ConfigEntityType(
 *   id = "neo_toolbar",
 *   label = @Translation("Toolbar"),
 *   label_collection = @Translation("Toolbars"),
 *   label_singular = @Translation("toolbar"),
 *   label_plural = @Translation("toolbars"),
 *   label_count = @PluralTranslation(
 *     singular = "@count toolbar",
 *     plural = "@count toolbars",
 *   ),
 *   handlers = {
 *     "access" = "Drupal\neo_toolbar\ToolbarAccessControlHandler",
 *     "list_builder" = "Drupal\neo_toolbar\ToolbarListBuilder",
 *     "form" = {
 *       "add" = "Drupal\neo_toolbar\Form\ToolbarForm",
 *       "edit" = "Drupal\neo_toolbar\Form\ToolbarForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm",
 *     },
 *   },
 *   config_prefix = "neo_toolbar",
 *   static_cache = true,
 *   admin_permission = "administer neo_toolbar",
 *   links = {
 *     "collection" = "/admin/config/neo/toolbar",
 *     "add-form" = "/admin/config/neo/toolbar/add",
 *     "edit-form" = "/admin/config/neo/toolbar/{neo_toolbar}",
 *     "delete-form" = "/admin/config/neo/toolbar/{neo_toolbar}/delete",
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *     "weight" = "weight"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "weight",
 *     "visibility",
 *   },
 * )
 */
final class Toolbar extends ConfigEntityBase implements ToolbarInterface {
  use VisibilityEntityTrait;

  /**
   * The toolbar id.
   */
  protected string $id;

  /**
   * The toolbar label.
   */
  protected string $label;

  /**
   * The toolbar weight.
   *
   * @var int
   */
  protected $weight = 0;

  /**
   * Edit mode flag.
   *
   * @var bool
   */
  protected $isEditMode = FALSE;

  /**
   * The toolbar items.
   *
   * @var \Drupal\neo_toolbar\ToolbarItemInterface[]
   */
  protected $items;

  /**
   * The cacheable metadata for the items.
   *
   * @var \Drupal\Core\Cache\CacheableMetadata
   */
  protected $itemsCacheableMetadata;

  /**
   * The plugin collection that holds the regions.
   *
   * @var \Drupal\Core\Plugin\DefaultLazyPluginCollection
   */
  protected $regionCollection;

  /**
   * {@inheritdoc}
   */
  public function setEditMode(bool $isEditMode = TRUE):self {
    $this->isEditMode = !empty($isEditMode);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isEditMode():bool {
    return $this->isEditMode === TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getItems($regionId = NULL, ?CacheableMetadata $cacheableMetadata = NULL): array {
    if (!isset($this->items)) {
      $this->itemsCacheableMetadata = new CacheableMetadata();
      $items = [];
      $ids = $this->entityTypeManager()->getStorage('neo_toolbar_item')->getQuery()
        ->accessCheck(TRUE)
        ->condition('toolbar', $this->id)
        ->condition('status', TRUE)
        ->sort('weight')
        ->execute();
      if ($ids) {
        /** @var \Drupal\neo_toolbar\ToolbarItemInterface[] $items */
        $items = $this->entityTypeManager()->getStorage('neo_toolbar_item')->loadMultiple($ids);

        if (!$this->isEditMode()) {
          // Temporarily group items by region to check sibling access. This
          // grouping has all items prior to access checks.
          $allItemsByRegion = [];
          foreach ($items as $key => $item) {
            $allItemsByRegion[$item->getRegionId()][$key] = $item;
          }

          // Check access for each item.
          $items = array_filter($items, function ($item) {
            $access = $item->access('view', NULL, TRUE);
            $this->itemsCacheableMetadata->addCacheableDependency($item);
            $this->itemsCacheableMetadata->addCacheableDependency($access);
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

      $this->items = $items;
    }

    $items = $this->items;
    if ($regionId) {
      $items = array_filter($items, fn($item) => $item->getRegionId() === $regionId);
    }
    if ($cacheableMetadata) {
      $cacheableMetadata->addCacheableDependency($this->itemsCacheableMetadata);
    }
    return $items;
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginCollections() {
    return [
      'visibility' => $this->getVisibilityConditions(),
      'regions' => $this->getRegionCollection(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getRegionIds(): array {
    $regions = [];
    foreach ($this->getRegionCollection() as $region) {
      $regions[] = $region->getPluginId();
    }
    return $regions;
  }

  /**
   * {@inheritdoc}
   */
  public function getRegions(): array {
    $regions = [];
    foreach ($this->getRegionCollection() as $region) {
      $regions[$region->getPluginId()] = $region;
    }
    return $regions;
  }

  /**
   * {@inheritdoc}
   */
  public function getRegionCollection() {
    if (!$this->regionCollection) {
      /** @var \Drupal\neo_toolbar\ToolbarRegionPluginManager $regionManager */
      $regionManager = \Drupal::service('plugin.manager.neo_toolbar_region');
      $configurations = [];
      if ($id = $this->id()) {
        foreach ($regionManager->getDefinitionForToolbar($id) as $pluginId => $definition) {
          $configurations[$pluginId] = [
            'id' => $pluginId,
          ];
        }
      }
      $this->regionCollection = new DefaultLazyPluginCollection($regionManager, $configurations);
    }
    return $this->regionCollection;
  }

  /**
   * Sorts active toolbars by weight; sorts inactive toolbars by name.
   */
  public static function sort(ConfigEntityInterface $a, ConfigEntityInterface $b) {
    // Separate enabled from disabled.
    $status = (int) $b->status() - (int) $a->status();
    if ($status !== 0) {
      return $status;
    }

    // Sort by weight.
    $weight = $a->get('weight') - $b->get('weight');
    if ($weight) {
      return $weight;
    }

    // Sort by label.
    return strcmp($a->label(), $b->label());
  }

}
