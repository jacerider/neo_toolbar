<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Plugin\PluginFormInterface;

/**
 * Interface for neo_toolbar_badge plugins.
 */
interface ToolbarBadgePluginInterface extends ConfigurableInterface, PluginFormInterface, PluginInspectionInterface {

  /**
   * Returns the translated plugin label.
   */
  public function label(): string;

  /**
   * Returns the badge value.
   *
   * @return string|int|null
   *   The badge value.
   */
  public function getBadge(ToolbarItemElement $element): string|int|null;

  /**
   * Returns the badge scheme.
   *
   * @return string
   *   The badge scheme.
   */
  public function getBadgeScheme(): string;

}
