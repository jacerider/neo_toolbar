<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\Core\Template\Attribute;
use Drupal\neo\VisibilityEntityInterface;

/**
 * Provides an interface defining a neo toolbar item entity type.
 */
interface ToolbarItemInterface extends ConfigEntityInterface, VisibilityEntityInterface {

  /**
   * Gets the toolbar id.
   *
   * @return string
   *   The toolbar id.
   */
  public function getToolbarId(): string;

  /**
   * Gets the toolbar.
   *
   * @return \Drupal\neo_toolbar\ToolbarInterface|null
   *   The toolbar.
   */
  public function getToolbar(): ToolbarInterface|null;

  /**
   * Gets the region id.
   *
   * @return string
   *   The region id.
   */
  public function getRegionId(): string;

  /**
   * Gets the region.
   *
   * @return \Drupal\neo_toolbar\ToolbarRegionPluginInterface
   *   The region.
   */
  public function getRegion(): ToolbarRegionPluginInterface;

  /**
   * Gets the weight.
   *
   * @return int
   *   The weight.
   */
  public function getWeight(): int;

  /**
   * Gets the plugin id.
   *
   * @return string
   *   The plugin id.
   */
  public function getPluginId(): string;

  /**
   * Gets the plugin.
   *
   * @return \Drupal\neo_toolbar\ToolbarItemPluginInterface
   *   The plugin.
   */
  public function getPlugin(): ToolbarItemPluginInterface;

  /**
   * Get the element collection.
   *
   * @return \Drupal\neo_toolbar\ToolbarItemCollection
   *   The element collection.
   */
  public function getElementCollection();

}
