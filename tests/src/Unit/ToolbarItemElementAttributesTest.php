<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_toolbar\Unit;

use Drupal\Core\Template\Attribute;
use Drupal\neo_toolbar\ToolbarItemElement;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Characterises the element's five attribute bags.
 *
 * `ToolbarItemElement` carries five `Attribute` objects — element, title, icon,
 * image and badge — and fifteen methods that write to them: an add-class, a
 * set-attribute and a merge for each. The five trios are hand-written copies of
 * one another, differing only in the property they touch, and they are public
 * API: `neo_favicon`'s `Favicon` plugin calls `addClass()` and
 * `addImageClass()` from another package.
 *
 * This class is the net under the candidate that wants to collapse all five
 * trios behind one core under expand–contract. Every criterion here stays green
 * through that refactor by construction, because the forwarders keep their
 * signatures; a criterion that goes red is that plan's own answer that it broke
 * the public API.
 *
 * Two things about how the bags are read here.
 *
 * The class exposes no getter for any bag, so `toRenderable()` is the only
 * public reader, and its five `#*_attributes` keys are exactly the contract the
 * eight Twig templates consume. Every element is therefore built `horizontal`.
 * That is not cosmetic: `setAlignment('vertical')` turns the title off and the
 * tooltip on, and `toRenderable()` then applies a `Tooltip` to the *element*
 * bag, which would put a second writer inside the seam this class is pinning.
 *
 * Assertions stop at each bag's stored value rather than its rendered string
 * wherever a class list is nested, because rendering one raises "Array to
 * string conversion" and this site's `phpunit.xml` sets `failOnWarning`. The
 * nesting is not incidental — see the shapes criterion.
 */
#[Group('neo_toolbar')]
final class ToolbarItemElementAttributesTest extends UnitTestCase {

  /**
   * The five bags, their three methods and their render-array key.
   *
   * Written out rather than derived, so that a method quietly disappearing
   * behind a forwarder is a failure here rather than a smaller loop.
   *
   * @var array<string, array<string, string>>
   */
  private const BAGS = [
    'element' => [
      'add' => 'addClass',
      'set' => 'setAttribute',
      'merge' => 'mergeAttributes',
      'key' => '#attributes',
    ],
    'title' => [
      'add' => 'addTitleClass',
      'set' => 'setTitleAttribute',
      'merge' => 'mergeTitleAttributes',
      'key' => '#title_attributes',
    ],
    'icon' => [
      'add' => 'addIconClass',
      'set' => 'setIconAttribute',
      'merge' => 'mergeIconAttributes',
      'key' => '#icon_attributes',
    ],
    'image' => [
      'add' => 'addImageClass',
      'set' => 'setImageAttribute',
      'merge' => 'mergeImageAttributes',
      'key' => '#image_attributes',
    ],
    'badge' => [
      'add' => 'addBadgeClass',
      'set' => 'setBadgeAttribute',
      'merge' => 'mergeBadgeAttributes',
      'key' => '#badge_attributes',
    ],
  ];

  /**
   * Each bag answers a class added through its own add-class method.
   *
   * Covers: it adds a class to each of the five bags.
   */
  public function testItAddsOneClassToEachOfTheFiveBags(): void {
    foreach (self::BAGS as $name => $spec) {
      $element = $this->element();
      $element->{$spec['add']}('neo-toolbar-test');

      $attributes = $this->bags($element)[$name];
      $this->assertSame(
        ['neo-toolbar-test'],
        $attributes->toArray()['class'] ?? [],
        sprintf('%s() wrote a class into the %s bag.', $spec['add'], $name)
      );
      $this->assertSame(' class="neo-toolbar-test"', (string) $attributes);
    }
  }

