<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_toolbar\Unit;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\neo_toolbar\Entity\Toolbar;
use Drupal\neo_toolbar\Plugin\ToolbarItem\Divider;
use Drupal\neo_toolbar\Plugin\ToolbarItem\Link;
use Drupal\neo_toolbar\ToolbarItemInterface;
use Drupal\neo_toolbar\ToolbarItemPluginBase;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Characterises the two container-free rules the toolbar's seams consume.
 *
 * Neither rule owns a seam of its own, and both are the answer some larger
 * seam asks for and then acts on. Pinning them here is what lets the kernel
 * tests above assert the pipeline and the repository rather than the rules
 * those two happen to call.
 *
 * The **sibling access** rule. `Divider::accessBySiblings()` is the module's
 * only non-trivial implementation of that method, and it is what drives the
 * item pipeline's sibling pass — `ItemPipelineRegionRulesTest` reaches it
 * through `Toolbar::getItems()`, which groups the surviving items by region,
 * walks each region in order and drops every item whose sibling answer is
 * forbidden. That test asserts the pass; this one asserts the rule underneath
 * it, so a change to either is a red in exactly one place.
 *
 * A divider exists to separate two things, so the rule refuses in the three
 * arrangements where there is nothing to separate: nothing before it, nothing
 * after it, or another divider on one side. Only the third refusal carries a
 * cacheable dependency, and it is always the **previous** item's, even when it
 * was the next item that was the divider — which is a characterisation, not a
 * defence. The fourth arrangement, an ordinary item on each side, is the one
 * the divider was drawn for and is allowed.
 *
 * The base plugin's own answer is a plain allow with no cacheability at all,
 * and every one of the module's other eleven toolbar item plugins inherits it
 * untouched. That is asserted twice over — once against the base class itself,
 * through an empty subclass, and once against a real plugin that does not
 * override the method — because "the divider is the only override" is the
 * property the pipeline's sibling pass is cheap on.
 *
 * The **sort comparator**. `Toolbar::sort()` is what `ToolbarRepository` sorts
 * a loaded set of toolbars with before taking the first one as the active
 * toolbar, so its three rules decide which toolbar a request renders. Status
 * separates enabled from disabled and outranks everything; weight orders what
 * is left; and `strcmp()` on the label has the last word, which makes the tie
 * break byte-ordered and therefore case-sensitive — an uppercase label sorts
 * ahead of a lowercase one. That is pinned as current behaviour.
 *
 * `ToolbarItem::sort()` is a byte-identical copy of the same comparator in the
 * other config entity class. This class asserts `Toolbar::sort()` and records
 * the duplicate rather than asserting it twice: a criterion green on both
 * would say nothing about which of the two a caller reached, and the
 * duplication itself is a finding for the backlog, not a behaviour to pin.
 *
 * Nothing here needs a container to decide anything. The one service stubbed
 * is `cache_contexts_manager`, because `Cache::mergeContexts()` asserts its
 * tokens through it when the divider's refusal folds a neighbour's contexts
 * into the access result.
 *
 * Two boundaries. The comparator is fed entity doubles rather than real
 * config entities, because `label()` on a real one resolves the label key
 * through the entity type manager and would put a container in the way of a
 * three-line comparison. And the pipeline-level consequences of both rules —
 * which items survive `getItems()` and which toolbar `getActive()` answers —
 * belong to `ItemPipelineRegionRulesTest` and `ActiveToolbarTest`.
 */
