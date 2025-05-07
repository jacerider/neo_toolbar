<?php

namespace Drupal\neo_toolbar;

/**
 * A trait that provides help with region items.
 */
trait ToolbarItemRegionTrait {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Get the toolbar items.
   *
   * @param string|null $regionId
   *   The region id.
   * @param \Drupal\Core\Cache\CacheableMetadata|null $cacheableMetadata
   *   The cacheable metadata.
   *
   * @return \Drupal\neo_toolbar\ToolbarItemInterface[]
   *   The items.
   */
  protected array $regionItems;

  /**
   * Process the element and add region items as modal.
   *
   * @param ToolbarItemElement $element
   *   The toolbar item element.
   * @param mixed $title
   *   The title as string or render array.
   * @param array $build
   *   The build array.
   */
  public function processRegionElementAsModal(ToolbarItemElement $element, mixed $title = NULL, array $build = []) {
    $modalCollections = $this->getRegionElementCollections();
    if (!empty($modalCollections) || !empty($build)) {
      $modalBuild = [];
      foreach ($modalCollections as $collection) {
        $modalBuild[] = $collection->toRenderable();
      }
      $build['items'] = $modalBuild;
      $element->setModal($build, $title);
    }

  }

  /**
   * Retrieves the toolbar items for the region.
   *
   * @param string|null $regionId
   *   The region ID.
   *
   * @return \Drupal\neo_toolbar\ToolbarItemInterface[]
   *   The region items.
   */
  protected function getRegionItems($regionId = NULL): array {
    if (!isset($this->regionItems)) {
      $this->regionItems = [];
      $regionId = $regionId ?? ($this->configuration['id'] ? 'item:' . $this->configuration['id'] : NULL);
      if ($toolbar = $this->getRegionToolbar()) {
        $this->regionItems = $toolbar->getItems($regionId);
      }
    }
    return $this->regionItems;
  }

  /**
   * Retrieves the toolbar element collections for the region.
   *
   * @param string|null $regionId
   *   The region ID.
   *
   * @return array
   *   The region elements.
   */
  protected function getRegionElementCollections($regionId = NULL): array {
    $collections = [];
    foreach ($this->getRegionItems($regionId) as $item) {
      $collections[] = $item->getElementCollection()->setStyle('modal');
    }
    return $collections;
  }

  /**
   * Retrieves the toolbar.
   *
   * @return \Drupal\neo_toolbar\ToolbarInterface|null
   *   The toolbar.
   */
  protected function getRegionToolbar(): ?ToolbarInterface {
    if (isset($this->toolbar) && $this->toolbar instanceof ToolbarInterface) {
      return $this->toolbar;
    }
    return NULL;
  }

}
