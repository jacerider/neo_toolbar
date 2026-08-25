<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_toolbar\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_toolbar\Entity\Toolbar;
use Drupal\neo_toolbar\Entity\ToolbarItem;
use Drupal\neo_toolbar\ToolbarInterface;
use Drupal\neo_toolbar\ToolbarItemInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Characterises the second half of the item pipeline.
 *
 * `Toolbar::getItems()` applies three more rules once the access filter of
 * \Drupal\Tests\neo_toolbar\Kernel\ItemPipelineAccessFilterTest has run:
 *
 * 1. A derived region whose items all lost access is collapsed, and the
 *    triggering item that opens it is dropped with it — otherwise a dropdown
 *    opens onto nothing.
 * 2. An item whose sibling access is forbidden is dropped. `Divider` is the
 *    module's only non-trivial implementation of that rule, so the pass is
 *    driven by real dividers rather than by a fixture that forbids on command.
 * 3. A triggering item that lost its own access is restored when its derived
 *    region still has children, because the children have no other way in.
 *
 * One behaviour is pinned as current rather than defended: the memo is computed
 * once and ignores edit mode, so `setEditMode()` after a first `getItems()`
 * answers the previous mode's items. Nothing hits it today because the render
 * path sets the mode on a freshly-loaded entity — an invariant held by call
 * order, not by the code.
 *
 * Derived regions are `item:<item id>`, produced by
 * \Drupal\neo_toolbar\Plugin\Derivative\ToolbarRegion for every item whose
 * plugin declares `region_create`. The fixture module's second item plugin is
 * exactly that, so a test owns both the region and the access answer of every
 * item in it.
 */
