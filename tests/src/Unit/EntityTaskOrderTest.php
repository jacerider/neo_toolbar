<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_toolbar\Unit;

use Drupal\Core\Menu\LocalTaskDefault;
use Drupal\neo_toolbar\EntityTaskOrder;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Characterises the entity tab order the local tasks alter applies.
 *
 * The rule moves three tabs, View first, Edit straight after it and Delete
 * last, and leaves every other tab's weight alone. What an editor sees is not
 * the weights the rule writes but the order core renders from them, so every
 * order below is asserted through `renderedOrder()`, which reads each weight
 * the way core's local task plugin does, default and integer cast included,
 * and sorts on it stably the way the toolbar does.
 *
 * The fixtures are the two tab sets that prompted the rule, trimmed to their
 * weights: a user page, where every tab but View has the default weight of 0
 * and Edit landed fourth by module discovery order, and a node page, where
 * Delete's weight of 10 put it ahead of Revisions and Devel.
 */
#[Group('neo_toolbar')]
final class EntityTaskOrderTest extends UnitTestCase {

  /**
   * Edit is pulled ahead of the tabs that share its default weight.
   *
   * Covers: it renders View then Edit ahead of every other tab, which keep
   * their own order, including the ones declared ahead of Edit.
   */
  public function testPutsEditStraightAfterView(): void {
    $definitions = [
      'address_book' => $this->tab('user.address_book', 'entity.user.canonical', 0),
      'payment_methods' => $this->tab('user.payment_methods', 'entity.user.canonical', 0),
      'devel' => $this->tab('entity.user.devel_load', 'entity.user.canonical', 100),
      'roles' => $this->tab('role_delegation.edit_form', 'entity.user.canonical', 0),
      'view' => $this->tab('entity.user.canonical', 'entity.user.canonical'),
      'edit' => $this->tab('entity.user.edit_form', 'entity.user.canonical', 0),
      'orders' => $this->tab('view.user_orders.page', 'entity.user.canonical', 0),
    ];

    EntityTaskOrder::apply($definitions);

    $this->assertSame(
      ['view', 'edit', 'address_book', 'payment_methods', 'roles', 'orders', 'devel'],
      $this->renderedOrder($definitions, 'entity.user.canonical'),
    );
    // View already sat below everything, so it is not written to.
    $this->assertArrayNotHasKey('weight', $definitions['view']);
  }

  /**
   * Delete is pushed past the heaviest tab in its set.
   *
   * Covers: it renders Delete last, behind tabs weighted above its own.
   */
  public function testPutsDeleteLast(): void {
    $definitions = [
      'devel' => $this->tab('entity.node.devel_load', 'entity.node.canonical', 100),
      'alchemist' => $this->tab('entity.node.alchemist', 'entity.node.canonical', 0),
      'view' => $this->tab('entity.node.canonical', 'entity.node.canonical'),
      'edit' => $this->tab('entity.node.edit_form', 'entity.node.canonical'),
      'delete' => $this->tab('entity.node.delete_form', 'entity.node.canonical', 10),
      'revisions' => $this->tab('entity.node.version_history', 'entity.node.canonical', 20),
    ];

    EntityTaskOrder::apply($definitions);

    $this->assertSame(
      ['view', 'edit', 'alchemist', 'revisions', 'devel', 'delete'],
      $this->renderedOrder($definitions, 'entity.node.canonical'),
    );
  }

  /**
   * A tab set without a View tab keeps Edit where its module put it.
   *
   * Covers: it leaves Edit among the rest when there is no View tab to follow,
   * and still renders Delete last.
   */
  public function testLeavesEditInPlaceWithoutViewTab(): void {
    $base = 'entity.taxonomy_vocabulary.overview_form';
    $definitions = [
      'list' => $this->tab($base, $base),
      'fields' => $this->tab('entity.taxonomy_term.field_ui_fields', $base, 1),
      'delete' => $this->tab('entity.taxonomy_vocabulary.delete_form', $base, 0),
      'edit' => $this->tab('entity.taxonomy_vocabulary.edit_form', $base, 10),
    ];

    EntityTaskOrder::apply($definitions);

    $this->assertSame(
      ['list', 'fields', 'edit', 'delete'],
      $this->renderedOrder($definitions, $base),
    );
    $this->assertSame(10, $definitions['edit']['weight']);
  }

