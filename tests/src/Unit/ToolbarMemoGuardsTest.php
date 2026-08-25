<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_toolbar\Unit;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Plugin\DefaultLazyPluginCollection;
use Drupal\neo_toolbar\Entity\Toolbar;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Covers the two lazily-populated properties the toolbar entity guards.
 *
 * `Toolbar` holds two things it computes once and keeps: the item memo, and
 * the region plugin collection. Both were declared by docblock alone — `@var
 * \Drupal\neo_toolbar\ToolbarItemInterface[]` and `@var
 * \Drupal\Core\Plugin\DefaultLazyPluginCollection` — and both were then
 * guarded on the one state those declarations rule out, with `isset()` on the
 * first and a falsiness test on the second. phpstan reports each as a
 * condition that cannot hold: `isset.property` and `booleanNot.alwaysFalse`.
 *
 * The fix is to say what is true. Each property is declared with a real
 * nullable type and an explicit `NULL` default, so "not computed yet" is a
 * value the type admits and the guard that tests for it is honest. The
 * answers do not move — an untyped property with no default already reads as
 * `NULL` — which is why nothing in the characterisation suite changes.
 *
 * Reflection is the whole test, because a declaration is the whole change.
 */
#[Group('neo_toolbar')]
final class ToolbarMemoGuardsTest extends UnitTestCase {

  /**
   * The memo and region collection are nullable, and default to NULL.
   *
   * Covers: the toolbar entity's memo and region-collection guards no longer
   * test a state their declared types forbid.
   */
  public function testLazyPropertiesAreDeclaredNullableAndDefaultToNull(): void {
    $expected = [
      'items' => 'array',
      'itemsCacheableMetadata' => CacheableMetadata::class,
      'regionCollection' => DefaultLazyPluginCollection::class,
    ];

    foreach ($expected as $name => $type) {
      $property = new \ReflectionProperty(Toolbar::class, $name);
      $this->assertTrue($property->hasType(), sprintf('%s declares a type.', $name));
      $declared = $property->getType();
      $this->assertInstanceOf(\ReflectionNamedType::class, $declared);
      $this->assertSame($type, $declared->getName(), sprintf('%s is declared as %s.', $name, $type));
      $this->assertTrue($declared->allowsNull(), sprintf('%s admits the uncomputed state.', $name));
      $this->assertTrue($property->hasDefaultValue(), sprintf('%s has a default.', $name));
      $this->assertNull($property->getDefaultValue(), sprintf('%s defaults to NULL.', $name));
    }
  }

  /**
   * Neither guard tests a state a declared type forbids.
   *
   * The declarations above are only half the fix: a nullable property still
   * guarded with `isset()` would pass every assertion in the first test and
   * leave the phpstan finding exactly where it was. This reads the source
   * because the two expressions are what phpstan reported, and an expression
   * that is gone cannot be observed through the class's answers.
   */
  public function testNeitherGuardTestsForAnImpossibleState(): void {
    $file = (new \ReflectionClass(Toolbar::class))->getFileName();
    $this->assertIsString($file);
    $source = file_get_contents($file);
    $this->assertIsString($source);

    // Asserted on booleans rather than with the string constraints, so a
    // failure names the expression rather than printing the whole class.
    $this->assertFalse(str_contains($source, 'isset($this->items)'), 'The memo is no longer guarded with isset().');
    $this->assertFalse(str_contains($source, '!$this->regionCollection'), 'The region collection is no longer guarded on falsiness.');
    $this->assertTrue(str_contains($source, '$this->items === NULL'), 'The memo is guarded on NULL.');
    $this->assertTrue(str_contains($source, '$this->regionCollection === NULL'), 'The region collection is guarded on NULL.');
  }

}
