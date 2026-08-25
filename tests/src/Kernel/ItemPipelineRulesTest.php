<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_toolbar\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_toolbar\Entity\Toolbar;
use Drupal\neo_toolbar\Entity\ToolbarItem;
use Drupal\neo_toolbar\ToolbarInterface;
use Drupal\neo_toolbar\ToolbarItemInterface;
use Drupal\neo_toolbar\ToolbarRepository;
use PHPUnit\Framework\Attributes\Group;

/**
 * Covers the four pipeline rules as things callable on their own.
 *
 * `ToolbarRepository::getToolbarItems()` is the composition; the rules it runs
 * in order — the view-access filter, region collapse, sibling access and the
 * triggering-item restore — are public methods each taking an array of toolbar
 * items and returning the ones that survive it. That is the whole point of
 * `docs/adr/0005`: every assertion below hands a rule two, three or four
 * hand-built items and reads the surviving array back. Not one of them loads a
 * toolbar to say what it means.
 *
 * The items are real `neo_toolbar_item` entities built with the fixture
 * plugins, because a rule consumes access answers and region ids that come
 * from plugins, and the characterisation suite already has one way of making an
 * item that forbids itself. A second, mocked way would be a second thing to
 * keep true.
 *
 * The pre-access item set that region collapse and the restore both take as
 * their second argument is written out explicitly in each test, which is what
 * makes "this region emptied out" a statement a test can make without a
 * toolbar having emptied anything.
 */
