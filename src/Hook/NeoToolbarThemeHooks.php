<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Template\Attribute;

/**
 * The module's theme registration, suggestions and preprocessors.
 *
 * Split from the behavioural hooks the way core splits its own dozen-odd
 * `…Hooks` / `…ThemeHooks` pairs: those three need collaborators, these need
 * none. This is core's pattern rather than an adaptation of it — a dozen core
 * modules in 11.4.4 register their theme hooks from a `#[Hook('theme')]` class,
 * core's own `toolbar` module among them — and the template path default is
 * derived from the extension's path rather than from where the implementation
 * lives, so nothing about template resolution changed with the move.
 *
 * The registration array below is what stood in `neo_toolbar.module`: the same
 * eight theme hooks, the same variables, the same two base-hook entries for the
 * divider and grid element variants. Two keys are new, and they are the reason
 * this class exists rather than a tidier `.module`.
 *
 * `template_preprocess_HOOK()` is deprecated as of Drupal 11.3 and removed in
 * Drupal 12, and the theme registry raises a deprecation for every one it
 * finds; this module raised two on every registry build. The replacement is an
 * **initial preprocess** entry in the theme hook's own definition, naming this
 * class and the method, which the theme manager resolves through the callable
 * resolver against the container. Position is preserved exactly: an initial
 * preprocess callback runs before every module and theme preprocess function,
 * which is where the deprecated function ran, so nothing that reads the
 * variables they set can observe the change.
 *
 * This is not an API and it is not `final`. The methods are public because
 * core's hook collector only reads public methods; the only one anything
 * outside the hook system reaches is a preprocess method, reached through the
 * theme registry by the callable the theme hook names.
 */
class NeoToolbarThemeHooks {

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'neo_toolbar' => [
        'variables' => ['toolbar' => NULL, 'regions' => []],
      ],
      'neo_toolbar_region' => [
        'variables' => ['region' => NULL, 'items' => []],
        'initial preprocess' => static::class . ':preprocessNeoToolbarRegion',
      ],
      'neo_toolbar_item' => [
        'variables' => [
          'attributes' => [],
          'alignment' => NULL,
          'style' => 'default',
          'elements' => [],
        ],
      ],
      'neo_toolbar_item_account_modal' => [
        'variables' => [
          'image' => NULL,
          'name' => NULL,
          'mail' => NULL,
        ],
      ],
      'neo_toolbar_modal' => [
        'variables' => [
          'content' => [],
          'title' => NULL,
          'title_attributes' => [],
        ],
        'initial preprocess' => static::class . ':preprocessNeoToolbarModal',
      ],
      'neo_toolbar_element' => [
        'variables' => [
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
      ],
      'neo_toolbar_element__divider' => [
        'base hook' => 'neo_toolbar_element',
      ],
      'neo_toolbar_element__grid' => [
        'base hook' => 'neo_toolbar_element',
      ],
    ];
  }

  /**
   * Implements hook_theme_suggestions_HOOK().
   */
  #[Hook('theme_suggestions_neo_toolbar_region')]
  public function themeSuggestionsNeoToolbarRegion(array $variables): array {
    $suggestions = [];
    /** @var \Drupal\neo_toolbar\ToolbarRegionPluginInterface $region */
    $region = $variables['region'];
    $suggestions[] = 'neo_toolbar_region__' . $region->getAlignment();
    return $suggestions;
  }

  /**
   * Implements hook_theme_suggestions_HOOK().
   */
  #[Hook('theme_suggestions_neo_toolbar_item')]
  public function themeSuggestionsNeoToolbarItem(array $variables): array {
    $suggestions = [];
    $suggestions[] = 'neo_toolbar_item__' . $variables['alignment'];
    return $suggestions;
  }

  /**
   * Implements hook_theme_suggestions_HOOK().
   */
  #[Hook('theme_suggestions_neo_toolbar_element')]
  public function themeSuggestionsNeoToolbarElement(array $variables): array {
    $suggestions = [];
    $suggestions[] = 'neo_toolbar_element__' . $variables['style'];
    $suggestions[] = 'neo_toolbar_element__' . $variables['id'];
    $suggestions[] = 'neo_toolbar_element__' . $variables['id'] . '__' . $variables['alignment'];
    return $suggestions;
  }

  /**
   * Prepares variables for neo-toolbar-region.html.twig template.
   *
   * Default template: neo-toolbar-region.html.twig.
   *
   * This was `template_preprocess_neo_toolbar_region()`. It is named as the
   * theme hook's initial preprocess callback rather than being found by name,
   * which is the same position with none of the deprecation.
   *
   * @param array $variables
   *   An associative array.
   */
  public function preprocessNeoToolbarRegion(array &$variables): void {
    $variables['alignment'] = $variables['region']->getAlignment();
    $variables['position'] = $variables['region']->getPosition();
  }

  /**
   * Prepares variables for neo-toolbar-modal.html.twig template.
   *
   * Default template: neo-toolbar-modal.html.twig.
   *
   * This was `template_preprocess_neo_toolbar_modal()`, moved for the same
   * reason and into the same position.
   *
   * @param array $variables
   *   An associative array.
   */
  public function preprocessNeoToolbarModal(array &$variables): void {
    $variables['title_attributes'] = new Attribute($variables['title_attributes'] ?? []);
  }

}
