<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_toolbar\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Routing\RouteMatch;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_toolbar\Entity\Toolbar;
use Drupal\neo_toolbar\Entity\ToolbarItem;
use Drupal\neo_toolbar\LazyBuilders;
use Drupal\neo_toolbar\ToolbarInterface;
use Drupal\neo_toolbar\ToolbarItemInterface;
use Drupal\neo_toolbar\ToolbarRepository;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Routing\Route;

/**
 * Covers the memo following the mode it was told about.
 *
 * `Toolbar::getItems()` computes once and remembers, and the pipeline it
 * memoises is skipped entirely in edit mode — so the memo held a mode-specific
 * answer under a mode-agnostic key. Setting the mode after a first call
 * therefore answered the previous mode's items: the access-filtered set when
 * edit mode had just been switched on, and the unfiltered admin set when it
 * had just been switched off.
 *
 * The correctness of every call site rested on an invariant held by call order
 * rather than by the code — set the mode on a freshly-loaded entity, before
 * anything asks it for items — and `neo_toolbar` is a module whose entity type
 * declares `static_cache = true`, so "freshly loaded" is exactly what the
 * second load in a request is not. `setEditMode()` now discards the memo and
 * its cacheable metadata when the mode actually changes, and leaves both alone
 * when it does not, so a caller setting the mode it already has does not pay
 * for a second pass.
 *
 * The three call sites that set the mode are asserted through the code that
 * sets it — `ToolbarRepository::getActive()` and the two lazy builders — rather
 * than by setting it here, because "answers what it always answered" is a
 * statement about those callers and not about the setter.
 */
