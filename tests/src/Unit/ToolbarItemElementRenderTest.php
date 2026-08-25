<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_toolbar\Unit;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Template\Attribute;
use Drupal\neo_settings\Plugin\SettingsInterface;
use Drupal\neo_settings\SettingsRepositoryInterface;
use Drupal\neo_toolbar\ToolbarItemElement;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Characterises the element's render array.
 *
 * `ToolbarItemElement::toRenderable()` is the only public reader of a 946-line
 * builder, and the array it answers is the contract every one of the module's
 * eight Twig templates consumes. Nothing else in `neo_toolbar` decides what a
 * toolbar item looks like.
 *
 * What is pinned here is the builder's own output: the defaults an untouched
 * element carries, the one rule in the method that is not a plain accessor,
 * the payload keys, the access result and the cacheability it folds in, the
 * children it delegates to an element collection, and the tooltip it applies
 * to its own attribute bag.
 *
 * Three deliberate boundaries.
 *
 * The five attribute bags are `ToolbarItemElementAttributesTest`'s subject and
 * are not re-covered here. What this class asserts about them is the thing
 * that test cannot see from inside one bag: that the render array carries all
 * five, under five distinct keys, as the element's own live objects rather
 * than copies. That identity is load-bearing — `toRenderable()` writes the
 * tooltip into `$this->attributes` *after* `$build['#attributes']` has already
 * been assigned, and only reference semantics make that reach the template.
 *
 * The modal branch is a kernel test, `ToolbarItemElementModalTest`, because
 * `setModal()` constructs a `neo_modal` `Modal` whose constructor reads a
 * `neo_settings` repository out of the container.
 *
 * `setDynamicIcon()` has no criterion at all. It resolves an icon through
 * `neo_icon`'s repository off the current route object, which is a `neo_icon`
 * seam wearing a toolbar method's clothes.
 *
 * The class stubs a two-service container rather than moving to the kernel
 * tier. `Cache::mergeContexts()` asserts its tokens through
 * `cache_contexts_manager`, so any element told about a cache context needs
 * one; and every element whose title is hidden builds a `Tooltip`, whose
 * `getAttributes()` compares the placement it was given against
 * `neo_tooltip.settings`. Neither service decides anything this class asserts
 * — they are what lets the builder run at all, which is exactly the case a
 * stub container is for.
 */
