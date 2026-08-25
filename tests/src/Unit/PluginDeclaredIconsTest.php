<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_toolbar\Unit;

use Drupal\neo_toolbar\Attribute\ToolbarItem;
use Drupal\neo_toolbar\Plugin\ToolbarItem\ContentModerationControl;
use Drupal\neo_toolbar\Plugin\ToolbarItem\Divider;
use Drupal\neo_toolbar\Plugin\ToolbarItem\LocalActions;
use Drupal\neo_toolbar\Plugin\ToolbarItem\LocalTasks;
use Drupal\neo_toolbar\Plugin\ToolbarItem\Masquerade;
use Drupal\neo_toolbar\Plugin\ToolbarItem\Taxonomy;
use Drupal\neo_toolbar\Plugin\ToolbarItem\User;
use Drupal\neo_toolbar\ToolbarItemPluginBase;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the seven fixed icons to the attribute that now declares them.
 *
 * Seven toolbar item plugins answered `getIcon()` with a constant — the
 * divider with a grip, local tasks with a dot, local actions with a star, the
 * user item with a user circle, the masquerade item with a secret-agent glyph,
 * the taxonomy item with tags and the content-moderation item with a merge
 * arrow. Each of those is a **declaration** written in the one place the plugin
 * system does not look, and this class asserts it has moved into the **toolbar
 * item attribute** beside the label it always belonged next to.
 *
 * Reflection is the instrument on purpose, for two reasons the kernel half
 * cannot supply.
 *
 * The masquerade plugin's provider is **not installed on this site**, so its
 * definition is never discovered and no runtime tier here can reach it — its
 * sibling plan records exactly that about its own, runtime, question. A static
 * declaration is readable without instantiating anything, so the one plugin the
 * container cannot produce is asserted here all the same. The taxonomy and
 * content-moderation plugins are in the same position for a milder reason:
 * their definitions exist only when `taxonomy` and `content_moderation` are
 * installed, and installing two content modules to assert two constant strings
 * is a wider fixture than the assertion is worth.
 *
 * The second criterion — that no plugin still writes the method — is only
 * answerable by reflection at all. A plugin that kept its override and declared
 * the same string in its attribute would answer correctly through the manager
 * and would still be the duplicated shape this change exists to remove, so the
 * declaring class of `getIcon()` is read rather than its answer.
 *
 * The three plugins that answer a **configured icon** — `link`, `create` and
 * `region` — are deliberately absent from both criteria. They read the icon
 * from the toolbar item's own settings, which is a behaviour rather than a
 * declaration, and none of them gains a declared fallback: that would change
 * what those items render for an administrator who left the setting empty.
 */
#[Group('neo_toolbar')]
final class PluginDeclaredIconsTest extends UnitTestCase {

  /**
   * The icon every constant-answering plugin used to return from a method.
   *
   * Keyed by the plugin class, valued by the string that class answered before
   * the declaration moved. Two of the seven — taxonomy and content moderation —
   * sit in classes that are not `final`, which is both why phpstan never
   * reported their overrides as over-wide and why they are the only two
   * deletions with any downstream reach.
   */
  private const DECLARED_ICONS = [
    Divider::class => 'grip-lines',
    LocalTasks::class => 'dot-circle',
    LocalActions::class => 'bahai',
    User::class => 'user-circle',
    Masquerade::class => 'user-secret',
    Taxonomy::class => 'tags',
    ContentModerationControl::class => 'code-merge',
  ];

  /**
   * Every one of the seven states its icon in its own attribute.
   *
   * The attribute is read off the class rather than out of a discovered
   * definition, so the assertion holds for the four plugins this site can
   * instantiate and equally for the three whose provider modules are not
   * installed here.
   *
   * Covers: it declares the same seven icons the seven plugins used to return,
   * read from each plugin class's own attribute — including the masquerade
   * plugin, whose provider is not installed here.
   */
  public function testTheSevenPluginsDeclareTheirIcon(): void {
    $declared = [];
    foreach (array_keys(self::DECLARED_ICONS) as $class) {
      $attributes = (new \ReflectionClass($class))->getAttributes(ToolbarItem::class);
      $this->assertCount(1, $attributes, $class . ' carries exactly one toolbar item attribute.');
      $declared[$class] = $attributes[0]->newInstance()->icon;
    }
    $this->assertSame(self::DECLARED_ICONS, $declared);
  }

  /**
   * None of the seven writes the method any more.
   *
   * `getIcon()` still exists on all seven — it is on the interface and the base
   * class implements it — so the question is which class *declares* it. The
   * base is the only allowed answer here: a plugin that answered correctly from
   * its own retained override would be indistinguishable through the manager
   * and would still be the shape this change removes.
   *
   * Covers: none of the seven classes still defines its own icon method.
   */
  public function testNoneOfTheSevenStillDefinesTheMethod(): void {
    $declaring = [];
    foreach (array_keys(self::DECLARED_ICONS) as $class) {
      $declaring[$class] = (new \ReflectionMethod($class, 'getIcon'))->getDeclaringClass()->getName();
    }
    $this->assertSame(
      array_fill_keys(array_keys(self::DECLARED_ICONS), ToolbarItemPluginBase::class),
      $declaring
    );
  }

}
