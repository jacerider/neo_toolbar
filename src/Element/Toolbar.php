<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar\Element;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Render\Attribute\RenderElement;
use Drupal\Core\Render\Element\RenderElementBase;

/**
 * Provides a render element to display a neo toolbar.
 *
 * Properties:
 * - #toolbar: The Neo Toolbar entity.
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
    /** @var \Drupal\neo_toolbar\ToolbarInterface $toolbar */
    $toolbar = $element['#toolbar'];
    $cacheableMetadata = new CacheableMetadata();
    $cacheableMetadata->addCacheableDependency($toolbar);
    $element['#attached']['library'][] = 'neo_toolbar/toolbar';
    foreach ($toolbar->getRegions() as $regionId => $region) {
      $regionCacheableMetadata = new CacheableMetadata();
      $regionCacheableMetadata->addCacheableDependency($toolbar);
      $items = $toolbar->getItems($regionId, $regionCacheableMetadata);
      if ($items) {
        $element['#regions'][$regionId] = [
          '#lazy_builder' => [
            'neo_toolbar.lazy_builders:renderToolbarRegion',
            [$toolbar->id(), $regionId, $toolbar->isEditMode()],
          ],
          '#cache' => [
            'keys' => ['neo_toolbar', 'region', $regionId],
          ],
        ];
        $regionCacheableMetadata->applyTo($element['#regions'][$regionId]);
      }
    }
    return $element;
  }

}
