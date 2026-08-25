<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_toolbar\Unit;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\neo_settings\Plugin\SettingsInterface;
use Drupal\neo_settings\SettingsRepositoryInterface;
use Drupal\neo_toolbar\ToolbarItemCollection;
use Drupal\neo_toolbar\ToolbarItemElement;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Characterises the element collection.
 *
 * `ToolbarItemCollection` is the ordered set of elements one toolbar item
 * contributes, and it owns the answer to whether that item renders at all.
 * Every toolbar item builds one — `ToolbarItem::getElementCollection()` — and
 * so does every element with children, so the contract pinned here is the one
 * both the region's top level and a flyout panel are rendered through.
 *
 * Four rules carry the class.
 *
 * The **style** is snake-cased on the way in, on both routes into the field:
 * the constructor delegates to the setter, so a plugin declaring `flyOut` and
 * a caller setting `Fly Out` land on the same `fly_out` the Twig templates
 * and the stylesheet expect.
 *
 * The **order** is insertion order and nothing else. The collection never
 * sorts — a plugin emits its elements in the order it wants them, and the
 * item's own weight orders items against each other, not elements within one.
 * `remove()` filters in place rather than rebuilding, so it leaves a gap in
 * the keys; `toRenderable()` appends and therefore does not.
 *
 * The **accessibility** answer is the load-bearing one. `accessible()` drops
 * every element whose access is not an explicit allow, and `isEmpty()` counts
 * what survives that filter rather than what was added, because an item
 * wrapper rendered around nothing is worse than no wrapper — `LazyBuilders`
 * asks `isEmpty()` before it emits anything at all.
 *
 * The **cacheability** is collected twice over, and the second half is the
 * subtle one: an element the current user cannot see is dropped by
 * `accessible()` before its render array exists, so its access result's
 * contexts and tags would never reach a caller through the element. `add()`
 * folds them into the collection instead, at the moment the element arrives.
 *
 * Two boundaries. `ToolbarItemElementRenderTest` owns what one element's
 * render array contains; this class asserts only what the collection decides
 * about it — the style it pushed down and whether the element is in the array
 * at all. And `getPlugin()` has no criterion: nothing in the module ever sets
 * `$plugin`, so the getter's return type makes it unreachable, which is a
 * finding for the backlog rather than a behaviour to pin.
 *
 * The class stubs the same two-service container as its sibling, for the same
 * reasons: `Cache::mergeContexts()` asserts its tokens through
 * `cache_contexts_manager`, and an element whose title is hidden builds a
 * `Tooltip` that reads `neo_tooltip.settings`. Neither service decides
 * anything asserted here.
 */
