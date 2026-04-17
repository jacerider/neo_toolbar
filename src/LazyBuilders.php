<?php

namespace Drupal\neo_toolbar;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Security\TrustedCallbackInterface;

/**
 * Defines a class for lazy building render arrays.
 *
 * @internal
 */
final class LazyBuilders implements TrustedCallbackInterface {

  /**
   * Constructs LazyBuilders object.
   */
  public function __construct(
    protected readonly EntityTypeManager $entityTypeManager,
    protected readonly ToolbarRegionPluginManager $regionPluginManager,
  ) {
  }

  /**
   * Render the Commerce inbox link with an unread messages indicator.
   *
   * @return array
   *   Render array.
   */
  public function renderToolbarRegion($toolbarId, $regionId, $isEditMode = FALSE): array {
    $build = [];
    /** @var \Drupal\neo_toolbar\ToolbarInterface $toolbar */
    $toolbar = $this->entityTypeManager->getStorage('neo_toolbar')->load($toolbarId);
    $toolbar->setEditMode($isEditMode);
    $cacheableMetadata = new CacheableMetadata();
    $cacheableMetadata->addCacheableDependency($toolbar);
    $items = $items = $toolbar->getItems($regionId, $cacheableMetadata);
    if ($items) {
      $region = $this->regionPluginManager->createInstance($regionId);
      $build = [];
      foreach ($items as $item) {
        $cacheableMetadata->addCacheableDependency($item);
        if ($item->getPlugin()->createPlaceholder()) {
          $build['#items'][$item->id()] = [
            '#lazy_builder' => [
              'neo_toolbar.lazy_builders:renderToolbarItem',
              [$toolbarId, $item->id(), $regionId, $isEditMode],
            ],
            '#create_placeholder' => TRUE,
          ];
        }
        else {
          $collection = $item->getElementCollection();
          // @todo See if we can avoid merging both the plugin and the collection
          // since the collection is instantiated by the plugin.
          $cacheableMetadata->addCacheableDependency($item->getPlugin());
          $cacheableMetadata->addCacheableDependency($collection);
          if ($collection->isEmpty()) {
            continue;
          }
          $build['#items'][$item->id()] = $collection->toRenderable();
        }
      }
      if (!empty($build['#items'])) {
        $build = [
          '#theme' => 'neo_toolbar_region',
          '#region' => $region,
        ] + $build;
      }
    }
    $cacheableMetadata->applyTo($build);
    return $build;
  }

  /**
   * Render a single toolbar item via placeholder.
   *
   * @return array
   *   Render array.
   */
  public function renderToolbarItem($toolbarId, $itemId, $regionId, $isEditMode = FALSE): array {
    $build = [];
    /** @var \Drupal\neo_toolbar\ToolbarInterface $toolbar */
    $toolbar = $this->entityTypeManager->getStorage('neo_toolbar')->load($toolbarId);
    $toolbar->setEditMode($isEditMode);
    /** @var \Drupal\neo_toolbar\ToolbarItemInterface $item */
    $item = $this->entityTypeManager->getStorage('neo_toolbar_item')->load($itemId);
    if ($item) {
      $collection = $item->getElementCollection();
      $cacheableMetadata = new CacheableMetadata();
      $cacheableMetadata->addCacheableDependency($item);
      $cacheableMetadata->addCacheableDependency($item->getPlugin());
      $cacheableMetadata->addCacheableDependency($collection);
      if (!$collection->isEmpty()) {
        $build = $collection->toRenderable();
      }
      $cacheableMetadata->applyTo($build);
    }
    return $build;
  }

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks(): array {
    return ['renderToolbarRegion', 'renderToolbarItem'];
  }

}
