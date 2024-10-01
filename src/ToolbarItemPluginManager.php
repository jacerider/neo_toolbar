<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar;

use Drupal\Component\Plugin\CategorizingPluginManagerInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\CategorizingPluginManagerTrait;
use Drupal\Core\Plugin\Context\ContextAwarePluginManagerInterface;
use Drupal\Core\Plugin\Context\ContextAwarePluginManagerTrait;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\neo_toolbar\Attribute\ToolbarItem;

/**
 * ToolbarItem plugin manager.
 */
final class ToolbarItemPluginManager extends DefaultPluginManager implements ContextAwarePluginManagerInterface, CategorizingPluginManagerInterface {

  use CategorizingPluginManagerTrait {
    getSortedDefinitions as traitGetSortedDefinitions;
  }
  use ContextAwarePluginManagerTrait;

  /**
   * {@inheritdoc}
   */
  protected $defaults = [
    'id' => '',
    'label' => '',
    'description' => '',
    'region_create' => FALSE,
  ];

  /**
   * Constructs the object.
   */
  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct('Plugin/ToolbarItem', $namespaces, $module_handler, ToolbarItemPluginInterface::class, ToolbarItem::class);
    $this->alterInfo('neo_toolbar_item_info');
    $this->setCacheBackend($cache_backend, 'neo_toolbar_item_plugins');
  }

  /**
   * {@inheritdoc}
   */
  public function processDefinition(&$definition, $plugin_id) {
    parent::processDefinition($definition, $plugin_id);
    $this->processDefinitionCategory($definition);
  }

  /**
   * {@inheritdoc}
   */
  public function getSortedDefinitions(array $definitions = NULL, $show_hidden = FALSE) {
    // Sort the plugins first by category, then by admin label.
    $definitions = $this->traitGetSortedDefinitions($definitions, 'label');
    // Do not display the 'broken' plugin in the UI.
    unset($definitions['broken']);

    if (!$show_hidden) {
      // Filter out definitions that can not be configured in Field UI.
      $definitions = array_filter($definitions, function ($definition) {
        return empty($definition['no_ui']);
      });
    }

    return $definitions;
  }

  /**
   * {@inheritdoc}
   */
  public function getFallbackPluginId($plugin_id, array $configuration = []) {
    return 'broken';
  }

}
