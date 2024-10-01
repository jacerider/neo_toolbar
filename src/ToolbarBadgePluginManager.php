<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\neo_toolbar\Attribute\ToolbarBadge;

/**
 * ToolbarBadge plugin manager.
 */
final class ToolbarBadgePluginManager extends DefaultPluginManager {

  /**
   * Constructs the object.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/ToolbarBadge', $namespaces, $module_handler, ToolbarBadgePluginInterface::class, ToolbarBadge::class);
    $this->alterInfo('neo_toolbar_badge_info');
    $this->setCacheBackend($cache_backend, 'neo_toolbar_badge_plugins');
  }

}