#[Group('neo_toolbar')]
final class ToolbarItemCollectionTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // `Cache::mergeContexts()` asserts every token it is handed.
    $cacheContexts = $this->createMock(CacheContextsManager::class);
    $cacheContexts->method('assertValidTokens')->willReturn(TRUE);

    // A vertical element hides its title and therefore builds a `Tooltip`,
    // whose `getAttributes()` compares what it was given against the site's
    // saved `neo_tooltip` settings.
    $values = [
      'placement' => 'top',
      'animation' => 'fade',
      'trigger' => 'mouseenter focus',
    ];
    $settings = $this->createMock(SettingsInterface::class);
    $settings->method('getValue')->willReturnCallback(
      static function ($key, $default = NULL) use ($values) {
        return $values[$key] ?? $default;
      }
    );
    $tooltipSettings = $this->createMock(SettingsRepositoryInterface::class);
    $tooltipSettings->method('getActive')->willReturn($settings);

    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cacheContexts);
    $container->set('neo_tooltip.settings', $tooltipSettings);
    \Drupal::setContainer($container);
  }

  /**
   * The style is normalised on both routes into the field.
   *
   * A style is a CSS variant name, so it has to arrive at the template in one
   * shape whatever a plugin declared. The constructor does not assign the
   * field itself — it calls the setter — which is what keeps the two routes
   * from drifting apart, and is why one mutation of `setStyle()` takes both
   * halves of this criterion down.
   *
   * `Str::snake()` is doing two things at once here: it splits camelCase on
   * the capitals, and it strips whitespace by way of `ucwords()`, so `Fly Out`
   * and `flyOut` are the same style.
   *
   * Covers: it snake-cases the style it is constructed with, and again when
   * the style is set later.
   */
  public function testStyleIsSnakeCasedOnConstructionAndOnSet(): void {
    // An already-snake style is left exactly as it stands.
    $this->assertSame('default', (new ToolbarItemCollection('horizontal'))->getStyle());
    $this->assertSame('fly_out', (new ToolbarItemCollection('horizontal', 'fly_out'))->getStyle());

    // camelCase splits on the capital.
    $this->assertSame('fly_out', (new ToolbarItemCollection('horizontal', 'flyOut'))->getStyle());

    // And whitespace goes, because `ucwords()` capitalises each word before
    // the split runs.
    $this->assertSame('fly_out', (new ToolbarItemCollection('horizontal', 'Fly Out'))->getStyle());

    // The same normalisation applies to a style set after construction.
    $collection = new ToolbarItemCollection('horizontal', 'flyOut');
    $this->assertSame($collection, $collection->setStyle('localTasks'));
    $this->assertSame('local_tasks', $collection->getStyle());
    $collection->setStyle('Local Tasks');
    $this->assertSame('local_tasks', $collection->getStyle());

    // Setting no style at all is setting the default one.
    $collection->setStyle();
    $this->assertSame('default', $collection->getStyle());

    // The other two constructor arguments are carried untouched.
    $vertical = new ToolbarItemCollection('vertical', 'default', -7);
    $this->assertSame('vertical', $vertical->getAlignment());
    $this->assertSame(-7, $vertical->getWeight());
    $this->assertSame(0, (new ToolbarItemCollection('horizontal'))->getWeight());
  }

  /**
   * Insertion order, and removal by element id.
   *
   * The collection never sorts. A plugin emits its elements in the order it
   * wants them rendered — an icon before its badge, a label after its avatar —
   * and the item's own weight is what orders items against each other, so a
   * sort here would silently reorder every multi-element plugin in the module.
   *
   * `remove()` filters rather than rebuilding, so the keys keep the gap the
   * removal left and a later `add()` continues past it. That is invisible to
   * `toRenderable()`, which appends into a fresh list, but not to anything
   * indexing `all()` by position.
   *
   * Covers: it keeps insertion order, and removes an element by id.
   */
  public function testInsertionOrderIsKeptAndElementsRemoveById(): void {
    $collection = new ToolbarItemCollection('horizontal');

    // Weights that argue with the insertion order, so a sort would show.
    $first = $this->element('first');
    $first->setWeight(10);
    $second = $this->element('second');
    $second->setWeight(-10);
    $third = $this->element('third');

    $this->assertSame($collection, $collection->add($first));
    $collection->add($second)->add($third);

    $this->assertSame(['first', 'second', 'third'], $this->ids($collection->all()));
    // The collection holds the elements themselves, not copies of them.
    $this->assertSame([$first, $second, $third], $collection->all());

    // Removing answers the collection, and takes only the named element. The
    // keys are filtered rather than rebuilt, so the removal leaves a gap.
    $this->assertSame($collection, $collection->remove('first'));
    $this->assertSame([1 => 'second', 2 => 'third'], $this->ids($collection->all()));

    // The next element therefore continues past that gap.
    $fourth = $this->element('fourth');
    $collection->add($fourth);
    $this->assertSame([1 => 'second', 2 => 'third', 3 => 'fourth'], $this->ids($collection->all()));

    // An id no element carries removes nothing.
    $collection->remove('first');
    $collection->remove('');
    $this->assertSame([1 => 'second', 2 => 'third', 3 => 'fourth'], $this->ids($collection->all()));

    // Order survives into the render array, which appends and so has no gap.
    $build = $collection->toRenderable();
    $this->assertSame(
      ['second', 'third', 'fourth'],
      array_column($build['#elements'], '#id')
    );
    $this->assertSame([0, 1, 2], array_keys($build['#elements']));
  }

  /**
   * Only an explicit allow survives the filter.
   *
   * The filter reads `ToolbarItemElement::isAccessible()`, which unwraps an
   * `AccessResultInterface` with `isAllowed()` and passes a boolean through.
   * Neutral is the answer that matters: it is not forbidden, and it is still
   * dropped, because a toolbar element nobody granted is an element nobody
   * sees.
   *
   * `all()` is unaffected — the collection keeps every element it was given,
   * so an access answer that changes after the fact is still honoured — and
   * `accessible()` filters in place, so the survivors keep their original
   * keys.
   *
   * Covers: accessible() drops elements whose access is forbidden.
   */
  public function testAccessibleDropsForbiddenElements(): void {
    $collection = new ToolbarItemCollection('horizontal');

    // The default: an untouched element is accessible.
    $default = $this->element('default');
    // An explicit allow, as an access result rather than a boolean.
    $allowed = $this->element('allowed');
    $allowed->setAccess(AccessResult::allowed());
    $forbidden = $this->element('forbidden');
    $forbidden->setAccess(AccessResult::forbidden());
    // Neutral is not an allow, so it goes too.
    $neutral = $this->element('neutral');
    $neutral->setAccess(AccessResult::neutral());
    $boolean = $this->element('boolean');
    $boolean->setAccess(FALSE);

    $collection->add($default)->add($allowed)->add($forbidden)
      ->add($neutral)->add($boolean);

    // Keys are preserved, so the survivors sit where they were added.
    $this->assertSame([0 => 'default', 1 => 'allowed'], $this->ids($collection->accessible()));
    $this->assertSame([$default, $allowed], array_values($collection->accessible()));

    // Everything that was added is still there.
    $this->assertCount(5, $collection->all());

    // And the answer is read fresh, not memoised: revoking access to an
    // element already in the collection takes it out of the next answer.
    $default->setAccess(AccessResult::forbidden());
    $this->assertSame([1 => 'allowed'], $this->ids($collection->accessible()));
  }

  /**
   * Emptiness is measured after the access filter, not before.
   *
   * `LazyBuilders` asks this before it emits anything, so the difference
   * between "has elements" and "has elements this user may see" is the
   * difference between a toolbar item rendering an empty wrapper and not
   * rendering at all.
   *
   * Covers: isEmpty() counts only accessible elements.
   */
  public function testIsEmptyCountsOnlyAccessibleElements(): void {
    $collection = new ToolbarItemCollection('horizontal');

    // Nothing added at all.
    $this->assertTrue($collection->isEmpty());

    // Elements, but none of them accessible: still empty, even though the
    // collection is holding two.
    $forbidden = $this->element('forbidden');
    $forbidden->setAccess(AccessResult::forbidden());
    $boolean = $this->element('boolean');
    $boolean->setAccess(FALSE);
    $collection->add($forbidden)->add($boolean);

    $this->assertCount(2, $collection->all());
    $this->assertTrue($collection->isEmpty());

    // One accessible element is enough to make it non-empty.
    $collection->add($this->element('visible'));
    $this->assertFalse($collection->isEmpty());

    // And it follows the access answer back down again.
    $collection->remove('visible');
    $this->assertTrue($collection->isEmpty());
  }

  /**
   * Cacheability is collected twice: from the element, and from its access.
   *
   * The first half is ordinary — the element is a cacheable dependency and its
   * contexts, tags and max-age become the collection's.
   *
   * The second half is why `add()` is more than one line. An element whose
   * access is forbidden is dropped by `accessible()`, so its render array is
   * never built and the access result's own cacheability has no other route
   * out. Without this fold, a toolbar varying by permission would be cached as
   * though it varied by nothing, and the elements the user is missing would
   * stay missing after the grant.
   *
   * The fold is guarded on `CacheableDependencyInterface`, which is what keeps
   * the default boolean access out of it: handing a boolean to
   * `addCacheableDependency()` would pin the whole collection to max-age zero
   * and raise a deprecation on the way.
   *
   * Covers: it collects both an element's own cacheability and its access
   * result's.
   */
  public function testItCollectsElementAndAccessResultCacheability(): void {
    $collection = new ToolbarItemCollection('horizontal');

    // A collection given nothing carries nothing, and is cacheable forever.
    $this->assertSame([], $collection->getCacheContexts());
    $this->assertSame([], $collection->getCacheTags());
    $this->assertSame(Cache::PERMANENT, $collection->getCacheMaxAge());

    // An element with its own cacheability and a boolean access answer. The
    // boolean carries nothing to fold, and must not zero the max-age.
    $plain = $this->element('plain');
    $plain->addCacheContexts(['languages:language_interface']);
    $plain->addCacheTags(['neo_toolbar_test']);
    $plain->mergeCacheMaxAge(600);
    $collection->add($plain);

    $this->assertSame(['languages:language_interface'], $collection->getCacheContexts());
    $this->assertSame(['neo_toolbar_test'], $collection->getCacheTags());
    $this->assertSame(600, $collection->getCacheMaxAge());

    // An element whose access is forbidden: it will never be rendered, and its
    // access cacheability arrives here all the same.
    $hidden = $this->element('hidden');
    $hidden->addCacheContexts(['theme']);
    $hidden->addCacheTags(['config:neo_toolbar.toolbar.default']);
    $hidden->setAccess(
      AccessResult::forbidden()
        ->addCacheContexts(['user.permissions'])
        ->addCacheTags(['user_role_list'])
        ->setCacheMaxAge(60)
    );
    $collection->add($hidden);

    $this->assertFalse($hidden->isAccessible());
    $this->assertEqualsCanonicalizing(
      ['languages:language_interface', 'theme', 'user.permissions'],
      $collection->getCacheContexts()
    );
    $this->assertEqualsCanonicalizing(
      ['config:neo_toolbar.toolbar.default', 'neo_toolbar_test', 'user_role_list'],
      $collection->getCacheTags()
    );
    // The shortest max-age of everything folded in wins.
    $this->assertSame(60, $collection->getCacheMaxAge());

    // The fold happens at `add()`, so an access result set afterwards is not
    // collected. Callers set access on the element before handing it over.
    $late = $this->element('late');
    $collection->add($late);
    $late->setAccess(AccessResult::allowed()->addCacheContexts(['url.path']));
    $this->assertNotContains('url.path', $collection->getCacheContexts());
  }

  /**
   * The collection's style is a default, not an override.
   *
   * A toolbar item declares one style for the set of elements it contributes,
   * and pushing it down here is what saves every plugin from setting it on
   * each element it builds. An element that forced its own style has said it
   * is a different thing — a modal trigger inside a grid, say — and keeps it.
   *
   * The push is a mutation of the element, not of the render array it
   * produces: the element is left carrying the collection's style afterwards,
   * which is what a second `toRenderable()` answers.
   *
   * Covers: it pushes its style onto elements that did not force one, and
   * leaves forced ones alone.
   */
  public function testStyleIsPushedOntoElementsThatDidNotForceOne(): void {
    $collection = new ToolbarItemCollection('horizontal', 'flyOut', 5);

    // Never told about a style: it is carrying the element default.
    $untouched = $this->element('untouched');
    // Told about one, but not forced, so the collection still wins.
    $unforced = $this->element('unforced');
    $unforced->setStyle('grid');
    // Forced, so the collection leaves it alone.
    $forced = $this->element('forced');
    $forced->setStyle('modal', TRUE);

    $collection->add($untouched)->add($unforced)->add($forced);

    $this->assertSame('default', $untouched->getStyle());
    $this->assertSame('grid', $unforced->getStyle());
    $this->assertTrue($forced->isStyleForced());

    $build = $collection->toRenderable();

    // The wrapper carries the collection's own three properties.
    $this->assertSame('neo_toolbar_item', $build['#theme']);
    $this->assertSame('horizontal', $build['#alignment']);
    $this->assertSame('fly_out', $build['#style']);
    $this->assertSame(5, $build['#weight']);

    // The snake-cased style, not the `flyOut` the collection was handed.
    $this->assertSame(
      ['fly_out', 'fly_out', 'modal'],
      array_column($build['#elements'], '#style')
    );

    // The elements themselves were changed, not just their render arrays.
    $this->assertSame('fly_out', $untouched->getStyle());
    $this->assertSame('fly_out', $unforced->getStyle());
    $this->assertSame('modal', $forced->getStyle());

    // Pushing a style down does not make it forced, so a later style still
    // reaches the same two elements.
    $collection->setStyle('localTasks');
    $this->assertSame(
      ['local_tasks', 'local_tasks', 'modal'],
      array_column($collection->toRenderable()['#elements'], '#style')
    );
  }

  /**
   * Nothing accessible means no wrapper, not an empty one.
   *
   * The theme hook the collection would otherwise emit wraps its elements in
   * markup of its own, so answering a wrapper with no elements inside it would
   * render a visible gap in the toolbar rail where an item the user cannot see
   * used to be. The guard is the same `accessible()` filter `isEmpty()` reads,
   * so the two answers cannot disagree.
   *
   * What is returned is the empty array exactly — no `#cache`, no `#theme`.
   * The collection's cacheability, which `add()` was careful to collect, is
   * therefore the caller's to read off the collection and attach; it does not
   * travel out through this array.
   *
   * Covers: it answers an empty render array when nothing in it is accessible.
   */
  public function testItAnswersEmptyRenderArrayWhenNothingIsAccessible(): void {
    // A collection with nothing in it at all.
    $empty = new ToolbarItemCollection('vertical', 'default');
    $this->assertSame([], array_keys($empty->toRenderable()));
    $this->assertSame([], $empty->toRenderable());

    // A collection holding two elements, neither of them accessible.
    $collection = new ToolbarItemCollection('vertical', 'default', 3);
    $forbidden = $this->element('forbidden', 'vertical');
    $forbidden->setAccess(AccessResult::forbidden());
    $boolean = $this->element('boolean', 'vertical');
    $boolean->setAccess(FALSE);
    $collection->add($forbidden)->add($boolean);

    $this->assertCount(2, $collection->all());
    // Not a wrapper with nothing inside it — no keys at all.
    $this->assertSame([], array_keys($collection->toRenderable()));
    $this->assertSame([], $collection->toRenderable());

    // One accessible element brings the whole wrapper back, carrying only
    // that element.
    $visible = $this->element('visible', 'vertical');
    $collection->add($visible);

    $build = $collection->toRenderable();
    $this->assertSame('neo_toolbar_item', $build['#theme']);
    $this->assertSame('vertical', $build['#alignment']);
    $this->assertSame('default', $build['#style']);
    $this->assertSame(3, $build['#weight']);
    $this->assertSame(['visible'], array_column($build['#elements'], '#id'));

    // The inaccessible elements were dropped here rather than left to the
    // renderer, so nothing carries their `#access` answer downstream.
    $this->assertCount(1, $build['#elements']);
  }

  /**
   * Builds an element for the collection to hold.
   *
   * @param string $id
   *   The element id, which is also its title and what `remove()` matches on.
   * @param string $alignment
   *   The element alignment.
   *
   * @return \Drupal\neo_toolbar\ToolbarItemElement
   *   The element.
   */
  private function element(string $id, string $alignment = 'horizontal'): ToolbarItemElement {
    return new ToolbarItemElement($id, $id, $alignment);
  }

  /**
   * Reduces elements to their ids, so an order assertion reads as one line.
   *
   * `array_map()` keeps the keys of a single array, so what comes back also
   * says where in the collection each element sits.
   *
   * @param \Drupal\neo_toolbar\ToolbarItemElement[] $elements
   *   The elements.
   *
   * @return string[]
   *   The element ids, keyed as the elements were.
   */
  private function ids(array $elements): array {
    return array_map(static fn (ToolbarItemElement $element) => $element->id(), $elements);
  }

}
