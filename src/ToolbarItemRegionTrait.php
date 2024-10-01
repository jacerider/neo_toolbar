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
    if (!empty($modalCollections)) {
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
    $regionId = $regionId ?? ($this->configuration['id'] ? 'item:' . $this->configuration['id'] : NULL);
    $storage = $this->getEntityTypeManager()->getStorage('neo_toolbar_item');
    return $storage->loadByProperties(['region' => $regionId]);
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
   * Retrieves the Entity Type Manager for the Entity.
   *
   * @return \Drupal\Core\Entity\EntityTypeManagerInterface|object|null
   *   The entity type manager.
   */
  protected function getEntityTypeManager() {
    if (!isset($this->entityTypeManager)) {
      $this->entityTypeManager = \Drupal::entityTypeManager();
    }
    return $this->entityTypeManager;
  }

}
