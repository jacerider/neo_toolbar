<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar\Entity;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Plugin\DefaultSingleLazyPluginCollection;
use Drupal\neo\VisibilityEntityTrait;
use Drupal\neo_toolbar\ToolbarInterface;
use Drupal\neo_toolbar\ToolbarItemCollection;
use Drupal\neo_toolbar\ToolbarItemElement;
use Drupal\neo_toolbar\ToolbarItemInterface;
use Drupal\neo_toolbar\ToolbarItemPluginInterface;
use Drupal\neo_toolbar\ToolbarRegionPluginInterface;

/**
 * Defines the Toolbar item entity type.
 *
 * @ConfigEntityType(
 *   id = "neo_toolbar_item",
 *   label = @Translation("Toolbar Item"),
 *   label_collection = @Translation("Toolbar Items"),
 *   label_singular = @Translation("Toolbar item"),
 *   label_plural = @Translation("Toolbar items"),
 *   label_count = @PluralTranslation(
 *     singular = "@count Toolbar item",
 *     plural = "@count Toolbar items",
 *   ),
 *   handlers = {
 *     "access" = "Drupal\neo_toolbar\ToolbarItemAccessControlHandler",
 *     "list_builder" = "Drupal\neo_toolbar\ToolbarItemListBuilder",
 *     "form" = {
 *       "add" = "Drupal\neo_toolbar\Form\ToolbarItemForm",
 *       "edit" = "Drupal\neo_toolbar\Form\ToolbarItemForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm",
 *     },
 *   },
 *   config_prefix = "neo_toolbar_item",
 *   admin_permission = "administer neo_toolbar",
 *   links = {
 *     "collection" = "/admin/config/neo/toolbar/{neo_toolbar}/items",
 *     "add-form" = "/admin/config/neo/toolbar/{neo_toolbar}/add/{region}/{plugin_id}",
 *     "edit-form" = "/admin/structure/neo-toolbar-item/{neo_toolbar_item}",
 *     "delete-form" = "/admin/structure/neo-toolbar-item/{neo_toolbar_item}/delete",
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "toolbar",
 *     "region",
 *     "plugin",
 *     "weight",
 *     "settings",
 *     "visibility",
 *   },
 * )
 */
final class ToolbarItem extends ConfigEntityBase implements ToolbarItemInterface {
  use VisibilityEntityTrait;

  /**
   * The toolbar item ID.
   */
  protected string $id;

  /**
   * The toolbar item label.
   */
  protected string $label;

  /**
   * The toolbar id.
   */
  protected string $toolbar;

  /**
   * The toolbar region.
   */
  protected string $region;

  /**
   * The toolbar plugin.
   */
  protected string $plugin;

  /**
   * The toolbar item weight.
   *
   * @var int
   */
  protected $weight = 0;

  /**
   * The plugin collection that holds the setting plugin for this entity.
   *
   * @var \Drupal\Core\Plugin\DefaultSingleLazyPluginCollection
   */
  protected $pluginCollection;

  /**
   * The collection that holds the item elements.
   *
   * @var \Drupal\neo_toolbar\ToolbarItemCollection
   */
  protected $elementCollection;

  /**
   * {@inheritdoc}
   */
  protected function urlRouteParameters($rel) {
    $uri_route_parameters = parent::urlRouteParameters($rel);
    $uri_route_parameters['neo_toolbar'] = $this->getToolbarId();
    return $uri_route_parameters;
  }

  /**
   * {@inheritdoc}
   */
  public function getToolbarId(): string {
    return $this->get('toolbar');
  }

  /**
   * {@inheritdoc}
   */
  public function getToolbar(): ToolbarInterface|null {
    return \Drupal::entityTypeManager()->getStorage('neo_toolbar')->load($this->getToolbarId());
  }

  /**
   * {@inheritdoc}
   */
  public function getRegionId(): string {
    return $this->get('region');
  }

  /**
   * {@inheritdoc}
   */
  public function getRegion(): ToolbarRegionPluginInterface {
    /** @var \Drupal\neo_toolbar\ToolbarRegionPluginManager $service */
    $service = \Drupal::service('plugin.manager.neo_toolbar_region');
    return $service->createInstance($this->getRegionId());
  }

  /**
   * {@inheritdoc}
   */
  public function getWeight(): int {
    return $this->weight;
  }

  /**
   * {@inheritdoc}
   */
  public function getSettings(): array {
    return $this->get('settings') ?? [];
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginId(): string {
    return $this->plugin;
  }

  /**
   * {@inheritdoc}
   */
  public function getPlugin(): ToolbarItemPluginInterface {
    return $this->getPluginCollection()->get($this->getPluginId());
  }

  /**
   * Encapsulates the creation of the setting's LazyPluginCollection.
   *
   * @return \Drupal\Component\Plugin\LazyPluginCollection
   *   The settings's plugin collection.
   */
  protected function getPluginCollection() {
    if (!$this->pluginCollection) {
      $settings = [
        'id' => $this->id(),
        'title' => $this->label(),
        'alignment' => $this->getRegion()->getAlignment(),
      ] + $this->getSettings();
      $this->pluginCollection = new DefaultSingleLazyPluginCollection(\Drupal::service('plugin.manager.neo_toolbar_item'), $this->plugin, $settings);
      $plugin = $this->pluginCollection->get($this->plugin);
      $plugin->addCacheableDependency($this);
    }
    return $this->pluginCollection;
  }

  /**
   * {@inheritdoc}
   */
  public function getPluginCollections() {
    return [
      'visibility' => $this->getVisibilityConditions(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getElementCollection() {
    if (!$this->elementCollection) {
      $this->elementCollection = new ToolbarItemCollection($this->getRegion()->getAlignment(), $this->getPlugin()->getStyle(), $this->getWeight());
      foreach ($this->getPlugin()->getElements() as $element) {
        if ($element instanceof ToolbarItemElement) {
          $this->elementCollection->add($element);
        }
      }
    }
    return $this->elementCollection;
  }

  /**
   * {@inheritdoc}
   */
  protected function getListCacheTagsToInvalidate() {
    $tags = parent::getListCacheTagsToInvalidate();
    $tags[] = 'neo_toolbar_region_plugins';
    if ($toolbar = $this->getToolbar()) {
      $tags = Cache::mergeTags($tags, $toolbar->getCacheTagsToInvalidate());
    }
    return $tags;
  }

  /**
   * {@inheritdoc}
   */
  public function calculateDependencies() {
    parent::calculateDependencies();
    $this->addDependency('module', $this->getPlugin()->getProvider());
    $this->addDependency('config', $this->getToolbar()->getConfigDependencyName());
    if ($regionItemId = $this->getRegion()->getToolbarItemId()) {
      $this->addDependency('config', 'neo_toolbar.neo_toolbar_item.' . $regionItemId);
    }
    return $this;
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
