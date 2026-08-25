<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_toolbar\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Template\Attribute;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_toolbar\Hook\NeoToolbarThemeHooks;
use PHPUnit\Framework\Attributes\Group;

/**
 * The module's theme registration, suggestions and preprocessors, as a class.
 *
 * The theme hook, its three suggestion hooks and the two preprocessors are
 * methods on `Drupal\neo_toolbar\Hook\NeoToolbarThemeHooks`. Nothing any of
 * them produces moved with them — the same eight theme hooks, the same
 * variables, the same suggestions in the same order — so what is at risk is
 * discovery rather than a body: a wrong hook attribute, a wrong hook name or a
 * class in a namespace nothing scans produces a class that reads correctly and
 * is never consulted.
 *
 * So the assertions go through the theme registry and the module handler, not
 * through rendered markup. A template is the visual ticket's business; what
 * this class proves is that the registry holds the eight hooks, that it names
 * the two preprocessors in the slot that runs before every other preprocess
 * function, and that the hook system resolves all four hooks to this class.
 *
 * The two preprocessors are asserted by resolving the callable the registry
 * names — through `callable_resolver`, the same service the theme manager uses
 * for exactly this — and invoking it. That is "what the registry invokes",
 * which is a different statement from "what a method on a class returns when
 * called directly", and only the first one fails when the entry is missing or
 * misspelled.
 */
