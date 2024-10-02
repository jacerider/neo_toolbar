<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar;

use Drupal\Core\Plugin\PluginBase;

/**
 * Default class used for neo_toolbar_regions plugins.
 */
final class ToolbarRegionPluginDefault extends PluginBase implements ToolbarRegionPluginInterface {

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    // The title from YAML file discovery may be a TranslatableMarkup object.
    return (string) $this->pluginDefinition['label'];
  }

  /**
   * {@inheritdoc}
   */
  public function getAlignment(): string {
    return $this->pluginDefinition['alignment'];
  }

  /**
   * {@inheritdoc}
   */
  public function getPosition(): string {
    return $this->pluginDefinition['position'];
  }

  /**
   * {@inheritdoc}
   */
  public function getToolbarId(): string|null {
    return $this->pluginDefinition['toolbar'];
  }

  /**
   * {@inheritdoc}
   */
  public function getToolbarItemId(): string|null {
    return $this->pluginDefinition['toolbar_item'];
  }

}
