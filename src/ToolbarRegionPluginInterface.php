<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar;

/**
 * Interface for neo_toolbar_region plugins.
 */
interface ToolbarRegionPluginInterface {

  /**
   * Returns the translated plugin label.
   */
  public function label(): string;

  /**
   * Returns the alignment of the region.
   */
  public function getAlignment(): string;

  /**
   * Returns the toolbar id of the region.
   */
  public function getToolbarId(): string|null;

  /**
   * Returns the toolbar item id of the region.
   *
   * This will be null when region does not belong to any toolbar item.
   */
  public function getToolbarItemId(): string|null;

}
