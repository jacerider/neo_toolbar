<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_toolbar\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_toolbar\Entity\Toolbar;
use Drupal\neo_toolbar\Entity\ToolbarItem;
use Drupal\neo_toolbar\ToolbarInterface;
use Drupal\neo_toolbar\ToolbarItemInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Characterises the first half of the item pipeline.
 *
 * `Toolbar::getItems()` is 114 lines applying four rules. This class pins what
 * it does *before* the region rules come into play: the weight-ordered load of
 * a toolbar's enabled items, the view-access filter and the cacheability it
 * collects while filtering, the single-region filter, the per-object memo, and
 * the wholesale skip in edit mode. The derived-region collapse, the sibling
 * access pass and the triggering-item restore are ticket 02's.
 *
 * The toolbar and its items are built here rather than installed from
 * `neo_toolbar`'s `config/install`, because one shipped default carries a
 * plugin from `neo_favicon` — a package this module does not depend on.
 *
 * Access answers come from `neo_toolbar_test`'s fixture item plugin, which
 * reads them out of each item's own settings, so every permutation is chosen
 * by the test rather than inferred from what a real `link` or `user` plugin
 * happens to answer today.
 */
#[Group('neo_toolbar')]
final class ItemPipelineAccessFilterTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * `KernelTestBase` installs exactly what is named here and does not resolve
   * the info file's dependency closure, so `token`, `neo`, `neo_modal`,
   * `neo_tooltip` and `neo_image` stay out: their classes autoload, and only a
   * service lookup would force one in. Nothing on this path performs one.
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
   * The fixture toolbar item plugins are discoverable.
   *
   * Covers: the fixture toolbar item plugins are discoverable, and one of them
   * declares region_create.
   */
  public function testFixturePluginsAreDiscoverable(): void {
    $definitions = $this->container->get('plugin.manager.neo_toolbar_item')->getDefinitions();

    $this->assertArrayHasKey('neo_toolbar_test_access', $definitions);
    $this->assertArrayHasKey('neo_toolbar_test_region', $definitions);

    // Only the second declares region_create, which is what gives ticket 02 a
    // derived region and a triggering item to work with.
    $this->assertFalse($definitions['neo_toolbar_test_access']['region_create']);
    $this->assertTrue($definitions['neo_toolbar_test_region']['region_create']);

    // The fixtures own two regions, one per alignment, so the region filter and
    // the collapse rule are asserted against regions the tests control rather
    // than the five the module ships.
    $regions = $this->container->get('plugin.manager.neo_toolbar_region')->getDefinitions();
    $this->assertArrayHasKey('test_horizontal', $regions);
    $this->assertSame('horizontal', $regions['test_horizontal']['alignment']);
    $this->assertArrayHasKey('test_vertical', $regions);
    $this->assertSame('vertical', $regions['test_vertical']['alignment']);
  }

  /**
   * Enabled items come back in weight order.
   *
   * Covers: it returns a toolbar's enabled items in weight order.
   */
  public function testReturnsEnabledItemsInWeightOrder(): void {
    // Created out of order, and out of alphabetical order, so neither creation
    // order nor id ordering can produce a passing result by accident.
    $this->createItem('gamma', ['weight' => 30]);
    $this->createItem('alpha', ['weight' => 10]);
    $this->createItem('beta', ['weight' => 20]);
    // Disabled items never reach the access filter; the query drops them.
    $this->createItem('zeta', ['weight' => 5, 'status' => FALSE]);

    $this->assertSame(['alpha', 'beta', 'gamma'], array_keys($this->loadToolbar()->getItems()));
  }

  /**
   * An item whose plugin forbids view access is dropped.
   *
   * Covers: it drops an item whose plugin forbids view access.
   */
  public function testDropsItemWhosePluginForbidsViewAccess(): void {
    $this->createItem('visible', ['weight' => 10]);
    $this->createItem('hidden', ['weight' => 20], 'forbidden');
    // A neutral plugin answer is not a forbidden one: the access handler falls
    // through to the visibility pass, which allows.
    $this->createItem('undecided', ['weight' => 30], 'neutral');

    $this->assertSame(['visible', 'undecided'], array_keys($this->loadToolbar()->getItems()));
  }

  /**
   * The dropped item's cacheability reaches the caller's metadata.
   *
   * Covers: it adds the dropped item's cacheability to the metadata the caller
   * passed in.
   */
  public function testAddsDroppedItemCacheabilityToPassedMetadata(): void {
    $this->createItem('visible', ['weight' => 10]);
    $hidden = $this->createItem('hidden', ['weight' => 20], 'forbidden');

    $cacheableMetadata = new CacheableMetadata();
    $items = $this->loadToolbar()->getItems(NULL, $cacheableMetadata);

    $this->assertArrayNotHasKey('hidden', $items);
    $tags = $cacheableMetadata->getCacheTags();
    // The dropped entity's own cacheability.
    $this->assertContains('config:neo_toolbar.neo_toolbar_item.hidden', $tags);
    $this->assertSame(['config:neo_toolbar.neo_toolbar_item.hidden'], $hidden->getCacheTags());
    // And the cacheability of the access result that dropped it — the fixture
    // plugin tags its answer with the item id, so this tag can only have come
    // from the forbidden result itself.
    $this->assertContains('neo_toolbar_test:hidden', $tags);
  }

  /**
   * A region id filters the answer to that region.
   *
   * Covers: it returns only one region's items when a region id is given.
   */
  public function testReturnsOnlyTheRequestedRegionsItems(): void {
    $this->createItem('top', ['weight' => 10, 'region' => 'test_horizontal']);
    $this->createItem('side', ['weight' => 20, 'region' => 'test_vertical']);

    $toolbar = $this->loadToolbar();
    $this->assertSame(['top', 'side'], array_keys($toolbar->getItems()));
    $this->assertSame(['side'], array_keys($toolbar->getItems('test_vertical')));
    $this->assertSame(['top'], array_keys($toolbar->getItems('test_horizontal')));
    $this->assertSame([], $toolbar->getItems('top_start'));
  }

  /**
   * The second call is answered from the memo.
   *
   * Covers: it answers a second call from the memo without re-querying.
   */
  public function testAnswersSecondCallFromTheMemo(): void {
    $this->createItem('first', ['weight' => 10]);

    $toolbar = $this->loadToolbar();
    $this->assertSame(['first'], array_keys($toolbar->getItems()));

    // A new enabled item the query would find is invisible to the memoised
    // toolbar object, which is how the absence of a second query is observed.
    $this->createItem('second', ['weight' => 20]);
    $this->assertSame(['first'], array_keys($toolbar->getItems()));

    // The memo lives on the object, not in a shared cache: a freshly loaded
    // toolbar queries again and sees both.
    $this->assertSame(['first', 'second'], array_keys($this->loadToolbar()->getItems()));
  }

  /**
   * Edit mode returns every enabled item, access unchecked.
   *
   * Covers: it returns every item without an access pass in edit mode.
   */
  public function testEditModeReturnsEveryItemWithoutAnAccessPass(): void {
    $this->createItem('visible', ['weight' => 10]);
    $this->createItem('hidden', ['weight' => 20], 'forbidden');
    // "Every item" is still every *enabled* item: the status condition is on
    // the query, which runs ahead of the edit-mode branch.
    $this->createItem('zeta', ['weight' => 30, 'status' => FALSE]);

    $toolbar = $this->loadToolbar()->setEditMode();
    $this->assertSame(['visible', 'hidden'], array_keys($toolbar->getItems()));
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