#[Group('neo_toolbar')]
final class ItemPipelineEditModeMemoTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The floor the item pipeline needs. `user` is here for its entity type as
   * much as its module: the region lazy builder renders each surviving item's
   * element collection, and that path reaches the current account.
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
    $this->installEntitySchema('user');
    // What `user_install()` creates and a kernel test does not: uid 0. The
    // element collection the region builder renders loads the current account,
    // which in a kernel test is anonymous.
    User::create([
      'uid' => 0,
      'name' => '',
      'status' => 0,
    ])->save();
    Toolbar::create([
      'id' => 'test_toolbar',
      'label' => 'Test toolbar',
      'weight' => 0,
    ])->save();
  }

  /**
   * The mode just set is the mode answered, in both directions.
   *
   * Covers: setting edit mode after a first call answers the edit-mode items
   * rather than the previous mode's.
   */
  public function testEditModeSetAfterTheFirstCallAnswersTheModeItWasJustGiven(): void {
    $this->createItem('visible', ['weight' => 10]);
    $this->createItem('hidden', ['weight' => 20], 'forbidden');

    // A first call in the default mode filters by access and memoises.
    $filtered = $this->loadToolbar();
    $this->assertSame(['visible'], array_keys($filtered->getItems()));

    // Edit mode arriving second is still edit mode. The memo the first call
    // established answered a question that is no longer the one being asked.
    $filtered->setEditMode();
    $this->assertTrue($filtered->isEditMode());
    $this->assertSame(['visible', 'hidden'], array_keys($filtered->getItems()));

    // The cacheability follows the same discard, which matters more than it
    // looks: an edit-mode pass consults no access result at all, so a caller
    // arriving after the switch must not be handed the access dependencies the
    // filtered pass recorded.
    $editing = new CacheableMetadata();
    $filtered->getItems(NULL, $editing);
    $this->assertSame([], $editing->getCacheTags());

    // And off again on the same object, which is the mirror image: the
    // unfiltered answer must not outlive the mode that produced it.
    $filtered->setEditMode(FALSE);
    $this->assertFalse($filtered->isEditMode());
    $this->assertSame(['visible'], array_keys($filtered->getItems()));
    $refiltered = new CacheableMetadata();
    $filtered->getItems(NULL, $refiltered);
    $this->assertContains('neo_toolbar_test:hidden', $refiltered->getCacheTags());

    // The same trap approached from the other side: a toolbar memoised in edit
    // mode, then taken out of it.
    $wasEditing = $this->loadToolbar()->setEditMode();
    $this->assertSame(['visible', 'hidden'], array_keys($wasEditing->getItems()));
    $wasEditing->setEditMode(FALSE);
    $this->assertSame(['visible'], array_keys($wasEditing->getItems()));
  }

  /**
   * A mode that did not change leaves the memo where it is.
   *
   * Covers: setting the mode it already has does not discard a memo that is
   * still correct.
   *
   * The repository service is swapped for a counting decorator over the real
   * one, because "did not discard" is a statement about how many times the
   * pipeline was entered, and no answer the entity returns can distinguish one
   * entry from three. Everything the decorator is asked it forwards, so the
   * answers below are the real pipeline's.
   */
  public function testTheMemoIsDiscardedOnlyWhenTheModeActuallyChanges(): void {
    $this->createItem('visible', ['weight' => 10]);
    $this->createItem('hidden', ['weight' => 20], 'forbidden');

    $spy = new class($this->repository()) {

      /**
       * How many times the pipeline was entered.
       *
       * @var int
       */
      public int $calls = 0;

      /**
       * Constructs the decorator.
       */
      public function __construct(
        protected readonly ToolbarRepository $inner,
      ) {}

      /**
       * Counts the call and forwards it to the real repository.
       *
       * @param \Drupal\neo_toolbar\ToolbarInterface $toolbar
       *   The toolbar.
       * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheableMetadata
       *   The cacheable metadata to fill.
       *
       * @return \Drupal\neo_toolbar\ToolbarItemInterface[]
       *   The surviving items.
       */
      public function getToolbarItems(ToolbarInterface $toolbar, ?CacheableMetadata $cacheableMetadata = NULL): array {
        $this->calls++;
        return $this->inner->getToolbarItems($toolbar, $cacheableMetadata);
      }

    };
    $this->container->set('neo_toolbar.repository', $spy);

    $toolbar = $this->loadToolbar();
    $this->assertSame(['visible'], array_keys($toolbar->getItems()));
    $this->assertSame(1, $spy->calls);

    // The mode it already has. Nothing about the memo has gone stale, so
    // nothing is thrown away and the second call is still free.
    $toolbar->setEditMode(FALSE);
    $this->assertSame(['visible'], array_keys($toolbar->getItems()));
    $this->assertSame(1, $spy->calls);

    // A mode that genuinely changed costs exactly one further pass.
    $toolbar->setEditMode();
    $this->assertSame(['visible', 'hidden'], array_keys($toolbar->getItems()));
    $this->assertSame(2, $spy->calls);

    // And setting that same mode again costs nothing, which is the half of
    // this criterion an implementation that discarded unconditionally would
    // fail while answering every item correctly.
    $toolbar->setEditMode(TRUE);
    $this->assertSame(['visible', 'hidden'], array_keys($toolbar->getItems()));
    $this->assertSame(2, $spy->calls);

    // Back to the filtered answer: one more change, one more pass, and then
    // the same mode twice more for nothing.
    $toolbar->setEditMode(FALSE);
    $this->assertSame(['visible'], array_keys($toolbar->getItems()));
    $this->assertSame(3, $spy->calls);
    $toolbar->setEditMode(FALSE);
    $toolbar->setEditMode(FALSE);
    $this->assertSame(['visible'], array_keys($toolbar->getItems()));
    $this->assertSame(3, $spy->calls);
  }

  /**
   * The active toolbar resolution answers in edit mode whatever it was handed.
   *
   * Covers: the three call sites that set the mode before their first call
   * answer exactly what they answered before — the first of them,
   * `ToolbarRepository::getActive()`.
   */
  public function testActiveToolbarResolutionAnswersInEditModeWhateverItWasHanded(): void {
    $this->createItem('visible', ['weight' => 10]);
    $this->createItem('hidden', ['weight' => 20], 'forbidden');

    // What it has always answered, on the entity the param converter upcast
    // and nothing has asked anything of yet.
    $untouched = $this->loadToolbar();
    $active = $this->repository($untouched)->getActive();
    $this->assertInstanceOf(ToolbarInterface::class, $active);
    $this->assertTrue($active->isEditMode());
    $this->assertSame(['visible', 'hidden'], array_keys($active->getItems()));

    // And the same answer when the object it was handed is not fresh. The
    // toolbar entity type declares `static_cache = true`, so a second load in
    // one request is the same object as the first — which is how an invariant
    // that reads as "the render path always sets the mode first" stops holding
    // without anybody writing a line of code that breaks it.
    $touched = $this->loadToolbar();
    $this->assertSame(['visible'], array_keys($touched->getItems()));
    $onTheRoute = $this->repository($touched)->getActive();
    $this->assertInstanceOf(ToolbarInterface::class, $onTheRoute);
    $this->assertSame($touched, $onTheRoute);
    $this->assertSame(['visible', 'hidden'], array_keys($onTheRoute->getItems()));
  }

  /**
   * The region lazy builder answers for the mode it was given.
   *
   * Covers: the three call sites that set the mode before their first call
   * answer exactly what they answered before — the second of them,
   * `LazyBuilders::renderToolbarRegion()`.
   */
  public function testRegionLazyBuilderAnswersForTheModeItWasGiven(): void {
    $this->createItem('visible', ['weight' => 10]);
    $this->createItem('hidden', ['weight' => 20], 'forbidden');

    // Each mode on its own, against a toolbar nothing has loaded yet: the two
    // answers the render path has always produced.
    $this->resetToolbarStaticCache();
    $this->assertSame(['visible'], $this->regionItemIds(FALSE));
    $this->resetToolbarStaticCache();
    $this->assertSame(['visible', 'hidden'], $this->regionItemIds(TRUE));

    // Both in one request, which is what the builder is actually handed: it
    // loads the toolbar by id, and the second load answers from the static
    // cache with the object the first call already memoised.
    $this->resetToolbarStaticCache();
    $this->assertSame(['visible'], $this->regionItemIds(FALSE));
    $this->assertSame(['visible', 'hidden'], $this->regionItemIds(TRUE));
    $this->assertSame(['visible'], $this->regionItemIds(FALSE));
  }

  /**
   * The item lazy builder answers the same and leaves the next render correct.
   *
   * Covers: the three call sites that set the mode before their first call
   * answer exactly what they answered before — the third of them,
   * `LazyBuilders::renderToolbarItem()`.
   *
   * This one never reads the memo: it sets the mode on the toolbar and then
   * loads and renders one item directly, so what it answers cannot move. What
   * it can do is set a mode on the toolbar the rest of the request shares, and
   * the region render that follows it has to answer for its own mode rather
   * than for the one this left behind.
   */
  public function testItemLazyBuilderAnswersTheSameAndLeavesTheNextRegionRenderCorrect(): void {
    $this->createItem('visible', ['weight' => 10]);
    $this->createItem('hidden', ['weight' => 20], 'forbidden');

    // The item's own render array, in both modes, which is the answer this
    // builder has always given and the one nothing in this ticket touches.
    $this->resetToolbarStaticCache();
    $inEditMode = $this->lazyBuilders()->renderToolbarItem('test_toolbar', 'visible', 'test_horizontal', TRUE);
    $this->assertNotSame([], $inEditMode);
    $this->resetToolbarStaticCache();
    // Equality rather than identity: the two builds carry fresh `Attribute`
    // objects either way, and what is being asserted is that the mode did not
    // change what the item renders.
    $this->assertEquals($inEditMode, $this->lazyBuilders()->renderToolbarItem('test_toolbar', 'visible', 'test_horizontal', FALSE));

    // A placeholdered item renders after the region that emitted it, on the
    // same statically-cached toolbar, and a region rendered after that must
    // still answer for the mode it is given rather than the one left behind.
    $this->resetToolbarStaticCache();
    $this->assertSame(['visible', 'hidden'], $this->regionItemIds(TRUE));
    $this->lazyBuilders()->renderToolbarItem('test_toolbar', 'visible', 'test_horizontal', TRUE);
    $this->assertSame(['visible'], $this->regionItemIds(FALSE));
  }

  /**
   * The item ids the region lazy builder puts in its build.
   *
   * @param bool $isEditMode
   *   The mode to hand the builder.
   *
   * @return string[]
   *   The item ids, in the order the builder emitted them.
   */
  protected function regionItemIds(bool $isEditMode): array {
    $build = $this->lazyBuilders()->renderToolbarRegion('test_toolbar', 'test_horizontal', $isEditMode);
    return array_keys($build['#items'] ?? []);
  }

  /**
   * The lazy builders, which load the toolbar themselves.
   *
   * @return \Drupal\neo_toolbar\LazyBuilders
   *   The lazy builders.
   */
  protected function lazyBuilders(): LazyBuilders {
    $builders = $this->container->get('neo_toolbar.lazy_builders');
    $this->assertInstanceOf(LazyBuilders::class, $builders);
    return $builders;
  }

  /**
   * A repository, optionally with a toolbar on its route.
   *
   * @param \Drupal\neo_toolbar\ToolbarInterface|null $routeToolbar
   *   The toolbar the route carries, or NULL for no toolbar parameter.
   *
   * @return \Drupal\neo_toolbar\ToolbarRepository
   *   The repository.
   */
  protected function repository(?ToolbarInterface $routeToolbar = NULL): ToolbarRepository {
    $routeMatch = $this->container->get('current_route_match');
    if ($routeToolbar) {
      // The shape the entity param converter leaves behind on the toolbar's
      // own edit route: the upcast entity under the parameter name, the raw id
      // beside it.
      $routeMatch = new RouteMatch(
        'entity.neo_toolbar.edit_form',
        new Route('/admin/config/neo/toolbar/{neo_toolbar}'),
        ['neo_toolbar' => $routeToolbar],
        ['neo_toolbar' => $routeToolbar->id()]
      );
    }
    return new ToolbarRepository($this->container->get('entity_type.manager'), $routeMatch);
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
   * Drops the toolbar the entity storage is holding on to.
   *
   * The toolbar entity type declares `static_cache = true`, so every load in a
   * request answers with one object. That is the mechanism half these
   * assertions are about, which is why clearing it is explicit rather than
   * hidden inside a load helper.
   */
  protected function resetToolbarStaticCache(): void {
    $this->container->get('entity_type.manager')->getStorage('neo_toolbar')->resetCache(['test_toolbar']);
  }

  /**
   * Loads the test toolbar, bypassing any memo an earlier call established.
   *
   * @return \Drupal\neo_toolbar\ToolbarInterface
   *   The toolbar.
   */
  protected function loadToolbar(): ToolbarInterface {
    $this->resetToolbarStaticCache();
    $toolbar = $this->container->get('entity_type.manager')->getStorage('neo_toolbar')->load('test_toolbar');
    $this->assertInstanceOf(ToolbarInterface::class, $toolbar);
    return $toolbar;
  }

}