#[Group('neo_toolbar')]
final class ThemeHooksTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The floor, and nothing above it: no criterion here needs a toolbar entity,
   * a toolbar item or a fixture plugin. The region plugin one criterion reads
   * is one of the five `neo_toolbar` ships in its own
   * `neo_toolbar.neo_toolbar_regions.yml`.
   */
  protected static $modules = [
    'system',
    'user',
    'neo_toolbar',
  ];

  /**
   * The eight theme hooks, with the variables each carried before the move.
   *
   * Copied from `neo_toolbar_theme()` as it stood, which is the point: the
   * registration array moved without being rewritten, so a template reading
   * any of these keeps reading it.
   */
  private const EXPECTED_HOOKS = [
    'neo_toolbar' => [
      'toolbar' => NULL,
      'regions' => [],
    ],
    'neo_toolbar_region' => [
      'region' => NULL,
      'items' => [],
    ],
    'neo_toolbar_item' => [
      'attributes' => [],
      'alignment' => NULL,
      'style' => 'default',
      'elements' => [],
    ],
    'neo_toolbar_item_account_modal' => [
      'image' => NULL,
      'name' => NULL,
      'mail' => NULL,
    ],
    'neo_toolbar_modal' => [
      'content' => [],
      'title' => NULL,
      'title_attributes' => [],
    ],
    'neo_toolbar_element' => [
      'id' => NULL,
      'tag' => NULL,
      'alignment' => NULL,
      'style' => 'default',
      'title' => NULL,
      'icon' => NULL,
      'image' => NULL,
      'image_size' => NULL,
      'badge' => NULL,
      'url' => NULL,
      'attributes' => NULL,
      'title_attributes' => NULL,
      'icon_attributes' => NULL,
      'image_attributes' => NULL,
      'badge_attributes' => NULL,
      'children' => NULL,
      'after' => NULL,
      'before' => NULL,
    ],
  ];

  /**
   * Every theme hook the module registered before is registered now.
   *
   * Covers: it registers all eight toolbar theme hooks with the variables they
   * carried before.
   */
  public function testRegistersAllEightToolbarThemeHooks(): void {
    $registry = $this->container->get('theme.registry')->get();

    foreach (self::EXPECTED_HOOKS as $hook => $variables) {
      $this->assertArrayHasKey($hook, $registry, "The theme registry holds $hook.");
      $this->assertSame($variables, $registry[$hook]['variables'], "$hook carries the variables it carried before.");
    }

    // The two element variants register nothing but a base hook, and take the
    // base hook's variables from it — which is what lets a divider or a grid
    // be themed by a template of its own without restating eighteen keys.
    foreach (['neo_toolbar_element__divider', 'neo_toolbar_element__grid'] as $variant) {
      $this->assertArrayHasKey($variant, $registry, "The theme registry holds $variant.");
      $this->assertSame('neo_toolbar_element', $registry[$variant]['base hook']);
      $this->assertSame(
        self::EXPECTED_HOOKS['neo_toolbar_element'],
        $registry[$variant]['variables']
      );
    }

    // Eight, and the registration came from the class rather than from a
    // function the collector is still reading out of the `.module` file.
    $this->assertCount(8, array_intersect_key($registry, array_flip([
      ...array_keys(self::EXPECTED_HOOKS),
      'neo_toolbar_element__divider',
      'neo_toolbar_element__grid',
    ])));
    $this->assertContains(
      'neo_toolbar: ' . NeoToolbarThemeHooks::class . '::theme',
      $this->hookImplementations('theme')
    );
  }

  /**
   * The region preprocessor is named where it runs before everything else.
   *
   * Covers: it sets a region's alignment and position through an initial
   * preprocess callback, before any other preprocess function runs.
   */
  public function testSetsRegionAlignmentAndPositionThroughInitialPreprocess(): void {
    $registry = $this->container->get('theme.registry')->get();

    // The slot is the position: the theme manager invokes `initial preprocess`
    // before it walks `preprocess functions`, which is where
    // `template_preprocess_HOOK()` used to be appended.
    $this->assertSame(
      NeoToolbarThemeHooks::class . ':preprocessNeoToolbarRegion',
      $registry['neo_toolbar_region']['initial preprocess']
    );
    $this->assertNotContains(
      'template_preprocess_neo_toolbar_region',
      $registry['neo_toolbar_region']['preprocess functions'] ?? []
    );
    $this->assertFalse(
      function_exists('template_preprocess_neo_toolbar_region'),
      'The deprecated procedural preprocessor is gone.'
    );

    // What the registry invokes, resolved the way the theme manager resolves
    // it, and given the region a real template would be handed.
    $region = $this->container->get('plugin.manager.neo_toolbar_region')->createInstance('side_end');
    $variables = ['region' => $region, 'items' => []];
    $this->invokeInitialPreprocess($registry, 'neo_toolbar_region', $variables);

    $this->assertSame('vertical', $variables['alignment']);
    $this->assertSame('end', $variables['position']);

    // In the slot, and not in the list: the theme manager walks
    // `preprocess functions` only once the initial callback has returned, so
    // every module and theme preprocess for this hook still runs after it, as
    // they all did when the deprecated function held the position.
    $this->assertNotContains(
      $registry['neo_toolbar_region']['initial preprocess'],
      $registry['neo_toolbar_region']['preprocess functions'] ?? []
    );
  }

  /**
   * The modal preprocessor is named in the same slot, and does the same thing.
   *
   * Covers: it turns a modal's title attributes into an Attribute object
   * through an initial preprocess callback.
   */
  public function testTurnsModalTitleAttributesIntoAttributeObject(): void {
    $registry = $this->container->get('theme.registry')->get();

    $this->assertSame(
      NeoToolbarThemeHooks::class . ':preprocessNeoToolbarModal',
      $registry['neo_toolbar_modal']['initial preprocess']
    );
    $this->assertNotContains(
      'template_preprocess_neo_toolbar_modal',
      $registry['neo_toolbar_modal']['preprocess functions'] ?? []
    );
    $this->assertFalse(
      function_exists('template_preprocess_neo_toolbar_modal'),
      'The deprecated procedural preprocessor is gone.'
    );

    // The template calls `title_attributes.addClass(...)`, so an array would
    // be a fatal there and the object is the whole job.
    $variables = ['title_attributes' => ['class' => ['sticky']], 'title' => 'Account'];
    $this->invokeInitialPreprocess($registry, 'neo_toolbar_modal', $variables);

    $this->assertInstanceOf(Attribute::class, $variables['title_attributes']);
    $this->assertSame(['class' => ['sticky']], $variables['title_attributes']->toArray());

    // The theme hook declares the variable as an empty array, and a modal that
    // sets nothing still reaches the template with an object.
    $empty = ['title_attributes' => [], 'title' => NULL];
    $this->invokeInitialPreprocess($registry, 'neo_toolbar_modal', $empty);
    $this->assertInstanceOf(Attribute::class, $empty['title_attributes']);
    $this->assertSame([], $empty['title_attributes']->toArray());
  }

  /**
   * Regions and items are suggested by the alignment they are drawn at.
   *
   * Covers: it suggests a region template by alignment, and an item template
   * by alignment.
   */
  public function testSuggestsRegionAndItemTemplatesByAlignment(): void {
    $region = $this->container->get('plugin.manager.neo_toolbar_region')->createInstance('top_start');

    $this->assertSame(
      ['neo_toolbar_region__horizontal'],
      $this->invokeSuggestions('neo_toolbar_region', ['region' => $region, 'items' => []])
    );
    $this->assertSame(
      ['neo_toolbar_item__vertical'],
      $this->invokeSuggestions('neo_toolbar_item', ['alignment' => 'vertical'])
    );

    $this->assertContains(
      'neo_toolbar: ' . NeoToolbarThemeHooks::class . '::themeSuggestionsNeoToolbarRegion',
      $this->hookImplementations('theme_suggestions_neo_toolbar_region')
    );
    $this->assertContains(
      'neo_toolbar: ' . NeoToolbarThemeHooks::class . '::themeSuggestionsNeoToolbarItem',
      $this->hookImplementations('theme_suggestions_neo_toolbar_item')
    );
  }

  /**
   * An element is suggested three ways, in the order it always returned them.
   *
   * Covers: it suggests element templates by style, by id, and by id and
   * alignment together.
   */
  public function testSuggestsElementTemplatesByStyleAndId(): void {
    $suggestions = $this->invokeSuggestions('neo_toolbar_element', [
      'id' => 'account',
      'style' => 'divider',
      'alignment' => 'horizontal',
    ]);

    // Order is the contract: the theme system takes the last match, so the id
    // beats the style and the id-with-alignment beats them both.
    $this->assertSame([
      'neo_toolbar_element__divider',
      'neo_toolbar_element__account',
      'neo_toolbar_element__account__horizontal',
    ], $suggestions);

    $this->assertContains(
      'neo_toolbar: ' . NeoToolbarThemeHooks::class . '::themeSuggestionsNeoToolbarElement',
      $this->hookImplementations('theme_suggestions_neo_toolbar_element')
    );
  }

  /**
   * Nothing procedural is left, and the module says so to the collector.
   *
   * Covers: it leaves the module with no procedural hook implementation, and
   * declares the scan skip.
   */
  public function testLeavesNoProceduralHookImplementationAndDeclaresTheScanSkip(): void {
    // The declaration is read out of the file rather than off the container:
    // core removes every `*.skip_procedural_hook_scan` parameter once the
    // container is built, because it is only needed while building it.
    //
    // @see \Drupal\Core\Hook\HookCollectorKeyValueWritePass::process()
    $services = Yaml::decode(file_get_contents($this->modulePath() . '/neo_toolbar.services.yml'));
    $this->assertTrue(
      $services['parameters']['neo_toolbar.skip_procedural_hook_scan'] ?? FALSE,
      'The module declares the container parameter that stops the procedural scan.'
    );

    // What the skip buys, and the reason it is not cosmetic: without it the
    // collector reads the surviving global forwarder as an implementation of a
    // `hook_toolbar_view_access` that does not exist anywhere.
    $this->assertFalse(
      $this->container->get('module_handler')->hasImplementations('toolbar_view_access'),
      'The gate forwarder is not registered as a hook implementation.'
    );

    // Every hook the module does implement is answered by a class method, not
    // by a function name.
    $hooks = [
      'theme',
      'theme_suggestions_neo_toolbar_region',
      'theme_suggestions_neo_toolbar_item',
      'theme_suggestions_neo_toolbar_element',
      'page_top',
      'block_access',
      'local_tasks_alter',
    ];
    foreach ($hooks as $hook) {
      foreach ($this->hookImplementations($hook) as $implementation) {
        if (str_starts_with($implementation, 'neo_toolbar: ')) {
          $this->assertStringContainsString('::', $implementation, "$hook is implemented by a class method.");
        }
      }
    }
  }

  /**
   * The one thing the `.module` still holds still answers.
   *
   * Covers: it leaves the gate forwarder defined and answering, with the
   * `.module` holding nothing else.
   */
  public function testLeavesTheGateForwarderDefinedAndAnswering(): void {
    $this->assertTrue(
      function_exists('neo_toolbar_toolbar_view_access'),
      'The global forwarder a site may call from its own code is still defined.'
    );

    $gate = $this->container->get('neo_toolbar.access_gate');

    $this->signIn($this->account(7, TRUE));
    $this->assertTrue(neo_toolbar_toolbar_view_access());
    $this->assertSame($gate->hasAccess(), neo_toolbar_toolbar_view_access());

    $this->signIn($this->account(8, FALSE));
    $this->assertFalse(neo_toolbar_toolbar_view_access());
    $this->assertSame($gate->hasAccess(), neo_toolbar_toolbar_view_access());

    // And nothing else: one function, no hook attribute, no preprocessor.
    $source = file_get_contents($this->modulePath() . '/neo_toolbar.module');
    preg_match_all('/^\s*function\s+(\w+)/m', $source, $matches);
    $this->assertSame(['neo_toolbar_toolbar_view_access'], $matches[1]);
    $this->assertStringNotContainsString('template_preprocess_', $source);
    $this->assertStringNotContainsString('#[Hook', $source);
  }

  /**
   * The absolute path of the module directory.
   *
   * @return string
   *   The path, with no trailing slash.
   */
  protected function modulePath(): string {
    return $this->container->getParameter('app.root') . '/' .
      $this->container->get('extension.list.module')->getPath('neo_toolbar');
  }

  /**
   * Resolves and invokes a theme hook's initial preprocess callback.
   *
   * Resolution goes through `callable_resolver` rather than through a direct
   * method call, because that is what the theme manager does with the string
   * the theme hook definition names, and a string naming a class or method
   * that does not exist only fails here.
   *
   * @param array $registry
   *   The theme registry.
   * @param string $hook
   *   The theme hook whose callback is to be invoked.
   * @param array $variables
   *   The variables, altered in place exactly as the theme manager alters
   *   them.
   *
   * @see \Drupal\Core\Theme\ThemeManager::render()
   */
  protected function invokeInitialPreprocess(array $registry, string $hook, array &$variables): void {
    $callable = $this->container->get('callable_resolver')
      ->getCallableFromDefinition($registry[$hook]['initial preprocess']);
    $callable($variables, $hook, $registry[$hook]);
  }

  /**
   * Invokes a theme suggestions hook the way the theme manager invokes it.
   *
   * @param string $hook
   *   The theme hook the suggestions are for.
   * @param array $variables
   *   The variables the suggestions are derived from.
   *
   * @return string[]
   *   The suggestions, in the order the hook system returned them.
   *
   * @see \Drupal\Core\Theme\ThemeManager::render()
   */
  protected function invokeSuggestions(string $hook, array $variables): array {
    return $this->container->get('module_handler')
      ->invokeAll('theme_suggestions_' . $hook, [$variables]);
  }

  /**
   * The implementations the hook system resolved for a hook, in order.
   *
   * @param string $hook
   *   The hook name, without the `hook_` prefix.
   *
   * @return string[]
   *   One `module: identifier` string per implementation, where the identifier
   *   is `Class::method` for a class-based implementation and the function
   *   name for a procedural one.
   */
  protected function hookImplementations(string $hook): array {
    $implementations = [];
    $this->container->get('module_handler')->invokeAllWith(
      $hook,
      static function (callable $implementation, string $module) use (&$implementations): void {
        if (is_array($implementation)) {
          $identifier = get_class($implementation[0]) . '::' . $implementation[1];
        }
        elseif (is_string($implementation)) {
          $identifier = $implementation;
        }
        else {
          $identifier = get_debug_type($implementation);
        }
        $implementations[] = $module . ': ' . $identifier;
      }
    );
    return $implementations;
  }

  /**
   * An account holding, or not holding, the toolbar permission.
   *
   * A double rather than a saved user, because the gate reads exactly two
   * things off the account and this class installs no entity schema — the gate
   * has its own unit and kernel classes where its branches are driven.
   *
   * @param int $uid
   *   The account id, which also keys the gate's memo.
   * @param bool $permitted
   *   Whether the account holds `access neo_toolbar`.
   *
   * @return \Drupal\Core\Session\AccountInterface
   *   The account.
   */
  protected function account(int $uid, bool $permitted): AccountInterface {
    $account = $this->createMock(AccountInterface::class);
    $account->method('id')->willReturn($uid);
    $account->method('hasPermission')->willReturnCallback(
      static fn (string $permission): bool => $permission === 'access neo_toolbar' && $permitted
    );
    return $account;
  }

  /**
   * Makes an account the current user.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   */
  protected function signIn(AccountInterface $account): void {
    \Drupal::currentUser()->setAccount($account);
  }

}