#[Group('neo_toolbar')]
final class ItemPipelineRegionRulesTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The floor the item pipeline needs, and no more: `KernelTestBase` installs
   * exactly what is named here, so `token`, `neo`, `neo_modal`, `neo_tooltip`
   * and `neo_image` stay out even though the info file names them. `Divider`
   * autoloads out of `neo_toolbar` itself and needs nothing extra.
   */
  protected static $modules = [
    'system',
    'user',
    'neo_toolbar',
    'neo_toolbar_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    Toolbar::create([
      'id' => 'test_toolbar',
      'label' => 'Test toolbar',
      'weight' => 0,
    ])->save();
  }

  /**
   * A derived region emptied by the access filter takes its opener with it.
   *
   * Covers: it collapses a derived region whose items all lost access, and
   * drops its triggering item.
   */
  public function testCollapsesAnEmptyDerivedRegionAndDropsItsTriggeringItem(): void {
    // The triggering item is allowed in its own right, and shares a region
    // with an ordinary item that must survive untouched.
    $this->createRegionItem('trigger', ['weight' => 10]);
    $this->createItem('bystander', ['weight' => 20]);
    // The only child of the derived region loses access, so the region the
    // triggering item opens is empty.
    $this->createItem('child', ['weight' => 30, 'region' => 'item:trigger'], 'forbidden');

    $toolbar = $this->loadToolbar();
    // The child is gone by the access filter, and the collapse rule takes the
    // triggering item with it rather than leave a dropdown onto nothing.
    $this->assertSame(['bystander'], array_keys($toolbar->getItems()));
    $this->assertSame([], $toolbar->getItems('item:trigger'));
    $this->assertSame(['bystander'], array_keys($toolbar->getItems('test_horizontal')));
  }

  /**
   * A divider with no item before it is dropped.
   *
   * Covers: it drops a divider whose sibling access is forbidden for want of a
   * previous item.
   */
  public function testDropsDividerForWantOfPreviousItem(): void {
    $this->createDivider('leading', ['weight' => 10]);
    $this->createItem('alpha', ['weight' => 20]);
    $this->createItem('beta', ['weight' => 30]);
    // `Divider::accessBySiblings()` forbids on a missing `$next` as well, so a
    // trailing divider goes for the mirror-image reason.
    $this->createDivider('trailing', ['weight' => 40]);

    $this->assertSame(['alpha', 'beta'], array_keys($this->loadToolbar()->getItems()));
  }

  /**
   * Two adjacent dividers both lose sibling access.
   *
   * Covers: it drops a divider that sits beside another divider.
   */
  public function testDropsDividerBesideAnotherDivider(): void {
    $this->createItem('alpha', ['weight' => 10]);
    $this->createDivider('first', ['weight' => 20]);
    $this->createDivider('second', ['weight' => 30]);
    $this->createItem('beta', ['weight' => 40]);

    // Both go: the first has a divider after it and the second has one before
    // it, and the pass reads the region as it was before any removal, so
    // neither is spared by the other's departure.
    $this->assertSame(['alpha', 'beta'], array_keys($this->loadToolbar()->getItems()));
  }

  /**
   * A divider between two ordinary items survives.
   *
   * Covers: it keeps a divider that sits between two accessible non-dividers.
   */
  public function testKeepsDividerBetweenTwoAccessibleNonDividers(): void {
    $this->createItem('alpha', ['weight' => 10]);
    $this->createDivider('middle', ['weight' => 20]);
    $this->createItem('beta', ['weight' => 30]);

    $this->assertSame(['alpha', 'middle', 'beta'], array_keys($this->loadToolbar()->getItems()));
  }

  /**
   * A triggering item whose region still has children comes back.
   *
   * Covers: it restores a triggering item that lost access while its derived
   * region still has children.
   */
  public function testRestoresTriggeringItemWhoseDerivedRegionStillHasChildren(): void {
    // Both triggering items lose their own access; both still have a child.
    // They differ only in their id, and that is the whole point below.
    $this->createRegionItem('region_menu', ['weight' => 10], 'forbidden');
    $this->createRegionItem('opener', ['weight' => 20], 'forbidden');
    $this->createItem('menu_child', ['weight' => 30, 'region' => 'item:region_menu']);
    $this->createItem('opener_child', ['weight' => 40, 'region' => 'item:opener']);

    $items = $this->loadToolbar()->getItems();

    // Both are restored: their children have no other way in. The restore
    // appends, so each lands after the children rather than at its own weight,
    // in the order their regions were met.
    $this->assertSame(['menu_child', 'opener_child', 'region_menu', 'opener'], array_keys($items));

    // `opener` is the assertion this plan's ticket 04 inverted, and its id is
    // the whole reason it is here. The rule used to test the region id with
    // `strpos($rid, 'item:region') === 0`, which fired only for a triggering
    // item whose own id began with `region` — a property of the item, not of
    // the region it derived. Items created through the UI happened to qualify,
    // because the `region` plugin's machine name suggestion is `region`,
    // `region_2` and so on, but nothing enforced it and the module's other
    // region-creating plugin is `user`, whose item is called `user`. Both
    // rules now test the `item:` prefix a derived region actually carries, so
    // an opener is restored for what its region is rather than for what it is
    // called.
    $this->assertArrayHasKey('opener', $items);
  }

  /**
   * The memo is computed once and never reconsiders the mode.
   *
   * Covers: it answers the previous mode's items when edit mode is set after a
   * first call.
   */
  public function testAnswersPreviousModeItemsWhenEditModeSetAfterFirstCall(): void {
    $this->createItem('visible', ['weight' => 10]);
    $this->createItem('hidden', ['weight' => 20], 'forbidden');

    // A first call in the default mode filters by access and memoises.
    $filtered = $this->loadToolbar();
    $this->assertSame(['visible'], array_keys($filtered->getItems()));
    // Edit mode now arrives too late. The flag is set on the object and
    // `isEditMode()` agrees, but `getItems()` short-circuits on the memo and
    // answers the filtered list the previous mode produced.
    $filtered->setEditMode();
    $this->assertTrue($filtered->isEditMode());
    $this->assertSame(['visible'], array_keys($filtered->getItems()));

    // The same trap in the other direction: a toolbar memoised in edit mode
    // keeps answering unfiltered after the mode is turned off.
    $editing = $this->loadToolbar()->setEditMode();
    $this->assertSame(['visible', 'hidden'], array_keys($editing->getItems()));
    $editing->setEditMode(FALSE);
    $this->assertFalse($editing->isEditMode());
    $this->assertSame(['visible', 'hidden'], array_keys($editing->getItems()));

    // Nothing hits this today because the render path sets the mode on a
    // freshly-loaded entity, which memoises against the mode it was given.
    $this->assertSame(['visible', 'hidden'], array_keys($this->loadToolbar()->setEditMode()->getItems()));
  }

  /**
   * Creates a toolbar item on the test toolbar.
   *
   * @param string $id
   *   The item id, which is also the array key `getItems()` answers under.
   * @param array $values
   *   Entity values overriding the defaults.
   * @param string $access
   *   The answer the fixture plugin gives to a view access check: `allowed`,
   *   `forbidden` or `neutral`.
   *
   * @return \Drupal\neo_toolbar\ToolbarItemInterface
   *   The saved item.
   */
  protected function createItem(string $id, array $values = [], string $access = 'allowed'): ToolbarItemInterface {
    $item = ToolbarItem::create($values + [
      'id' => $id,
      'label' => $id,
      'toolbar' => 'test_toolbar',
      'region' => 'test_horizontal',
      'plugin' => 'neo_toolbar_test_access',
      'weight' => 0,
      'settings' => ['access' => $access],
    ]);
    $item->save();
    return $item;
  }

  /**
   * Creates a toolbar item that derives a region of its own.
   *
   * The item becomes the triggering item of an `item:<id>` region, which is
   * what the collapse and restore rules key off.
   *
   * @param string $id
   *   The item id.
   * @param array $values
   *   Entity values overriding the defaults.
   * @param string $access
   *   The answer the fixture plugin gives to a view access check.
   *
   * @return \Drupal\neo_toolbar\ToolbarItemInterface
   *   The saved item.
   */
  protected function createRegionItem(string $id, array $values = [], string $access = 'allowed'): ToolbarItemInterface {
    return $this->createItem($id, $values + ['plugin' => 'neo_toolbar_test_region'], $access);
  }

  /**
   * Creates a real divider on the test toolbar.
   *
   * `Divider` is the module's only non-trivial `accessBySiblings()`, so the
   * sibling pass is driven by the real thing. It carries no settings of its
   * own, which is why this does not go through the fixture plugin's `access`
   * key — that key is not in the divider's config schema.
   *
   * @param string $id
   *   The item id.
   * @param array $values
   *   Entity values overriding the defaults.
   *
   * @return \Drupal\neo_toolbar\ToolbarItemInterface
   *   The saved item.
   */
  protected function createDivider(string $id, array $values = []): ToolbarItemInterface {
    return $this->createItem($id, $values + [
      'plugin' => 'divider',
      'settings' => [],
    ]);
  }

  /**
   * Loads the test toolbar, bypassing any memo an earlier call established.
   *
   * @return \Drupal\neo_toolbar\ToolbarInterface
   *   The toolbar.
   */
  protected function loadToolbar(): ToolbarInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_toolbar');
    $storage->resetCache(['test_toolbar']);
    $toolbar = $storage->load('test_toolbar');
    $this->assertInstanceOf(ToolbarInterface::class, $toolbar);
    return $toolbar;
  }

}