#[Group('neo_toolbar')]
final class SiblingAndSortRulesTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // `Cache::mergeContexts()` asserts every token it is handed, which the
    // divider's third refusal reaches when it folds in a neighbour.
    $cacheContexts = $this->createMock(CacheContextsManager::class);
    $cacheContexts->method('assertValidTokens')->willReturn(TRUE);

    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $cacheContexts);
    \Drupal::setContainer($container);
  }

  /**
   * A divider with nothing before it separates nothing.
   *
   * This is the first of the three refusals and the one the pipeline hits
   * most, because a region whose leading items all lost access promotes a
   * divider to the front. The refusal carries no cacheable dependency: there
   * was no neighbour to depend on.
   *
   * Covers: a divider with no previous item forbids itself.
   */
  public function testDividerWithNoPreviousItemForbidsItself(): void {
    $divider = $this->divider();

    $result = $divider->accessBySiblings(NULL, $this->item('link'));
    $this->assertTrue($result->isForbidden());
    $this->assertFalse($result->isAllowed());

    // Nothing was folded in, so the refusal is as cacheable as it can be.
    $this->assertSame([], $result->getCacheContexts());
    $this->assertSame([], $result->getCacheTags());
    $this->assertSame(Cache::PERMANENT, $result->getCacheMaxAge());

    // Both neighbours missing is the same answer, reached by this same branch
    // before the next one is ever consulted.
    $this->assertTrue($divider->accessBySiblings(NULL, NULL)->isForbidden());
  }

  /**
   * A divider with nothing after it separates nothing either.
   *
   * The mirror of the first refusal, and the arrangement a region ends in
   * when its trailing items lost access. It is a separate branch in the rule
   * rather than one test on both neighbours, which is why it is a separate
   * criterion.
   *
   * Covers: a divider with no next item forbids itself.
   */
  public function testDividerWithNoNextItemForbidsItself(): void {
    $result = $this->divider()->accessBySiblings($this->item('link'), NULL);

    $this->assertTrue($result->isForbidden());
    $this->assertFalse($result->isAllowed());
    $this->assertSame([], $result->getCacheContexts());
    $this->assertSame([], $result->getCacheTags());
    $this->assertSame(Cache::PERMANENT, $result->getCacheMaxAge());
  }

  /**
   * Two dividers in a row collapse to one, and the refusal is cacheable.
   *
   * The rule reads both neighbours but depends on only one: the cacheable
   * dependency added to the refusal is always `$previous`, whichever side the
   * offending divider was on. That asymmetry is pinned rather than defended —
   * an item's access answer varying with the neighbour it did not name is the
   * kind of thing a later refactor should have to state out loud.
   *
   * Covers: a divider beside another divider forbids itself, carrying the
   * neighbour's cacheability.
   */
  public function testDividerBesideAnotherDividerForbidsItselfCarryingNeighbourCacheability(): void {
    $neighbouringDivider = $this->item('divider', ['user.permissions'], ['config:neo_toolbar.neo_toolbar_item.rule'], 3600);
    $ordinary = $this->item('link', ['route'], ['config:neo_toolbar.neo_toolbar_item.help'], 600);

    // A divider whose previous neighbour is a divider.
    $result = $this->divider()->accessBySiblings($neighbouringDivider, $ordinary);
    $this->assertTrue($result->isForbidden());
    $this->assertSame(['user.permissions'], $result->getCacheContexts());
    $this->assertSame(['config:neo_toolbar.neo_toolbar_item.rule'], $result->getCacheTags());
    $this->assertSame(3600, $result->getCacheMaxAge());

    // A divider whose *next* neighbour is the divider is refused just the
    // same — and still carries the previous item's cacheability, not the
    // cacheability of the divider that triggered the refusal.
    $result = $this->divider()->accessBySiblings($ordinary, $neighbouringDivider);
    $this->assertTrue($result->isForbidden());
    $this->assertSame(['route'], $result->getCacheContexts());
    $this->assertSame(['config:neo_toolbar.neo_toolbar_item.help'], $result->getCacheTags());
    $this->assertSame(600, $result->getCacheMaxAge());
  }

  /**
   * The arrangement the divider was drawn for.
   *
   * Two ordinary items with a divider between them is the only shape the rule
   * allows, and the allow is unconditional: neither neighbour is folded into
   * the result, so a divider between two items is as cacheable as the items
   * around it already were.
   *
   * Covers: a divider between two ordinary items is allowed.
   */
  public function testDividerBetweenTwoOrdinaryItemsIsAllowed(): void {
    $result = $this->divider()->accessBySiblings(
      $this->item('link', ['route'], ['config:neo_toolbar.neo_toolbar_item.help'], 600),
      $this->item('user', ['user'], ['config:neo_toolbar.neo_toolbar_item.account'], 60),
    );

    $this->assertTrue($result->isAllowed());
    $this->assertFalse($result->isForbidden());
    $this->assertSame([], $result->getCacheContexts());
    $this->assertSame([], $result->getCacheTags());
    $this->assertSame(Cache::PERMANENT, $result->getCacheMaxAge());
  }

  /**
   * Every plugin but the divider answers a plain allow.
   *
   * The base class is abstract only so the plugin manager cannot instantiate
   * it; it implements every member of the interface, so an empty subclass is
   * the base's own answer with nothing in between. The answer ignores both
   * neighbours entirely — including two dividers, the arrangement the divider
   * itself refuses — and carries no cacheability, which is what makes the
   * pipeline's sibling pass free for the eleven plugins that do not override
   * it.
   *
   * `Link` is asserted alongside it as a real plugin that inherits the base
   * answer, because "the divider is the module's only override" is a property
   * of the module rather than of the base class.
   *
   * Covers: the base plugin's sibling access answer is a plain allow.
   */
  public function testBasePluginAnswersPlainAllow(): void {
    $transliteration = $this->createMock(TransliterationInterface::class);
    $base = new class([], 'neo_toolbar_base_probe', ['id' => 'neo_toolbar_base_probe'], $transliteration) extends ToolbarItemPluginBase {};

    $arrangements = [
      [NULL, NULL],
      [$this->item('divider'), NULL],
      [NULL, $this->item('divider')],
      [$this->item('divider'), $this->item('divider')],
      [$this->item('link'), $this->item('user')],
    ];
    foreach ($arrangements as [$previous, $next]) {
      $result = $base->accessBySiblings($previous, $next);
      $this->assertTrue($result->isAllowed());
      $this->assertFalse($result->isForbidden());
      $this->assertSame([], $result->getCacheContexts());
      $this->assertSame([], $result->getCacheTags());
      $this->assertSame(Cache::PERMANENT, $result->getCacheMaxAge());
    }

    // A real plugin inherits exactly that, dividers on both sides included.
    $link = new Link([], 'link', ['id' => 'link'], $transliteration);
    $this->assertTrue($link->accessBySiblings(NULL, NULL)->isAllowed());
    $this->assertTrue($link->accessBySiblings($this->item('divider'), $this->item('divider'))->isAllowed());
  }

  /**
   * The comparator that decides which toolbar is the active one.
   *
   * Three rules in order, and each one only speaks when the one above it is
   * silent. Status is a subtraction of the two booleans the other way round,
   * so an enabled toolbar leads a disabled one whatever their weights say.
   * Weight then orders what is left. The label has the last word through
   * `strcmp()`, which compares bytes rather than characters — so `Zulu` leads
   * `alpha`, and the tie break is case-sensitive. That is pinned as current
   * behaviour, not endorsed.
   *
   * The fixtures are arranged so that each rule contradicts the one below it:
   * the disabled toolbar has the lowest weight, and the lifted toolbar has the
   * label that would sort last. Any one rule going missing therefore reorders
   * the set.
   *
   * Covers: the sort comparator puts enabled before disabled, then orders by
   * weight, then by label.
   */
  public function testSortPutsEnabledBeforeDisabledThenWeightThenLabel(): void {
    $lifted = $this->toolbar(TRUE, -5, 'zeta');
    $upper = $this->toolbar(TRUE, 0, 'Zulu');
    $lower = $this->toolbar(TRUE, 0, 'alpha');
    $retired = $this->toolbar(FALSE, -50, 'Aardvark');

    // Status outranks weight: the disabled toolbar is lighter by 50 and still
    // sorts last.
    $this->assertLessThan(0, Toolbar::sort($upper, $retired));
    $this->assertGreaterThan(0, Toolbar::sort($retired, $upper));

    // Weight outranks the label: `zeta` would sort last alphabetically.
    $this->assertLessThan(0, Toolbar::sort($lifted, $upper));
    $this->assertGreaterThan(0, Toolbar::sort($upper, $lifted));

    // The label is the last word, compared byte by byte.
    $this->assertLessThan(0, Toolbar::sort($upper, $lower));
    $this->assertGreaterThan(0, Toolbar::sort($lower, $upper));

    // Alike on all three counts is a tie.
    $this->assertSame(0, Toolbar::sort($upper, $this->toolbar(TRUE, 0, 'Zulu')));

    // And the three rules compose into one order.
    $toolbars = [$lower, $retired, $upper, $lifted];
    usort($toolbars, [Toolbar::class, 'sort']);
    $labels = array_map(static function (ConfigEntityInterface $toolbar): string {
      return (string) $toolbar->label();
    }, $toolbars);
    $this->assertSame(['zeta', 'Zulu', 'alpha', 'Aardvark'], $labels);
  }

  /**
   * Builds a divider plugin.
   *
   * The base constructor takes only the transliteration service, and the
   * divider declares no contexts, so the plugin is built directly rather than
   * through the plugin manager.
   *
   * @return \Drupal\neo_toolbar\Plugin\ToolbarItem\Divider
   *   The divider plugin.
   */
  private function divider(): Divider {
    return new Divider([], 'divider', ['id' => 'divider'], $this->createMock(TransliterationInterface::class));
  }

  /**
   * Builds a toolbar item double standing in for one neighbour.
   *
   * The rule reads exactly one thing off a neighbour — its plugin id — and
   * then, in the third refusal, its cacheability.
   *
   * @param string $pluginId
   *   The plugin id the neighbour answers with.
   * @param string[] $contexts
   *   The neighbour's cache contexts.
   * @param string[] $tags
   *   The neighbour's cache tags.
   * @param int $maxAge
   *   The neighbour's cache max-age.
   *
   * @return \Drupal\neo_toolbar\ToolbarItemInterface
   *   The toolbar item double.
   */
  private function item(string $pluginId, array $contexts = [], array $tags = [], int $maxAge = Cache::PERMANENT): ToolbarItemInterface {
    $item = $this->createMock(ToolbarItemInterface::class);
    $item->method('getPluginId')->willReturn($pluginId);
    $item->method('getCacheContexts')->willReturn($contexts);
    $item->method('getCacheTags')->willReturn($tags);
    $item->method('getCacheMaxAge')->willReturn($maxAge);
    return $item;
  }

  /**
   * Builds a config entity double standing in for one toolbar.
   *
   * The comparator reads three things and nothing else: `status()`, the
   * `weight` property and `label()`.
   *
   * @param bool $status
   *   Whether the toolbar is enabled.
   * @param int $weight
   *   The toolbar's weight.
   * @param string $label
   *   The toolbar's label.
   *
   * @return \Drupal\Core\Config\Entity\ConfigEntityInterface
   *   The toolbar double.
   */
  private function toolbar(bool $status, int $weight, string $label): ConfigEntityInterface {
    $toolbar = $this->createMock(ConfigEntityInterface::class);
    $toolbar->method('status')->willReturn($status);
    $toolbar->method('get')->willReturnCallback(static function (string $property) use ($weight) {
      return $property === 'weight' ? $weight : NULL;
    });
    $toolbar->method('label')->willReturn($label);
    return $toolbar;
  }

}
