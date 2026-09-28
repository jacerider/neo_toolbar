<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar;

/**
 * Puts an entity's View and Edit tabs first and its Delete tab last.
 *
 * An entity's tabs are declared by whichever modules care about it, and most
 * take the default weight of 0, so where Edit lands among them is decided by
 * module discovery order: on a user it follows the address book and payment
 * methods, and on a node Delete sits ahead of Revisions and Devel. This rule
 * moves the three tabs every entity shares to where an editor looks for them.
 * Every other tab keeps its weight, and so its order among the rest.
 *
 * The three are recognised by the route names core's entity route providers
 * give an entity's link templates, `entity.{type}.canonical`,
 * `entity.{type}.edit_form` and `entity.{type}.delete_form`, where the type is
 * the one the tab set's base route belongs to. No entity type is named, and a
 * tab linking to some other entity's edit form is left among the rest.
 *
 * Edit is only pulled forward behind a View tab. A tab set without one, such
 * as a vocabulary's or a bundle's, starts where its own module put it.
 *
 * Only the tabs that move are written to, and only when their weight changes.
 */
final class EntityTaskOrder {

  /**
   * The entity link templates that move, by route name suffix.
   */
  private const TABS = [
    'view' => 'canonical',
    'edit' => 'edit_form',
    'delete' => 'delete_form',
  ];

  /**
   * Orders the entity tabs in a set of local task definitions.
   *
   * @param array $definitions
   *   The local task definitions, as hook_local_tasks_alter() receives them.
   */
  public static function apply(array &$definitions): void {
    $sets = [];
    foreach ($definitions as $id => $definition) {
      $baseRoute = $definition['base_route'] ?? '';
      if (preg_match('/^entity\.([^.]+)\./', $baseRoute, $match)) {
        // Primary tabs share a base route; secondary tabs share a parent too.
        $key = $baseRoute . '|' . ($definition['parent_id'] ?? '');
        $sets[$key]['type'] = $match[1];
        $sets[$key]['ids'][] = $id;
      }
    }
    foreach ($sets as $set) {
      self::orderSet($definitions, $set['type'], $set['ids']);
    }
  }

  /**
   * Orders one tab set, the tabs rendered side by side.
   *
   * @param array $definitions
   *   The local task definitions.
   * @param string $type
   *   The entity type id the set's base route belongs to.
   * @param string[] $ids
   *   The plugin ids of the tabs in the set.
   */
  private static function orderSet(
    array &$definitions,
    string $type,
    array $ids,
  ): void {
    $weights = [];
    $tabs = [];
    foreach ($ids as $id) {
      $weights[$id] = self::weight($definitions[$id]);
      $route = $definitions[$id]['route_name'] ?? '';
      foreach (self::TABS as $tab => $template) {
        if ($route === "entity.$type.$template") {
          $tabs[$tab] = $id;
        }
      }
    }
    if (!$tabs) {
      return;
    }

    $view = $tabs['view'] ?? NULL;
    $edit = $view === NULL ? NULL : ($tabs['edit'] ?? NULL);
    $delete = $tabs['delete'] ?? NULL;
    $moving = array_filter([$view, $edit, $delete], 'is_string');
    $rest = array_diff_key($weights, array_flip($moving));
    $floor = $rest ? min($rest) : NULL;

    if ($edit !== NULL && $floor !== NULL) {
      $weights[$edit] = min($weights[$edit], $floor - 1);
    }
    $below = $edit !== NULL ? $weights[$edit] : $floor;
    if ($view !== NULL && $below !== NULL) {
      $weights[$view] = min($weights[$view], $below - 1);
    }
    if ($delete !== NULL) {
      $others = array_diff_key($weights, [$delete => TRUE]);
      if ($others) {
        $weights[$delete] = max($weights[$delete], max($others) + 1);
      }
    }

    foreach ($moving as $id) {
      if ($weights[$id] !== self::weight($definitions[$id])) {
        $definitions[$id]['weight'] = $weights[$id];
      }
    }
  }

  /**
   * The weight a tab sorts by.
   *
   * @param array $definition
   *   A local task definition.
   *
   * @return int
   *   The explicit weight as core casts it, or core's default: -10 for the tab
   *   that is its own base route and 0 for every other.
   *
   * @see \Drupal\Core\Menu\LocalTaskDefault::getWeight()
   */
  private static function weight(array $definition): int {
    if (isset($definition['weight'])) {
      return (int) $definition['weight'];
    }
    $baseRoute = $definition['base_route'] ?? '';
    return $baseRoute === ($definition['route_name'] ?? '') ? -10 : 0;
  }

}
