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
   * The memoised toolbar items, or NULL until the pipeline has been run.
   *
   * Nullable because "not computed yet" is a state this property is guarded on,
   * and a declared type that ruled it out made that guard a condition phpstan
   * reports as one that cannot hold.
   *
   * @var \Drupal\neo_toolbar\ToolbarItemInterface[]|null
   */
  protected ?array $items = NULL;

  /**
   * The cacheable metadata for the memoised items.
   *
   * @var \Drupal\Core\Cache\CacheableMetadata|null
   */
  protected ?CacheableMetadata $itemsCacheableMetadata = NULL;

  /**
   * The plugin collection that holds the regions, or NULL until first asked.
   *
   * @var \Drupal\Core\Plugin\DefaultLazyPluginCollection|null
   */
  protected ?DefaultLazyPluginCollection $regionCollection = NULL;

  /**
   * {@inheritdoc}
   *
   * The memo goes when the mode changes. The pipeline is skipped entirely in
   * edit mode, so what it answers is mode-specific while the memo holding it
   * is not: a mode set after a first call used to answer the previous mode's
   * items, and the cacheable metadata beside them recorded an access pass that
   * the new mode does not run. Correctness rested on every call site setting
   * the mode on a freshly-loaded entity — and this entity type declares
   * `static_cache = true`, so the second load in a request is not one.
   *
   * A mode that did not change discards nothing, because the memo it would
   * throw away is still the answer to the question being asked.
   */
  public function setEditMode(bool $isEditMode = TRUE):self {
    $isEditMode = !empty($isEditMode);
    if ($isEditMode !== $this->isEditMode) {
      $this->isEditMode = $isEditMode;
      $this->items = NULL;
      $this->itemsCacheableMetadata = NULL;
    }
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
   *
   * The pipeline this used to run lives on the toolbar repository now. What is
   * left is what this method's interface has always advertised: hold the memo,
   * delegate once, filter to a region, and merge the cacheability the pass
   * collected into whatever the caller passed in.
   */
  public function getItems($regionId = NULL, ?CacheableMetadata $cacheableMetadata = NULL): array {
    if ($this->items === NULL) {
      $this->itemsCacheableMetadata = new CacheableMetadata();
      /** @var \Drupal\neo_toolbar\ToolbarRepository $repository */
      $repository = \Drupal::service('neo_toolbar.repository');
      $this->items = $repository->getToolbarItems($this, $this->itemsCacheableMetadata);
    }

    $items = $this->items;
    if ($regionId) {
      $items = array_filter($items, fn($item) => $item->getRegionId() === $regionId);
    }
    if ($cacheableMetadata && $this->itemsCacheableMetadata) {
      $cacheableMetadata->addCacheableDependency($this->itemsCacheableMetadata);
    }
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
    if ($this->regionCollection === NULL) {
      /** @var \Drupal\neo_toolbar\ToolbarRegionPluginManager $regionManager */
      $regionManager = \Drupal::service('plugin.manager.neo_toolbar_region');
      $configurations = [];
      if ($id = $this->id()) {
        foreach ($regionManager->getDefinitionForToolbar($id) as $pluginId => $definition) {
          $configurations[$pluginId] = [
            'id' => $pluginId,
          ];
        }
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
