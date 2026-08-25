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
   * enabled items in weight order and then runs the four pipeline rules below
   * in the one order that is correct: view access, region collapse, sibling
   * access, triggering-item restore.
   *
   * This is the composition callers want. The rules are public so that each is
   * answerable on a handful of items with no toolbar behind them — see
   * `docs/adr/0005` — but they are four answers to four questions, not a kit:
   * run in another order they give an answer no toolbar would.
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
        // What the toolbar held before any rule ran. Two of the rules need it:
        // "this region emptied out" and "this dropdown still has children" are
        // both statements about the difference the access filter made.
        $allItems = $items;

        $items = $this->filterItemsByViewAccess($items, $cacheableMetadata);
        $items = $this->collapseEmptyRegions($items, $allItems);
        $items = $this->filterItemsBySiblingAccess($items);
        $items = $this->restoreTriggeringItems($items, $allItems);
      }
    }
    return $items;
  }

  /**
   * Drops the items view access forbids, recording every one it examined.
   *
   * The first pipeline rule. Cacheability is the half a caller cannot recover
   * from the answer: a dropped item is not in the array it gets back, so only
   * the metadata can say the answer depends on that item and on the access
   * result that dropped it.
   *
   * @param \Drupal\neo_toolbar\ToolbarItemInterface[] $items
   *   The items to filter, keyed by item id.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheableMetadata
   *   Cacheable metadata to fill with every item examined and every access
   *   result consulted, dropped items included.
   *
   * @return \Drupal\neo_toolbar\ToolbarItemInterface[]
   *   The items view access allows, in the order they were given.
   */
  public function filterItemsByViewAccess(array $items, ?CacheableMetadata $cacheableMetadata = NULL): array {
    $cacheableMetadata = $cacheableMetadata ?? new CacheableMetadata();
    return array_filter($items, function (ToolbarItemInterface $item) use ($cacheableMetadata) {
      $access = $item->access('view', NULL, TRUE);
      $cacheableMetadata->addCacheableDependency($item);
      $cacheableMetadata->addCacheableDependency($access);
      return $access->isAllowed();
    });
  }

  /**
   * Drops the triggering item of a derived region that has emptied out.
   *
   * The second pipeline rule. A derived region is opened by an item of its
   * own, and an opener that opens onto nothing is worse than no opener at all,
   * so when every item a region held has gone the item that opens it goes too.
   *
   * "Has gone" is only meaningful against what the toolbar held before, which
   * is why the pre-access set is a parameter rather than something this reads
   * off object state: passing it is what lets the rule be called without a
   * toolbar having emptied anything.
   *
   * Only a derived region has an opener, which is why every region id is put
   * to \Drupal\neo_toolbar\ToolbarRepository::triggeringItemId() rather than
   * having its prefix stripped unconditionally. A real region that emptied
   * says nothing whatever about a toolbar item that happens to share its
   * machine name.
   *
   * @param \Drupal\neo_toolbar\ToolbarItemInterface[] $items
   *   The items that survived the access filter, keyed by item id.
   * @param \Drupal\neo_toolbar\ToolbarItemInterface[] $allItems
   *   The items the toolbar held before the access filter ran, keyed by id.
   *
   * @return \Drupal\neo_toolbar\ToolbarItemInterface[]
   *   The items, less the openers of every region that emptied out.
   */
  public function collapseEmptyRegions(array $items, array $allItems): array {
    $itemsByRegion = $this->groupItemsByRegion($items);
    foreach (array_keys($this->groupItemsByRegion($allItems)) as $rid) {
      if (!isset($itemsByRegion[$rid])) {
        // An empty region, which may be one an item toggles.
        $triggeringItemId = $this->triggeringItemId($rid);
        if ($triggeringItemId !== NULL && isset($items[$triggeringItemId])) {
          unset($items[$triggeringItemId]);
        }
      }
    }
    return $items;
  }

  /**
   * Drops the items their immediate neighbours forbid.
   *
   * The third pipeline rule. Every item is asked about the item before it and
   * the item after it within its own region; `Divider` is the only plugin that
   * answers anything but "allowed", and it forbids itself at either end of a
   * region and beside another divider.
   *
   * The neighbours are read from the region as it was handed in, so two
   * adjacent items that forbid each other both go — neither is spared by the
   * other's departure.
   *
   * @param \Drupal\neo_toolbar\ToolbarItemInterface[] $items
   *   The items to filter, keyed by item id.
   *
   * @return \Drupal\neo_toolbar\ToolbarItemInterface[]
   *   The items their siblings allow, in the order they were given.
   */
  public function filterItemsBySiblingAccess(array $items): array {
    foreach ($this->groupItemsByRegion($items) as $regionItems) {
      $keys = array_keys($regionItems);
      $forbidden = array_filter($regionItems, function (ToolbarItemInterface $item, string|int $key) use ($regionItems, $keys) {
        $index = array_search($key, $keys);
        if ($index === FALSE) {
          return FALSE;
        }
        $previous = $index > 0 ? $keys[$index - 1] : NULL;
        $next = $index < count($keys) - 1 ? $keys[$index + 1] : NULL;
        $siblingAccess = $item->accessBySiblings($regionItems[$previous] ?? NULL, $regionItems[$next] ?? NULL);
        return $siblingAccess->isForbidden();
      }, ARRAY_FILTER_USE_BOTH);
      $items = array_diff_key($items, $forbidden);
    }
    return $items;
  }

  /**
   * Brings back the opener of a derived region that still has children.
   *
   * The fourth pipeline rule, and the mirror of the second: where the collapse
   * drops an opener whose region emptied, this restores one whose region did
   * not. A child of a derived region has no other way in, so an opener that
   * lost its own access is appended rather than leave the children stranded.
   *
   * It fires for every derived region, which is every region id carrying the
   * `item:` prefix. It used to test `item:region` — five characters longer —
   * and so fired only when the opener's own machine name began with `region`:
   * true of items built on the `region` plugin, false of items built on
   * `user`, and those two are the only plugins in the module that create
   * regions. A user dropdown's links were never protected by this rule on any
   * site until ticket 04 of this plan made both rules read the prefix the same
   * way.
   *
   * "Still has children" is read from the items handed in, so a region whose
   * last child the sibling rule dropped has no children here. Inside the
   * composition that is the rule running after
   * \Drupal\neo_toolbar\ToolbarRepository::filterItemsBySiblingAccess(), which
   * is the order it has always run in.
   *
   * @param \Drupal\neo_toolbar\ToolbarItemInterface[] $items
   *   The items that survived the rules before this one, keyed by item id.
   * @param \Drupal\neo_toolbar\ToolbarItemInterface[] $allItems
   *   The items the toolbar held before the access filter ran, keyed by id.
   *
   * @return \Drupal\neo_toolbar\ToolbarItemInterface[]
   *   The items, plus every opener whose derived region still has children.
   */
  public function restoreTriggeringItems(array $items, array $allItems): array {
    foreach (array_keys($this->groupItemsByRegion($items)) as $rid) {
      // A region in this list has items. If an item derived it, that item is
      // the one thing standing between those items and nobody reaching them.
      $triggeringItemId = $this->triggeringItemId($rid);
      if ($triggeringItemId !== NULL && !isset($items[$triggeringItemId]) && isset($allItems[$triggeringItemId])) {
        $items[$triggeringItemId] = $allItems[$triggeringItemId];
      }
    }
    return $items;
  }

  /**
   * Names the item that opens a region, if any item does.
   *
   * The other thing the region rules share. A derived region's id is `item:`
   * followed by the machine name of the item that opens it — see
   * \Drupal\neo_toolbar\Plugin\Derivative\ToolbarRegion, which derives one
   * such region per item whose plugin declares `region_create` — so this is
   * one question with one answer, and the two rules that ask it ask it here.
   *
   * They used to ask it separately and get different answers, in both
   * directions. The collapse stripped `item:` from every region id whether or
   * not the id carried one, so the real region `top_start` named a toolbar
   * item `top_start` and took it down with it. The restore matched
   * `item:region`, which is a statement about the *item*'s machine name rather
   * than the region's, so it never fired for the `user` item — one of exactly
   * two plugins that create regions, and the one this module's own install
   * config ships.
   *
   * @param string $regionId
   *   The region id.
   *
   * @return string|null
   *   The machine name of the item that opens the region, or NULL when the
   *   region is a real one that no item derived.
   */
  private function triggeringItemId(string $regionId): ?string {
    if (!str_starts_with($regionId, 'item:')) {
      return NULL;
    }
    return substr($regionId, 5);
  }

  /**
   * Groups items by the region they sit in.
   *
   * The one thing the four rules share, and the only part of the pipeline that
   * is not answerable on its own: it is a shape, not a question, so it stays
   * private where `docs/adr/0005` put it.
   *
   * @param \Drupal\neo_toolbar\ToolbarItemInterface[] $items
   *   The items to group, keyed by item id.
   *
   * @return array<string, \Drupal\neo_toolbar\ToolbarItemInterface[]>
   *   The items keyed by region id and then by item id, regions in the order
   *   their first item appeared.
   */
  private function groupItemsByRegion(array $items): array {
    $itemsByRegion = [];
    foreach ($items as $key => $item) {
      $itemsByRegion[$item->getRegionId()][$key] = $item;
    }
    return $itemsByRegion;
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
