<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_toolbar\Unit;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_toolbar\Attribute\ToolbarItem;
use Drupal\neo_toolbar\Plugin\ToolbarItem\Link;
use Drupal\neo_toolbar\ToolbarItemPluginBase;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the declared icon: the attribute states it, the base class answers it.
 *
 * A toolbar item plugin declares its id, its label, its description and its
 * region-creation flag in the toolbar item attribute, and — until now — wrote
 * its icon out as a method body instead. This class covers the two halves of
 * moving that one declaration into the place the plugin system already looks:
 * the **declared icon** reaching the definition array the attribute produces,
 * and `ToolbarItemPluginBase::getIcon()` reading it back out.
 *
 * Both halves are container-free. The attribute is a plain value object whose
 * definition array comes from one inherited method, and the base class returns
 * early from its context pass whenever a definition declares no contexts — so
 * a stub subclass, a hand-built definition array and a mocked transliteration
 * service reach `getIcon()` with no container, no database and no bootstrap.
 *
 * Three boundaries are deliberate.
 *
 * The icon is asserted to be the **last** constructor parameter, after the
 * provider. Grouping it with the label and the region-creation flag would read
 * better and would shift four existing parameters one place right; the
 * attribute is public API across roughly thirty sites, where a positional
 * declaration would then fail with a type error at plugin discovery. The
 * position is therefore load-bearing and is pinned rather than assumed.
 *
 * The base reads the key with a null coalesce, so a definition with **no**
 * icon key at all is asserted alongside one declaring `NULL`. A definition
 * contributed by an alter hook is merged after the plugin manager's defaults
 * are applied and can lack any key, which is why the missing-key case is a
 * real arrangement rather than a defensive flourish.
 *
 * A plugin's own override still wins, and `Link` is the plugin that proves it,
 * because its override answers the **configured icon** from the item's own
 * settings. That is a behaviour rather than a declaration, so it is one of the
 * three overrides that outlives the constant ones — an icon a site
 * administrator chose is never overruled by a declaration.
 */
#[Group('neo_toolbar')]
final class DeclaredIconTest extends UnitTestCase {

  /**
   * The attribute carries a declared icon into its definition.
   *
   * The parameter order is asserted in the same breath, because appending the
   * icon after the provider rather than grouping it with the label is the one
   * decision here a later tidy-up would be tempted to undo.
   *
   * Covers: it puts a declared icon into the definition the attribute
   * produces.
   */
  public function testAttributeCarriesTheDeclaredIcon(): void {
    $parameters = array_map(
      static fn (\ReflectionParameter $parameter): string => $parameter->getName(),
      (new \ReflectionMethod(ToolbarItem::class, '__construct'))->getParameters()
    );
    $this->assertSame([
      'id',
      'label',
      'description',
      'region_create',
      'context_definitions',
      'deriver',
      'provider',
      'icon',
    ], $parameters);

    $attribute = new ToolbarItem(
      id: 'declared',
      label: new TranslatableMarkup('Declared'),
      icon: 'star',
    );
    $attribute->setClass(Link::class);

    $definition = $attribute->get();
    $this->assertIsArray($definition);
    $this->assertArrayHasKey('icon', $definition);
    $this->assertSame('star', $definition['icon']);
  }

  /**
   * The attribute carries a null icon when nothing declares one.
   *
   * The inherited `get()` strips a `NULL` value only for `deriver`, `provider`
   * and `dependencies`, so an undeclared icon reaches the definition as a
   * present key holding `NULL` rather than as no key at all. Every plugin in
   * the module is in this state today.
   *
   * Covers: it puts a null icon into that definition when no icon is declared.
   */
  public function testAttributeCarriesNullIconWhenNoneIsDeclared(): void {
    $attribute = new ToolbarItem(
      id: 'plain',
      label: new TranslatableMarkup('Plain'),
    );
    $attribute->setClass(Link::class);

    $definition = $attribute->get();
    $this->assertIsArray($definition);
    $this->assertArrayHasKey('icon', $definition);
    $this->assertNull($definition['icon']);
  }

  /**
   * The base class answers the icon its definition declares.
   *
   * The base is abstract only so the plugin manager cannot instantiate it; it
   * implements every member of the interface, so an empty subclass is the
   * base's own answer with nothing in between.
   *
   * Covers: it answers the icon its plugin definition declares.
   */
  public function testBaseAnswersTheDeclaredIcon(): void {
    $this->assertSame('star', $this->basePlugin(['icon' => 'star'])->getIcon());
  }

  /**
   * The base class answers null for both shapes of "no icon".
   *
   * A definition that declares `NULL` and a definition with no icon key are
   * different arrangements reaching the same read, and only the coalesce makes
   * the second one an answer rather than a notice.
   *
   * Covers: it answers null when the definition declares no icon, and when the
   * definition has no icon key at all.
   */
  public function testBaseAnswersNullWithoutDeclaredIcon(): void {
    $this->assertNull($this->basePlugin(['icon' => NULL])->getIcon());
    $this->assertNull($this->basePlugin([])->getIcon());
  }

  /**
   * A plugin's own override answers instead of the declaration.
   *
   * `Link` answers the **configured icon** the toolbar item carries in its own
   * settings, and it keeps doing so even when its definition declares one — an
   * icon chosen by a site administrator outranks an icon written by a plugin
   * author. An anonymous subclass asserts the same thing about the base itself,
   * which is what says `getIcon()` was not quietly made final.
   *
   * Covers: it lets a plugin's own override answer instead of the declaration.
   */
  public function testAnOverrideAnswersInsteadOfTheDeclaration(): void {
    $link = new Link(
      ['icon' => 'bell'],
      'link',
      ['id' => 'link', 'icon' => 'star'],
      $this->createMock(TransliterationInterface::class),
    );
    $this->assertSame('bell', $link->getIcon());

    // An empty configured icon is still the configured answer, not a fallback
    // to the declaration.
    $emptied = new Link(
      ['icon' => ''],
      'link',
      ['id' => 'link', 'icon' => 'star'],
      $this->createMock(TransliterationInterface::class),
    );
    $this->assertSame('', $emptied->getIcon());

    $overriding = new class([], 'probe', ['id' => 'probe', 'icon' => 'star'], $this->createMock(TransliterationInterface::class)) extends ToolbarItemPluginBase {

      /**
       * {@inheritdoc}
       */
      public function getIcon(): string|null {
        return 'compass';
      }

    };
    $this->assertSame('compass', $overriding->getIcon());
  }

  /**
   * Builds the base class's own answer through an empty subclass.
   *
   * @param array $definition
   *   The plugin definition, minus the id every arrangement shares.
   *
   * @return \Drupal\neo_toolbar\ToolbarItemPluginBase
   *   The stub plugin.
   */
  private function basePlugin(array $definition): ToolbarItemPluginBase {
    return new class([], 'probe', ['id' => 'probe'] + $definition, $this->createMock(TransliterationInterface::class)) extends ToolbarItemPluginBase {};
  }

}
