<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar\Entity;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Plugin\DefaultLazyPluginCollection;
use Drupal\neo\VisibilityEntityTrait;
use Drupal\neo_toolbar\ToolbarInterface;

/**
 * Defines the toolbar entity type.
 *
 * @ConfigEntityType(
 *   id = "neo_toolbar",
 *   label = @Translation("Toolbar"),
 *   label_collection = @Translation("Toolbars"),
 *   label_singular = @Translation("toolbar"),
 *   label_plural = @Translation("toolbars"),
 *   label_count = @PluralTranslation(
 *     singular = "@count toolbar",
 *     plural = "@count toolbars",
 *   ),
 *   handlers = {
 *     "access" = "Drupal\neo_toolbar\ToolbarAccessControlHandler",
 *     "list_builder" = "Drupal\neo_toolbar\ToolbarListBuilder",
 *     "form" = {
 *       "add" = "Drupal\neo_toolbar\Form\ToolbarForm",
 *       "edit" = "Drupal\neo_toolbar\Form\ToolbarForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm",
 *     },
 *   },
 *   config_prefix = "neo_toolbar",
 *   static_cache = true,
 *   admin_permission = "administer neo_toolbar",
 *   links = {
 *     "collection" = "/admin/config/neo/toolbar",
 *     "add-form" = "/admin/config/neo/toolbar/add",
 *     "edit-form" = "/admin/config/neo/toolbar/{neo_toolbar}",
 *     "delete-form" = "/admin/config/neo/toolbar/{neo_toolbar}/delete",
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *     "weight" = "weight"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "weight",
 *     "visibility",
 *   },
 * )
 */
final class Toolbar extends ConfigEntityBase implements ToolbarInterface {
  use VisibilityEntityTrait;

  /**
   * The toolbar id.
   */
  protected string $id;

  /**
   * The toolbar label.
   */
  protected string $label;

  /**
   * The toolbar weight.
   *
   * @var int
   */
  protected $weight = 0;

  /**
   * Edit mode flag.
   *
   * @var bool
   */
  protected $isEditMode = FALSE;

  /**
   * The toolbar items.
   *
   * @var \Drupal\neo_toolbar\ToolbarItemInterface[]
   */
  protected $items;

  /**
   * The plugin collection that holds the regions.
   *
   * @var \Drupal\Core\Plugin\DefaultLazyPluginCollection
   */
  protected $regionCollection;

  /**
   * {@inheritdoc}
   */
  public function setEditMode(bool $isEditMode = TRUE):self {
    $this->isEditMode = !empty($isEditMode);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isEditMode():bool {
    return $this->isEditMode === TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function getItems($regionId = NULL, CacheableMetadata $cacheableMetadata = NULL): array {
    if (!isset($this->items)) {
      $this->items = [];
      $ids = $this->entityTypeManager()->getStorage('neo_toolbar_item')->getQuery()
        ->accessCheck(TRUE)
        ->condition('toolbar', $this->id)
        ->condition('status', TRUE)
        ->sort('weight')
        ->execute();
      if ($ids) {
        $this->items = $this->entityTypeManager()->getStorage('neo_toolbar_item')->loadMultiple($ids);
      }
    }
    $items = $this->items;
    if ($regionId) {
      $items = array_filter($this->items, fn($item) => $item->getRegionId() === $regionId);
    }
    $items = array_filter($items, function ($item) use ($cacheableMetadata) {
      /** @var \Drupal\neo_toolbar\ToolbarItemInterface $item */
      $access = $item->access('view', NULL, TRUE);
      if ($cacheableMetadata) {
        $cacheableMetadata->addCacheableDependency($item);
        $cacheableMetadata->addCacheableDependency($access);
      }
      return $this->isEditMode() || $access->isAllowed();
    });
    return $items;
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginCollections() {
    return [
      'visibility' => $this->getVisibilityConditions(),
      'regions' => $this->getRegionCollection(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getRegionIds(): array {
    $regions = [];
    foreach ($this->getRegionCollection() as $region) {
      $regions[] = $region->getPluginId();
    }
    return $regions;
  }

  /**
   * {@inheritdoc}
   */
  public function getRegions(): array {
    $regions = [];
    foreach ($this->getRegionCollection() as $region) {
      $regions[$region->getPluginId()] = $region;
    }
    return $regions;
  }

  /**
   * {@inheritdoc}
   */
  public function getRegionCollection() {
    if (!$this->regionCollection) {
      /** @var \Drupal\neo_toolbar\ToolbarRegionPluginManager $regionManager */
      $regionManager = \Drupal::service('plugin.manager.neo_toolbar_region');
      $configurations = [];
      foreach ($regionManager->getDefinitionForToolbar($this->id()) as $pluginId => $definition) {
        $configurations[$pluginId] = [
          'id' => $pluginId,
        ];
      }
      $this->regionCollection = new DefaultLazyPluginCollection($regionManager, $configurations);
    }
    return $this->regionCollection;
  }

  /**
   * Sorts active toolbars by weight; sorts inactive toolbars by name.
   */
  public static function sort(ConfigEntityInterface $a, ConfigEntityInterface $b) {
    // Separate enabled from disabled.
    $status = (int) $b->status() - (int) $a->status();
    if ($status !== 0) {
      return $status;
    }

    // Sort by weight.
    $weight = $a->get('weight') - $b->get('weight');
    if ($weight) {
      return $weight;
    }

    // Sort by label.
    return strcmp($a->label(), $b->label());
  }

}