  /**
   * The variadic capture decides what a caller may pass.
   *
   * Every add-class method reads `func_get_args()` and hands the whole array to
   * `Attribute::addClass()` as a single argument, so what a caller passes is
   * wrapped exactly once. A bare string and several strings therefore arrive as
   * a flat class list — which is every call site in `neo_toolbar` and in
   * `neo_favicon` — while a list arrives one level too deep and a nested list
   * two.
   *
   * The docblocks say `@param string|array ...`, so the array shapes are
   * documented; they do not work. A list of two classes is stored as one
   * element that is itself an array and renders as `class="Array"`.
   * `LocalActions` already works around it, exploding a class string and
   * calling `addClass()` once per class rather than passing the list. That is a
   * live defect, characterised here rather than repaired: this plan changes no
   * production code, and a flattening fix belongs in the candidate that
   * collapses the five trios.
   *
   * Covers: it accepts a bare string, several arguments, a list and a nested
   * list of classes.
   */
  public function testItAcceptsBareStringSeveralArgumentsListAndNestedList(): void {
    $shapes = [
      'a bare string' => [
        ['alpha'],
        ['alpha'],
      ],
      'several arguments' => [
        ['alpha', 'beta'],
        ['alpha', 'beta'],
      ],
      'a list' => [
        [['alpha', 'beta']],
        [['alpha', 'beta']],
      ],
      'a nested list' => [
        [[['alpha', 'beta'], ['gamma']]],
        [[['alpha', 'beta'], ['gamma']]],
      ],
    ];

    foreach (self::BAGS as $name => $spec) {
      foreach ($shapes as $shape => [$arguments, $expected]) {
        $element = $this->element();
        $element->{$spec['add']}(...$arguments);

        $this->assertSame(
          $expected,
          $this->bags($element)[$name]->toArray()['class'] ?? [],
          sprintf('%s() accepted %s.', $spec['add'], $shape)
        );
      }
    }
  }

  /**
   * The `if ($args)` guard is what keeps an empty call out of the bag.
   *
   * Without it `Attribute::addClass([])` would set an empty `class` key, and
   * every element in the toolbar would carry one.
   *
   * Covers: it makes no change when called with no arguments.
   */
  public function testItMakesNoChangeWhenCalledWithNoArguments(): void {
    foreach (self::BAGS as $name => $spec) {
      $element = $this->element();
      $element->{$spec['add']}();

      $attributes = $this->bags($element)[$name];
      $this->assertSame(
        [],
        $attributes->toArray(),
        sprintf('%s() with no arguments left the %s bag empty.', $spec['add'], $name)
      );
      $this->assertSame('', (string) $attributes);
    }
  }

  /**
   * Each bag answers a named attribute set through its own setter.
   *
   * Covers: it sets a single named attribute on each of the five bags.
   */
  public function testItSetsOneNamedAttributeOnEachOfTheFiveBags(): void {
    foreach (self::BAGS as $name => $spec) {
      $element = $this->element();
      $element->{$spec['set']}('data-neo-toolbar-test', 'pinned');

      $attributes = $this->bags($element)[$name];
      $this->assertSame(
        ['data-neo-toolbar-test' => 'pinned'],
        $attributes->toArray(),
        sprintf('%s() wrote one attribute into the %s bag.', $spec['set'], $name)
      );
      $this->assertSame(' data-neo-toolbar-test="pinned"', (string) $attributes);
    }
  }

  /**
   * Both halves of the `array|Attribute` union reach the bag.
   *
   * The array half is the one with a conversion behind it — each merge method
   * wraps an array in a new `Attribute` before calling `Attribute::merge()`,
   * whose parameter is typed. The class lists of the two merges are appended
   * rather than replaced, because `NestedArray::mergeDeep()` appends integer
   * keys.
   *
   * Covers: it merges both an array and an Attribute object into each of the
   * five bags.
   */
  public function testItMergesBothAnArrayAndAnAttributeObjectIntoEachOfTheFiveBags(): void {
    foreach (self::BAGS as $name => $spec) {
      $element = $this->element();
      $element->{$spec['merge']}([
        'class' => ['from-array'],
        'data-from-array' => 'yes',
      ]);
      $element->{$spec['merge']}(new Attribute([
        'class' => ['from-object'],
        'data-from-object' => 'yes',
      ]));

      $this->assertSame(
        [
          'class' => ['from-array', 'from-object'],
          'data-from-array' => 'yes',
          'data-from-object' => 'yes',
        ],
        $this->bags($element)[$name]->toArray(),
        sprintf('%s() merged an array and an Attribute into the %s bag.', $spec['merge'], $name)
      );
    }
  }

