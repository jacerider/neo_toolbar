<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_toolbar\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;

/**
 * Proves the declared icon travels attribute → discovery → definition → plugin.
 *
 * The unit half of this change reads each plugin class's **toolbar item
 * attribute** by reflection, which says the declaration is written but not that
 * anything reads it. This is the other end: the real plugin manager discovers
 * the attribute, the definition it caches carries the icon, and the plugin the
 * manager builds answers that same string out of the base class. Every step in
 * that chain is a place the string could be dropped, and only a container that
 * really runs discovery visits all of them.
 *
 * Four of the seven are asserted here, and the choice of four is the kernel
 * floor rather than a sample. `KernelTestBase` installs exactly what
 * `$modules` names, so the definitions that exist are the ones whose provider
 * is installed: the divider, local tasks, local actions and the user item. The
 * masquerade, taxonomy and content-moderation plugins are filtered out of
 * discovery by `DefaultPluginManager`'s own provider check, and installing two
 * content modules — and a third that is not on this site at all — to assert
 * three constant strings is a wider fixture than the assertion is worth. Their
 * declarations are asserted by reflection instead, which needs no provider.
 *
 * The definition is asserted alongside the plugin's answer rather than instead
 * of it. `getIcon()` returning the right string proves the base reads *a*
 * value; comparing it against `getDefinition()` proves it is reading *the
 * declaration*, which is the claim.
 *
 * The last criterion guards the other direction. A `link` item answers the
 * **configured icon** its own settings carry, and it has to keep doing so — an
 * icon a site administrator chose is never overruled by a **declared icon**.
 * That assertion cannot go red on this change; it is here so that a later one
 * cannot quietly make the base's declaration outrank a plugin's own override.
 *
 * `neo_toolbar`'s own `config/install` is deliberately not installed, for the
 * same reason the other kernel tests here skip it: one shipped item carries a
 * plugin from `neo_favicon`, a package this module does not depend on. Nothing
 * here needs a toolbar entity.
 */
#[Group('neo_toolbar')]
final class PluginDeclaredIconsTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The kernel floor the other tests here established, and no more. `system`
   * and `user` are what make the four plugins below constructible — the user
   * item asks the container for `current_user` and `entity_type.manager`, and
   * the two menu items ask for the local task and local action managers.
   */
  protected static $modules = [
    'system',
    'user',
    'neo_toolbar',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The user item loads the current account in its constructor, before
    // anything asks it for an icon, so the user schema and the anonymous
    // account both have to exist for the manager to build it at all.
    $this->installEntitySchema('user');
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
  }

  /**
   * The declared icon reaches the definition and the plugin answers it.
   *
   * Covers: it answers the declared icon through the real plugin manager for
   * the divider, local tasks, local actions and the user item, matching that
   * plugin's definition.
   */
  public function testTheManagerCarriesTheDeclaredIconThroughToThePlugin(): void {
    $manager = $this->container->get('plugin.manager.neo_toolbar_item');

    $expected = [
      'divider' => 'grip-lines',
      'local_tasks' => 'dot-circle',
      'local_actions' => 'bahai',
      'user' => 'user-circle',
    ];

    $definitions = [];
    $answers = [];
    foreach (array_keys($expected) as $plugin_id) {
      $definition = $manager->getDefinition($plugin_id);
      $definitions[$plugin_id] = $definition['icon'] ?? NULL;
      $answers[$plugin_id] = $manager->createInstance($plugin_id)->getIcon();
    }

    // The definition discovery produced carries the declaration, and the
    // plugin the manager built answers that same declaration.
    $this->assertSame($expected, $definitions);
    $this->assertSame($definitions, $answers);
  }

  /**
   * A link item still answers the icon its own settings carry.
   *
   * `link` overrides `getIcon()` to read the toolbar item's configuration, and
   * that override outranks anything the base could read from a definition. The
   * empty case is asserted too, because "declared fallback for an empty
   * configured icon" is the change this criterion exists to refuse: it would
   * alter what an item renders for an administrator who left the setting blank.
   *
   * Covers: a link item still answers the icon its own settings carry, not a
   * declared one.
   */
  public function testLinkItemAnswersItsConfiguredIcon(): void {
    $manager = $this->container->get('plugin.manager.neo_toolbar_item');

    $this->assertNull($manager->getDefinition('link')['icon'] ?? NULL);
    $this->assertSame('bell', $manager->createInstance('link', ['icon' => 'bell'])->getIcon());
    // An unconfigured link answers its own empty setting, not a declaration.
    $this->assertSame('', $manager->createInstance('link')->getIcon());
  }

}