  /**
   * Only the entity the tab set belongs to has its tabs moved.
   *
   * Covers: it leaves a tab linking to another entity type's edit or delete
   * form among the rest.
   */
  public function testLeavesOtherEntityTypesTabsAmongTheRest(): void {
    $definitions = [
      'view' => $this->tab('entity.user.canonical', 'entity.user.canonical'),
      'profile_edit' => $this->tab('entity.profile.edit_form', 'entity.user.canonical', 0),
      'edit' => $this->tab('entity.user.edit_form', 'entity.user.canonical', 5),
      'profile_delete' => $this->tab('entity.profile.delete_form', 'entity.user.canonical', 50),
      'orders' => $this->tab('view.user_orders.page', 'entity.user.canonical', 60),
    ];

    EntityTaskOrder::apply($definitions);

    $this->assertSame(
      ['view', 'edit', 'profile_edit', 'profile_delete', 'orders'],
      $this->renderedOrder($definitions, 'entity.user.canonical'),
    );
    $this->assertSame(0, $definitions['profile_edit']['weight']);
    $this->assertSame(50, $definitions['profile_delete']['weight']);
  }

  /**
   * Tab sets already in order, and sets that are not an entity's, stay put.
   *
   * Covers: it writes nothing when View, Edit and Delete already render where
   * the rule puts them, nothing to a tab set whose base route is not an
   * entity route, and nothing to a definition without a route name.
   */
  public function testLeavesOrderedAndForeignTabSetsUntouched(): void {
    $definitions = [
      'view' => $this->tab('entity.node.canonical', 'entity.node.canonical'),
      'edit' => $this->tab('entity.node.edit_form', 'entity.node.canonical', -5),
      'revisions' => $this->tab('entity.node.version_history', 'entity.node.canonical', 20),
      'delete' => $this->tab('entity.node.delete_form', 'entity.node.canonical', 30),
      'content' => $this->tab('system.admin_content', 'system.admin_content'),
      'files' => $this->tab('view.files.page_1', 'system.admin_content', 0),
      'bare' => ['base_route' => 'entity.node.canonical'],
    ];
    $original = $definitions;

    EntityTaskOrder::apply($definitions);

    $this->assertSame($original, $definitions);
  }

  /**
   * Builds a local task definition with the keys the rule reads.
   *
   * @param string $route
   *   The route the tab links to.
   * @param string $baseRoute
   *   The base route of the tab set it belongs to.
   * @param int|null $weight
   *   The explicit weight, or NULL for core's default.
   *
   * @return array
   *   The definition.
   */
  private function tab(string $route, string $baseRoute, ?int $weight = NULL): array {
    $definition = ['route_name' => $route, 'base_route' => $baseRoute];
    if ($weight !== NULL) {
      $definition['weight'] = $weight;
    }
    return $definition;
  }

  /**
   * The plugin ids of one tab set, in the order core renders them.
   *
   * Each weight is read by `LocalTaskDefault::getWeight()`, as core reads it
   * off the plugin instance, and ties keep definition order, as the toolbar's
   * stable `uasort()` keeps it.
   *
   * @param array $definitions
   *   The altered local task definitions.
   * @param string $baseRoute
   *   The base route of the tab set to order.
   *
   * @return string[]
   *   The plugin ids, first tab first.
   */
  private function renderedOrder(array $definitions, string $baseRoute): array {
    $weights = [];
    foreach ($definitions as $id => $definition) {
      if ($definition['base_route'] === $baseRoute) {
        $task = new LocalTaskDefault([], $id, $definition);
        $weights[$id] = $task->getWeight();
      }
    }
    asort($weights);
    return array_keys($weights);
  }

}