  /**
   * The five bags are five objects, not one wearing five names.
   *
   * This is the property the collapse is most likely to break: a single core
   * that resolves the wrong property, or five forwarders sharing one bag, would
   * pass every other criterion in this class and fail only this one.
   *
   * Covers: a write to one bag is not visible in any of the other four.
   */
  public function testWriteToOneBagIsNotVisibleInAnyOfTheOtherFour(): void {
    foreach (self::BAGS as $name => $spec) {
      $element = $this->element();
      $element->{$spec['add']}('only-' . $name);
      $element->{$spec['set']}('data-only', $name);
      $element->{$spec['merge']}(['data-merged' => $name]);

      $bags = $this->bags($element);
      $this->assertSame(
        [
          'class' => ['only-' . $name],
          'data-only' => $name,
          'data-merged' => $name,
        ],
        $bags[$name]->toArray(),
        sprintf('All three writes landed in the %s bag.', $name)
      );

      foreach ($bags as $other => $attributes) {
        if ($other === $name) {
          continue;
        }
        $this->assertSame(
          [],
          $attributes->toArray(),
          sprintf('Writing to the %s bag left the %s bag alone.', $name, $other)
        );
      }
    }
  }

  /**
   * Fifteen methods, fifteen `return $this`.
   *
   * `Favicon` chains off `addClass()` today, so the identity is the API and not
   * a convenience. The count is asserted alongside it, because a forwarder that
   * is simply missing would otherwise shrink the loop rather than fail it.
   *
   * Covers: every one of the fifteen methods answers the element itself, so
   * calls chain.
   */
  public function testEveryOneOfTheFifteenMethodsAnswersTheElementItself(): void {
    $element = $this->element();
    $methods = 0;

    foreach (self::BAGS as $name => $spec) {
      $this->assertSame($element, $element->{$spec['add']}('chain-' . $name));
      $this->assertSame($element, $element->{$spec['set']}('data-chain', $name));
      $this->assertSame($element, $element->{$spec['merge']}(['data-chain-merged' => $name]));
      $methods += 3;
    }

    $this->assertSame(15, $methods);

    // One element, chained through all fifteen, still carries all five bags.
    $bags = $this->bags($element);
    foreach (array_keys(self::BAGS) as $name) {
      $this->assertSame(
        [
          'class' => ['chain-' . $name],
          'data-chain' => $name,
          'data-chain-merged' => $name,
        ],
        $bags[$name]->toArray(),
        sprintf('The chain reached the %s bag.', $name)
      );
    }
  }

  /**
   * Builds an element whose only writer is the test.
   *
   * `horizontal` is deliberate — see the class docblock.
   *
   * @return \Drupal\neo_toolbar\ToolbarItemElement
   *   A toolbar item element with five empty attribute bags.
   */
  private function element(): ToolbarItemElement {
    return new ToolbarItemElement('neo_toolbar_test', 'Test item', 'horizontal');
  }

  /**
   * Reads the five bags back off the render array.
   *
   * @param \Drupal\neo_toolbar\ToolbarItemElement $element
   *   The element to read.
   *
   * @return array<string, \Drupal\Core\Template\Attribute>
   *   The five bags, keyed as in ::BAGS.
   */
  private function bags(ToolbarItemElement $element): array {
    $build = $element->toRenderable();
    $bags = [];
    foreach (self::BAGS as $name => $spec) {
      $this->assertArrayHasKey($spec['key'], $build);
      $this->assertInstanceOf(Attribute::class, $build[$spec['key']]);
      $bags[$name] = $build[$spec['key']];
    }
    return $bags;
  }

}
