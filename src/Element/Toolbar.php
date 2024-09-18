<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar\Element;

use Drupal\Core\Render\Attribute\RenderElement;
use Drupal\Core\Render\Element\RenderElementBase;

/**
 * Provides a render element to display a neo toolbar.
 *
 * Properties:
 * - #foo: Property description here.
 *
 * Usage Example:
 * @code
 * $build['neo_toolbar'] = [
 *   '#type' => 'neo_toolbar',
 *   '#toolbar' => $toolbar,
 * ];
 * @endcode
 */
#[RenderElement('neo_toolbar')]
final class Toolbar extends RenderElementBase {

  /**
   * {@inheritdoc}
   */
  public function getInfo(): array {
    return [
      '#pre_render' => [
        [self::class, 'preRenderToolbar'],
      ],
      '#theme' => 'neo_toolbar',
    ];
  }

  /**
   * Neo toolbar element pre render callback.
   *
   * @param array $element
   *   An array containing the properties of the neo_toolbar element.
   *
   * @return array
   *   The modified element.
   */
  public static function preRenderToolbar(array $element): array {
    $element['#attached']['library'][] = 'neo_toolbar/toolbar';
    return $element;
  }

}
