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
 * Covers the item pipeline's new entry point on the toolbar repository.
 *
 * The pass that used to be 114 lines inside `Toolbar::getItems()` is now
 * `ToolbarRepository::getToolbarItems()`: it takes a toolbar, runs the rules,
 * fills the cacheable metadata the caller handed it, and remembers nothing.
 * What the entity keeps is the memo, one delegation, the region filter and the
 * cacheability merge.
 *
 * The characterisation suite — `ItemPipelineAccessFilterTest` and
 * `ItemPipelineRegionRulesTest` — still asserts every answer the pipeline
 * gives, through the entity accessor, and passes unedited across this move.
 * This class asserts the two things that move cannot: that the repository
 * answers on its own, with no entity memo in front of it, and that the entity
 * is now the thin accessor its interface always advertised.
 *
 * The toolbar and its items are built here rather than installed from
 * `neo_toolbar`'s `config/install`, because one shipped default carries a
 * plugin from `neo_favicon` — a package this module does not depend on.
 */
#[Group('neo_toolbar')]
final class ItemPipelineEntryPointTest extends KernelTestBase {

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
   * The repository runs the pass for a toolbar handed to it.
   *
   * Covers: the repository answers a toolbar's enabled items in weight order
   * and drops the ones view access forbids.
   */
  public function testRepositoryAnswersEnabledItemsInWeightOrderMinusForbiddenOnes(): void {
    // Created out of order, and out of alphabetical order, so neither creation
    // order nor id ordering can produce a passing result by accident.
    $this->createItem('gamma', ['weight' => 30]);
    $this->createItem('alpha', ['weight' => 10]);
    $this->createItem('beta', ['weight' => 20], 'forbidden');
    // Disabled items never reach the access filter; the query drops them.
    $this->createItem('zeta', ['weight' => 5, 'status' => FALSE]);

    $items = $this->repository()->getToolbarItems($this->loadToolbar());

    $this->assertSame(['alpha', 'gamma'], array_keys($items));
  }

  /**
   * Every item the pass examined reaches the caller's metadata.
   *
   * Covers: the repository fills a caller's cacheable metadata from every item
   * it examined, dropped ones included.
   */
  public function testRepositoryFillsCallersCacheableMetadataIncludingDroppedItems(): void {
    $this->createItem('visible', ['weight' => 10]);
    $this->createItem('hidden', ['weight' => 20], 'forbidden');

    $cacheableMetadata = new CacheableMetadata();
    $items = $this->repository()->getToolbarItems($this->loadToolbar(), $cacheableMetadata);

    $this->assertSame(['visible'], array_keys($items));
    $tags = $cacheableMetadata->getCacheTags();
    // The surviving item's own cacheability.
    $this->assertContains('config:neo_toolbar.neo_toolbar_item.visible', $tags);
    // And the dropped item's, which is the half a caller cannot recover from
    // the answer it was given: the entity is gone from the array, so only the
    // metadata can say the answer depends on it.
    $this->assertContains('config:neo_toolbar.neo_toolbar_item.hidden', $tags);
    // The fixture plugin tags its access answer with the item id, so this tag
    // can only have come from the forbidden result itself. Its allowed twin has
    // no tag here, and that is `ToolbarItemAccessControlHandler`'s doing rather
    // than the pipeline's: only a forbidden plugin answer is returned, and an
    // allowed one is replaced by the visibility pass's result.
    $this->assertContains('neo_toolbar_test:hidden', $tags);
    // Which is the result this context comes from — nothing else on this path
    // carries one, so the allowed item's access answer landed here as well.
    $this->assertContains('user.permissions', $cacheableMetadata->getCacheContexts());
  }

  /**
   * Edit mode skips the whole access pass.
   *
   * Covers: the repository returns every item without an access pass when the
   * toolbar is in edit mode.
   */
  public function testRepositoryReturnsEveryItemWithoutAnAccessPassInEditMode(): void {
    $this->createItem('visible', ['weight' => 10]);
    $this->createItem('hidden', ['weight' => 20], 'forbidden');
    // "Every item" is still every *enabled* item: the status condition is on
    // the query, which runs ahead of the edit-mode branch.
    $this->createItem('zeta', ['weight' => 30, 'status' => FALSE]);

    $cacheableMetadata = new CacheableMetadata();
    $toolbar = $this->loadToolbar()->setEditMode();
    $items = $this->repository()->getToolbarItems($toolbar, $cacheableMetadata);

    $this->assertSame(['visible', 'hidden'], array_keys($items));
    // The mode is read off the toolbar the caller passed, and no access result
    // was consulted — which is why nothing tagged the metadata. That absence is
    // how "without an access pass" is observed rather than merely asserted.
    $this->assertSame([], $cacheableMetadata->getCacheTags());
  }

  /**
   * The entity delegates once and answers everything else from the memo.
   *
   * Covers: the toolbar entity delegates to the repository, memoizes the
   * result, and filters the memo to one region without re-running the pass.
   *
   * The repository service is swapped for a counting decorator over the real
   * one, because "delegates" and "does not re-run the pass" are statements
   * about how many times the pipeline was entered, and no answer the entity
   * returns can distinguish one entry from three. Everything the decorator is
   * asked it forwards, so the answers below are the real pipeline's.
   */
  public function testEntityDelegatesOnceAndFiltersTheMemoToOneRegion(): void {
    $this->createItem('top', ['weight' => 10, 'region' => 'test_horizontal']);
    $this->createItem('side', ['weight' => 20, 'region' => 'test_vertical']);
    $this->createItem('hidden', ['weight' => 30], 'forbidden');

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
    $cacheableMetadata = new CacheableMetadata();
    $this->assertSame(['top', 'side'], array_keys($toolbar->getItems(NULL, $cacheableMetadata)));
    // The entity ran no pipeline of its own: the one pass that happened
    // happened inside the repository.
    $this->assertSame(1, $spy->calls);

    // A region id filters what the memo already holds. Neither call re-enters
    // the pipeline, and both are consistent with the unfiltered answer.
    $this->assertSame(['side'], array_keys($toolbar->getItems('test_vertical')));
    $this->assertSame(['top'], array_keys($toolbar->getItems('test_horizontal')));
    $this->assertSame([], $toolbar->getItems('item:top'));
    $this->assertSame(['top', 'side'], array_keys($toolbar->getItems()));
    $this->assertSame(1, $spy->calls);

    // The memo holds the cacheability too, and merges it into whatever each
    // caller passes in — including a caller that arrived after the pass ran.
    $late = new CacheableMetadata();
    $toolbar->getItems('test_vertical', $late);
    $this->assertSame(1, $spy->calls);
    foreach (['config:neo_toolbar.neo_toolbar_item.hidden', 'neo_toolbar_test:hidden'] as $tag) {
      $this->assertContains($tag, $cacheableMetadata->getCacheTags());
      $this->assertContains($tag, $late->getCacheTags());
    }
  }

  /**
   * Creates a toolbar item on the test toolbar.
   *
   * @param string $id
   *   The item id, which is also the array key the pipeline answers under.
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
   * The repository service, which holds no state the pipeline reads.
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
