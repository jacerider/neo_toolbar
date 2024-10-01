<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\neo\VisibilityEntityInterface;

/**
 * Provides an interface defining a toolbar entity type.
 */
interface ToolbarInterface extends ConfigEntityInterface, VisibilityEntityInterface {

  /**
   * Sets the toolbar edit mode.
   *
   * @param bool $isEditMode
   *   TRUE if the toolbar is in edit mode, FALSE otherwise.
   *
   * @return $this
   */
  public function setEditMode(bool $isEditMode = TRUE):self;

  /**
   * Checks if the toolbar is in edit mode.
   *
   * @return bool
   *   TRUE if the toolbar is in edit mode, FALSE otherwise.
   */
  public function isEditMode():bool;

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
  public function getItems($regionId = NULL, CacheableMetadata $cacheableMetadata = NULL): array;

  /**
   * Get the toolbar region ids.
   *
   * @return string[]
   *   The region ids.
   */
  public function getRegionIds(): array;

  /**
   * Get the toolbar regions.
   *
   * @return \Drupal\neo_toolbar\ToolbarRegionPluginInterface[]
   *   The regions.
   */
  public function getRegions(): array;

}