#[Group('neo_toolbar')]
final class ToolbarItemElementRenderTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // `Cache::mergeContexts()` asserts every token it is handed.
    $cacheContexts = $this->createMock(CacheContextsManager::class);
    $cacheContexts->method('assertValidTokens')->willReturn(TRUE);

    // `Tooltip::getAttributes()` omits the placement, animation and trigger it
    // was given whenever they match the site's configured defaults. The stub
    // answers values none of the tooltips here asks for, so what reaches the
    // attribute bag is what `toRenderable()` set rather than an artefact of
    // this site's saved `neo_tooltip` settings.
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
   * An untouched element answers a span, the default style and access.
   *
   * These four are the floor every template renders against when a plugin sets
   * nothing: the tag it wraps in, the style that picks the variant, the
   * alignment the constructor was handed, and an access answer that is a plain
   * `TRUE` rather than an `AccessResult`. The style is also not forced, which
   * is what lets a parent collection push its own style down — see the
   * children criterion.
   *
   * Covers: it carries its defaults — a span tag, the default style, and
   * access allowed.
   */
  public function testDefaultsAreSpanTagDefaultStyleAndAccessAllowed(): void {
    $element = new ToolbarItemElement('neo_toolbar_test', 'Test item', 'horizontal');

    $this->assertSame('span', $element->getTag());
    $this->assertSame('default', $element->getStyle());
    $this->assertFalse($element->isStyleForced());
    $this->assertSame('horizontal', $element->getAlignment());
    $this->assertTrue($element->getAccess());
    $this->assertTrue($element->isAccessible());

    $build = $element->toRenderable();

    $this->assertSame('neo_toolbar_element', $build['#theme']);
    $this->assertSame('neo_toolbar_test', $build['#id']);
    $this->assertSame('span', $build['#tag']);
    $this->assertSame('horizontal', $build['#alignment']);
    $this->assertSame('default', $build['#style']);
    $this->assertTrue($build['#access']);
    $this->assertSame(0, $build['#weight']);
    $this->assertSame('', $build['#icon']);
    $this->assertSame('', $build['#image']);
    $this->assertNull($build['#image_size']);
    $this->assertNull($build['#badge']);
    $this->assertSame([], $build['#attached']);
    $this->assertSame([
      'contexts' => [],
      'tags' => [],
      'max-age' => Cache::PERMANENT,
    ], $build['#cache']);

    // Nothing was added, so neither of the two conditional keys is present.
    $this->assertArrayNotHasKey('#children', $build);
    $this->assertArrayNotHasKey('#after', $build);
  }

  /**
   * The one rule in the method that is not a plain accessor.
   *
   * Every other `#` key is a getter's answer. This one is a correction: a
   * horizontal element with neither an icon nor an image has nothing to show
   * but its title, so the title comes back on whatever the caller asked for.
   * Without it a horizontal item that failed to resolve an icon would render
   * as an empty box.
   *
   * The correction is scoped twice over — it applies to `horizontal` only, and
   * only while both the icon and the image are empty. Vertical elements are
   * left hidden, which is what the vertical toolbar's icon-only rail depends
   * on, and a horizontal element with either an icon or an image keeps the
   * caller's answer.
   *
   * Covers: it shows a horizontal element's title when there is neither an
   * icon nor an image, even when the title was hidden.
   */
  public function testHorizontalTitleShowsWithoutIconOrImageEvenWhenHidden(): void {
    // The rule: hidden, horizontal, and nothing else to show.
    $element = new ToolbarItemElement('neo_toolbar_test', 'Test item', 'horizontal');
    $element->showTitle(FALSE);
    $this->assertSame('Test item', $element->toRenderable()['#title']);

    // An icon is something else to show, so the caller's answer stands.
    $withIcon = new ToolbarItemElement('neo_toolbar_test', 'Test item', 'horizontal');
    $withIcon->showTitle(FALSE)->setIcon('star');
    $this->assertSame('', $withIcon->toRenderable()['#title']);

    // So is an image.
    $withImage = new ToolbarItemElement('neo_toolbar_test', 'Test item', 'horizontal');
    $withImage->showTitle(FALSE)->setImage('public://avatar.png');
    $this->assertSame('', $withImage->toRenderable()['#title']);

    // Vertical is outside the rule entirely: `setAlignment()` hid the title,
    // and nothing brings it back.
    $vertical = new ToolbarItemElement('neo_toolbar_test', 'Test item', 'vertical');
    $this->assertSame('', $vertical->toRenderable()['#title']);

    // And a horizontal element that was never hidden is unaffected either way.
    $shown = new ToolbarItemElement('neo_toolbar_test', 'Test item', 'horizontal');
    $this->assertSame('Test item', $shown->toRenderable()['#title']);
    $shown->setIcon('star');
    $this->assertSame('Test item', $shown->toRenderable()['#title']);
  }

  /**
   * The payload keys, and the five bags arriving by reference.
   *
   * `ToolbarItemElementAttributesTest` owns what each bag stores; what belongs
   * here is that all five reach the array under five distinct keys and are the
   * element's own objects. A builder that handed out copies would pass every
   * criterion in that class and still lose the tooltip attributes, which are
   * written into `$this->attributes` after `$build` has been assembled.
   *
   * Covers: it emits the five attribute bags, the badge, the image and its
   * size, the weight and the attachments.
   */
  public function testItEmitsTheAttributeBagsBadgeImageWeightAndAttachments(): void {
    $element = new ToolbarItemElement('neo_toolbar_test', 'Test item', 'horizontal');
    $element->setIcon('star')
      ->setImage('public://avatar.png')
      ->setImageSize(24, 32)
      ->setBadge(7)
      ->setWeight(-5)
      ->addLibrary('neo_toolbar/toolbar')
      ->addLibrary('neo_toolbar/toolbar.edit');

    $build = $element->toRenderable();

    $this->assertSame('star', $build['#icon']);
    $this->assertSame('public://avatar.png', $build['#image']);
    $this->assertSame(['width' => 24, 'height' => 32], $build['#image_size']);
    $this->assertSame(7, $build['#badge']);
    $this->assertSame(-5, $build['#weight']);
    $this->assertSame([
      'library' => [
        'neo_toolbar/toolbar',
        'neo_toolbar/toolbar.edit',
      ],
    ], $build['#attached']);

    // A width on its own squares itself.
    $square = new ToolbarItemElement('neo_toolbar_test', 'Test item', 'horizontal');
    $square->setImageSize(24);
    $this->assertSame(['width' => 24, 'height' => 24], $square->toRenderable()['#image_size']);

    // Five keys, five distinct objects, none shared with another.
    $keys = [
      '#attributes',
      '#title_attributes',
      '#icon_attributes',
      '#image_attributes',
      '#badge_attributes',
    ];
    $bags = [];
    foreach ($keys as $key) {
      $this->assertInstanceOf(Attribute::class, $build[$key], $key . ' is an attribute bag.');
      foreach ($bags as $seenKey => $seen) {
        $this->assertNotSame($seen, $build[$key], $key . ' is not ' . $seenKey . '.');
      }
      $bags[$key] = $build[$key];
    }

    // And they are the element's own, not copies: a write after the array was
    // built is visible through it, which is how the tooltip reaches the
    // template.
    $element->addClass('written-after-the-build');
    $this->assertSame(
      ['written-after-the-build'],
      $build['#attributes']->toArray()['class'] ?? [],
      'The render array carries the live element bag.'
    );
    $rebuilt = $element->toRenderable();
    foreach ($bags as $key => $bag) {
      $this->assertSame($bag, $rebuilt[$key], $key . ' is the same object on a second build.');
    }
  }

  /**
   * Access is answered twice: as `#access`, and as cacheability.
   *
   * The render array's `#access` is what drops the element, and the element's
   * `#cache` is what decides how long that answer may be reused. Folding the
   * access result's own cacheability into the element is what keeps a
   * permission-derived answer from outliving the permission — and it happens
   * for a forbidden result exactly as for an allowed one, which matters most
   * there, because a forbidden element renders nothing and would otherwise
   * take its contexts with it.
   *
   * The fold is guarded on `AccessResult`, the class, not on
   * `AccessResultInterface`. A plain boolean carries no cacheability and is
   * passed straight through.
   *
   * Covers: it passes an access result through as #access and folds it into
   * its own cacheability.
   */
  public function testAccessResultIsPassedThroughAndFoldedIntoCacheability(): void {
    $access = AccessResult::allowed()
      ->addCacheContexts(['user.permissions'])
      ->addCacheTags(['config:neo_toolbar.toolbar.default'])
      ->setCacheMaxAge(60);

    $element = new ToolbarItemElement('neo_toolbar_test', 'Test item', 'horizontal');
    // Cacheability the element already carried, to prove a merge rather than a
    // replacement.
    $element->addCacheContexts(['languages:language_interface']);
    $element->addCacheTags(['neo_toolbar_test']);
    $element->setAccess($access);

    $build = $element->toRenderable();

    $this->assertSame($access, $build['#access']);
    $this->assertTrue($element->isAccessible());
    $this->assertEqualsCanonicalizing(
      ['languages:language_interface', 'user.permissions'],
      $build['#cache']['contexts']
    );
    $this->assertEqualsCanonicalizing(
      ['config:neo_toolbar.toolbar.default', 'neo_toolbar_test'],
      $build['#cache']['tags']
    );
    $this->assertSame(60, $build['#cache']['max-age']);

    // A forbidden result is folded on the same terms, and still answers as
    // `#access` so the renderer drops the element.
    $forbidden = AccessResult::forbidden()->addCacheContexts(['user.roles']);
    $hidden = new ToolbarItemElement('neo_toolbar_test', 'Test item', 'horizontal');
    $hidden->setAccess($forbidden);

    $hiddenBuild = $hidden->toRenderable();
    $this->assertSame($forbidden, $hiddenBuild['#access']);
    $this->assertFalse($hidden->isAccessible());
    $this->assertSame(['user.roles'], $hiddenBuild['#cache']['contexts']);

    // A boolean has nothing to fold and is passed through as it stands.
    $boolean = new ToolbarItemElement('neo_toolbar_test', 'Test item', 'horizontal');
    $boolean->setAccess(FALSE);

    $booleanBuild = $boolean->toRenderable();
    $this->assertFalse($booleanBuild['#access']);
    $this->assertFalse($boolean->isAccessible());
    $this->assertSame([], $booleanBuild['#cache']['contexts']);
    $this->assertSame([], $booleanBuild['#cache']['tags']);
    $this->assertSame(Cache::PERMANENT, $booleanBuild['#cache']['max-age']);
  }

  /**
   * Children go through a collection, never inline.
   *
   * `toRenderable()` builds a `ToolbarItemCollection` for its children and
   * hands it the parent's alignment and the children style, so the whole
   * collection contract — the `neo_toolbar_item` theme hook, the accessible
   * filter and the style push-down — applies to a nested list exactly as it
   * does to a toolbar region's top level. `ToolbarItemCollectionTest` owns
   * that contract; what is pinned here is the delegation and the two arguments
   * it is given.
   *
   * The children style is its own setting with a fallback, not the element's
   * style: a flyout panel is styled independently of the trigger that opens
   * it, and `getChildrenStyle()` answers the element's own style only when
   * nothing was set.
   *
   * Covers: it renders its children through an element collection, in the
   * children style when one was set.
   */
  public function testChildrenAreRenderedThroughAnElementCollection(): void {
    $element = new ToolbarItemElement('neo_toolbar_test', 'Parent', 'horizontal');
    $element->setStyle('trigger');
    $element->setChildrenStyle('flyout');

    $first = new ToolbarItemElement('child_one', 'First', 'horizontal');
    $second = new ToolbarItemElement('child_two', 'Second', 'horizontal');
    // A forced style is the child's own answer and survives the push-down.
    $second->setStyle('forced', TRUE);
    // An inaccessible child never reaches the array — the collection filters
    // before it renders.
    $third = new ToolbarItemElement('child_three', 'Third', 'horizontal');
    $third->setAccess(FALSE);

    $element->addChild($first)->addChild($second)->addChild($third);

    $this->assertSame([$first, $second, $third], $element->getChildren());
    $this->assertSame('flyout', $element->getChildrenStyle());

    $build = $element->toRenderable();

    $this->assertArrayHasKey('#children', $build);
    $children = $build['#children'];
    $this->assertSame('neo_toolbar_item', $children['#theme']);
    $this->assertSame('horizontal', $children['#alignment']);
    $this->assertSame('flyout', $children['#style']);
    $this->assertSame(0, $children['#weight']);

    $this->assertCount(2, $children['#elements']);
    $this->assertSame('child_one', $children['#elements'][0]['#id']);
    $this->assertSame('child_two', $children['#elements'][1]['#id']);
    // The collection pushed its style onto the child that did not force one.
    $this->assertSame('flyout', $children['#elements'][0]['#style']);
    $this->assertSame('forced', $children['#elements'][1]['#style']);
    // The parent's own style is untouched by the children's.
    $this->assertSame('trigger', $build['#style']);

    // With no children style set, the element's own style is the fallback.
    $fallback = new ToolbarItemElement('neo_toolbar_test', 'Parent', 'vertical');
    $fallback->setStyle('trigger');
    $fallback->addChild(new ToolbarItemElement('child_one', 'First', 'vertical'));

    $this->assertSame('trigger', $fallback->getChildrenStyle());
    $fallbackBuild = $fallback->toRenderable();
    $this->assertSame('trigger', $fallbackBuild['#children']['#style']);
    $this->assertSame('vertical', $fallbackBuild['#children']['#alignment']);
  }

  /**
   * The tooltip is the hidden title's replacement, not an addition.
   *
   * Both halves of the guard matter. A tooltip on a visible title would repeat
   * it, so the tooltip applies only where the title was suppressed; and
   * `setAlignment()` sets the two flags in opposition — vertical hides the
   * title and turns tooltips on, horizontal shows it and turns them off — so
   * the default for each alignment already satisfies exactly one half.
   *
   * The placement follows the rail the item sits on: a vertical toolbar's
   * tooltip opens to the right of the icon, a horizontal one below it.
   *
   * What the tooltip writes goes into the element's own attribute bag, which
   * is the reason the payload criterion pins that bag as a live object.
   *
   * Covers: it applies a tooltip only when the title is hidden and tooltips
   * are on, and attaches the tooltip library.
   */
  public function testTooltipAppliesOnlyWhenTitleIsHiddenAndTooltipsAreOn(): void {
    // Vertical: the constructor hid the title and turned tooltips on.
    $vertical = new ToolbarItemElement('neo_toolbar_test', 'Test item', 'vertical');
    $build = $vertical->toRenderable();

    $this->assertSame('', $build['#title']);
    $attributes = $build['#attributes']->toArray();
    $this->assertSame(['use-neo-tooltip'], $attributes['class']);
    $this->assertSame('right', $attributes['data-tippy-placement']);
    $this->assertSame('Test item', $attributes['data-tippy-content']);
    $this->assertSame(['library' => ['neo_tooltip/tooltip']], $build['#attached']);

    // Horizontal, hidden by the caller, with an icon so the title rule does
    // not bring it back: tooltips are off by default for this alignment, so
    // the caller has to turn them on.
    $horizontal = new ToolbarItemElement('neo_toolbar_test', 'Test item', 'horizontal');
    $horizontal->setIcon('star')->showTitle(FALSE);
    $withoutTooltip = $horizontal->toRenderable();
    $this->assertSame([], $withoutTooltip['#attributes']->toArray());
    $this->assertSame([], $withoutTooltip['#attached']);

    $horizontal->showTooltip(TRUE);
    $withTooltip = $horizontal->toRenderable();
    $this->assertSame(
      'bottom',
      $withTooltip['#attributes']->toArray()['data-tippy-placement'],
      'A horizontal tooltip opens below the item.'
    );
    $this->assertSame(['library' => ['neo_tooltip/tooltip']], $withTooltip['#attached']);

    // Tooltips on but the title visible: nothing to replace, nothing applied.
    $visible = new ToolbarItemElement('neo_toolbar_test', 'Test item', 'vertical');
    $visible->showTitle(TRUE);
    $visibleBuild = $visible->toRenderable();
    $this->assertSame('Test item', $visibleBuild['#title']);
    $this->assertSame([], $visibleBuild['#attributes']->toArray());
    $this->assertSame([], $visibleBuild['#attached']);

    // And a horizontal element with neither icon nor image is never a tooltip
    // candidate, because the title rule has already turned its title back on.
    $corrected = new ToolbarItemElement('neo_toolbar_test', 'Test item', 'horizontal');
    $corrected->showTitle(FALSE)->showTooltip(TRUE);
    $correctedBuild = $corrected->toRenderable();
    $this->assertSame('Test item', $correctedBuild['#title']);
    $this->assertSame([], $correctedBuild['#attributes']->toArray());
    $this->assertSame([], $correctedBuild['#attached']);
  }

}