#[Group('neo_toolbar')]
final class ItemPipelineRulesTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The floor the rules need, and no more: `KernelTestBase` installs exactly
   * what is named here, so `token`, `neo`, `neo_modal`, `neo_tooltip` and
   * `neo_image` stay out even though the info file names them. `Divider`
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
   * The access filter drops forbidden items and records what it examined.
   *
   * Covers: the access-filter rule drops forbidden items and records their
   * cacheability, given a plain array of items.
   */
  public function testAccessFilterDropsForbiddenItemsAndRecordsTheirCacheability(): void {
    $items = [
      'visible' => $this->createItem('visible'),
      'hidden' => $this->createItem('hidden', [], 'forbidden'),
      // Neutral is not forbidden: the access handler falls through to the
      // visibility pass, which allows.
      'undecided' => $this->createItem('undecided', [], 'neutral'),
    ];

    $cacheableMetadata = new CacheableMetadata();
    $survivors = $this->repository()->filterItemsByViewAccess($items, $cacheableMetadata);

    $this->assertSame(['visible', 'undecided'], array_keys($survivors));

    $tags = $cacheableMetadata->getCacheTags();
    // The dropped entity's own cacheability, which the answer cannot carry
    // because the entity is no longer in it.
    $this->assertContains('config:neo_toolbar.neo_toolbar_item.hidden', $tags);
    // And the cacheability of the access result that dropped it — the fixture
    // plugin tags its answer with the item id, so this tag can only have come
    // from the forbidden result itself.
    $this->assertContains('neo_toolbar_test:hidden', $tags);
    // The surviving items were examined too, and are recorded.
    $this->assertContains('config:neo_toolbar.neo_toolbar_item.visible', $tags);
    $this->assertContains('config:neo_toolbar.neo_toolbar_item.undecided', $tags);
  }

  /**
   * Region collapse drops the opener of a region that emptied out.
   *
   * Covers: the region-collapse rule drops a derived region's triggering item
   * when that region's items have all gone, given a plain array.
   */
  public function testRegionCollapseDropsTheTriggeringItemOfAnEmptiedDerivedRegion(): void {
    $trigger = $this->createRegionItem('trigger');
    $bystander = $this->createItem('bystander');
    $child = $this->createItem('child', ['region' => 'item:trigger']);

    $allItems = [
      'trigger' => $trigger,
      'bystander' => $bystander,
      'child' => $child,
    ];

    // The child is the only thing `item:trigger` held, and the access filter
    // has already taken it. The opener would now open onto nothing.
    $survivors = $this->repository()->collapseEmptyRegions([
      'trigger' => $trigger,
      'bystander' => $bystander,
    ], $allItems);
    $this->assertSame(['bystander'], array_keys($survivors));

    // Nothing emptied, nothing collapses: the same rule over the same items
    // leaves every one of them alone.
    $this->assertSame(
      ['trigger', 'bystander', 'child'],
      array_keys($this->repository()->collapseEmptyRegions($allItems, $allItems))
    );
  }

  /**
   * Sibling access drops an item its immediate neighbours forbid.
   *
   * Covers: the sibling rule drops an item its immediate neighbours forbid,
   * given a plain array of one region's items.
   *
   * `Divider` is the module's only non-trivial `accessBySiblings()`, so the
   * rule is driven by real dividers rather than by a fixture that forbids on
   * command.
   */
  public function testSiblingRuleDropsAnItemItsNeighboursForbid(): void {
    $alpha = $this->createItem('alpha');
    $beta = $this->createItem('beta');
    $first = $this->createDivider('first');
    $second = $this->createDivider('second');

    // Two adjacent dividers forbid each other, and neither is spared by the
    // other's departure: the rule reads the region as it was handed to it.
    $this->assertSame(['alpha', 'beta'], array_keys($this->repository()->filterItemsBySiblingAccess([
      'alpha' => $alpha,
      'first' => $first,
      'second' => $second,
      'beta' => $beta,
    ])));

    // A divider between two ordinary items keeps its place.
    $this->assertSame(['alpha', 'first', 'beta'], array_keys($this->repository()->filterItemsBySiblingAccess([
      'alpha' => $alpha,
      'first' => $first,
      'beta' => $beta,
    ])));

    // And a divider with nothing before it goes for want of a previous item.
    $this->assertSame(['alpha', 'beta'], array_keys($this->repository()->filterItemsBySiblingAccess([
      'first' => $first,
      'alpha' => $alpha,
      'beta' => $beta,
    ])));
  }

  /**
   * The restore rule brings back an opener whose region still has children.
   *
   * Covers: the restore rule returns a triggering item whose derived region
   * still has children, given a plain array.
   */
  public function testRestoreReturnsTriggeringItemWhoseDerivedRegionStillHasChildren(): void {
    $trigger = $this->createRegionItem('region_menu', [], 'forbidden');
    $child = $this->createItem('menu_child', ['region' => 'item:region_menu']);

    $allItems = ['region_menu' => $trigger, 'menu_child' => $child];

    // The opener lost its own access; its child has no other way in, so the
    // rule appends the opener rather than strand the child.
    $restored = $this->repository()->restoreTriggeringItems(['menu_child' => $child], $allItems);
    $this->assertSame(['menu_child', 'region_menu'], array_keys($restored));
    $this->assertSame($trigger, $restored['region_menu']);

    // An opener that never left is left exactly where it was.
    $this->assertSame(
      ['region_menu', 'menu_child'],
      array_keys($this->repository()->restoreTriggeringItems($allItems, $allItems))
    );

    // A derived region with no surviving children has nothing to strand, so
    // there is nothing to bring back.
    $this->assertSame([], $this->repository()->restoreTriggeringItems([], $allItems));
  }

  /**
   * The restore rule answers each derived region on its own.
   *
   * Covers: the restore rule no longer reassigns the region id of the loop
   * containing it, and answers what it answered before.
   *
   * The reassignment is not observable through an answer — the inner loop's
   * condition never depended on the loop and the key it clobbered was never
   * read again — so the shape is asserted where it lives, in the method's own
   * source, and the answer is asserted beside it. Two derived regions are used
   * because one cannot show that the rule keeps its place.
   */
  public function testRestoreAnswersEveryDerivedRegionWithoutAnInnerLoop(): void {
    $first = $this->createRegionItem('region_one', [], 'forbidden');
    $second = $this->createRegionItem('region_two', [], 'forbidden');
    $firstChild = $this->createItem('one_child', ['region' => 'item:region_one']);
    $secondChild = $this->createItem('two_child', ['region' => 'item:region_two']);

    $allItems = [
      'region_one' => $first,
      'region_two' => $second,
      'one_child' => $firstChild,
      'two_child' => $secondChild,
    ];

    // Both openers come back, appended in the order their regions were met —
    // the answer the rule gave when a loop inside it was overwriting the outer
    // loop's region id on every pass.
    $this->assertSame(
      ['one_child', 'two_child', 'region_one', 'region_two'],
      array_keys($this->repository()->restoreTriggeringItems([
        'one_child' => $firstChild,
        'two_child' => $secondChild,
      ], $allItems))
    );

    // One `foreach` — over the regions — and nothing nested inside it, so
    // there is no inner loop left to reassign the region id it holds.
    $source = $this->methodSource('restoreTriggeringItems');
    $this->assertSame(1, substr_count($source, 'foreach'), $source);
  }

  /**
   * The composition answers what the four rules answer in order.
   *
   * Covers: the composed pipeline answers exactly what it answered before the
   * rules were separable.
   *
   * The fixture exercises all four: `hidden` goes to the access filter,
   * `dead_trigger` to the collapse, `lone_divider` to sibling access, and
   * `region_live` comes back from the restore.
   */
  public function testComposedPipelineAnswersTheFourRulesRunInOrder(): void {
    $this->createItem('alpha', ['weight' => 10]);
    $this->createItem('hidden', ['weight' => 15], 'forbidden');
    $this->createRegionItem('dead_trigger', ['weight' => 20]);
    $this->createItem('dead_child', ['weight' => 25, 'region' => 'item:dead_trigger'], 'forbidden');
    $this->createDivider('lone_divider', ['weight' => 30, 'region' => 'test_vertical']);
    $this->createRegionItem('region_live', ['weight' => 40], 'forbidden');
    $this->createItem('live_child', ['weight' => 45, 'region' => 'item:region_live']);

    $repository = $this->repository();

    // The answer the pipeline gave before the rules were separable, written
    // out rather than derived, so this cannot agree with a decomposition that
    // moved and a composition that moved with it.
    $this->assertSame(
      ['alpha', 'live_child', 'region_live'],
      array_keys($repository->getToolbarItems($this->loadToolbar()))
    );

    // And the composition is the four rules in order over the same input.
    $allItems = $this->allItemsInWeightOrder();
    $items = $repository->filterItemsByViewAccess($allItems, new CacheableMetadata());
    $items = $repository->collapseEmptyRegions($items, $allItems);
    $items = $repository->filterItemsBySiblingAccess($items);
    $items = $repository->restoreTriggeringItems($items, $allItems);

    $this->assertSame(['alpha', 'live_child', 'region_live'], array_keys($items));
  }

  /**
   * Loads every enabled item of the test toolbar in weight order.
   *
   * The pre-access item set the pipeline starts from, built here so a rule can
   * be handed one without the composition having run.
   *
   * @return \Drupal\neo_toolbar\ToolbarItemInterface[]
   *   The items, keyed by item id.
   */
  protected function allItemsInWeightOrder(): array {
    $storage = $this->container->get('entity_type.manager')->getStorage('neo_toolbar_item');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->condition('toolbar', 'test_toolbar')
      ->condition('status', TRUE)
      ->sort('weight')
      ->execute();
    /** @var \Drupal\neo_toolbar\ToolbarItemInterface[] $items */
    $items = $storage->loadMultiple($ids);
    return $items;
  }

  /**
   * Reads a repository method's own source.
   *
   * @param string $method
   *   The method name.
   *
   * @return string
   *   The method's source, including its signature.
   */
  protected function methodSource(string $method): string {
    $reflection = new \ReflectionMethod(ToolbarRepository::class, $method);
    $file = $reflection->getFileName();
    $this->assertIsString($file);
    $lines = file($file);
    $this->assertIsArray($lines);
    return implode('', array_slice(
      $lines,
      $reflection->getStartLine() - 1,
      $reflection->getEndLine() - $reflection->getStartLine() + 1
    ));
  }

  /**
   * Creates a toolbar item on the test toolbar.
   *
   * @param string $id
   *   The item id, which is also the array key every rule answers under.
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
   * It carries no settings of its own, which is why this does not go through
   * the fixture plugin's `access` key — that key is not in the divider's
   * config schema.
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
   * The repository service, which holds no state the rules read.
   *
   * @return \Drupal\neo_toolbar\ToolbarRepository
   *   The repository.
   */
  protected function repository(): ToolbarRepository {
    $repository = $this->container->get('neo_toolbar.repository');
    $this->assertInstanceOf(ToolbarRepository::class, $repository);
    return $repository;
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
