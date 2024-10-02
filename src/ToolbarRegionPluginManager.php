<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Plugin\Discovery\ContainerDerivativeDiscoveryDecorator;
use Drupal\Core\Plugin\Discovery\YamlDiscovery;
use Drupal\Core\Plugin\Factory\ContainerFactory;

/**
 * Defines a plugin manager to deal with neo_toolbar_regions.
 *
 * Modules can define neo_toolbar_regions in a
 * MODULE_NAME.neo_toolbar_regions.yml file contained in the module's base
 * directory. Each neo_toolbar_region has the following structure:
 *
 * @code
 *   MACHINE_NAME:
 *     label: STRING
 * @endcode
 *
 * @see \Drupal\neo_toolbar\ToolbarRegionDefault
 * @see \Drupal\neo_toolbar\ToolbarRegionInterface
 */
final class ToolbarRegionPluginManager extends DefaultPluginManager {

  /**
   * {@inheritdoc}
   */
  protected $defaults = [
    'id' => '',
    'label' => '',
    'alignment' => 'vertical',
    'position' => 'start',
    'toolbar' => NULL,
    'toolbar_item' => NULL,
    'weight' => 0,
    'class' => ToolbarRegionPluginDefault::class,
  ];

  /**
   * {@inheritdoc}
   */
  protected function getType() {
    return 'neo_toolbar_region';
  }

  /**
   * Constructs ToolbarRegionPluginManager object.
   */
  public function __construct(ModuleHandlerInterface $module_handler, CacheBackendInterface $cache_backend) {
    $this->factory = new ContainerFactory($this);
    $this->moduleHandler = $module_handler;
    $this->alterInfo('neo_toolbar_region_info');
    $this->setCacheBackend($cache_backend, 'neo_toolbar_region_plugins', ['neo_toolbar_region_plugins']);
  }

  /**
   * {@inheritdoc}
   */
  protected function getDiscovery(): ContainerDerivativeDiscoveryDecorator {
    if (!isset($this->discovery)) {
      $discovery = new YamlDiscovery('neo_toolbar_regions', $this->moduleHandler->getModuleDirectories());
      $discovery->addTranslatableProperty('label', 'label_context');
      $this->discovery = new ContainerDerivativeDiscoveryDecorator($discovery);
    }
    return $this->discovery;
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinitions($show_hidden = TRUE) {
    $definitions = parent::getDefinitions();
    uasort($definitions, [
      'Drupal\Component\Utility\SortArray',
      'sortByWeightElement',
    ]);
    return $definitions;
  }

  /**
   * Get definitions for a specific toolbar.
   *
   * @param string $toolbarId
   *   The toolbar ID.
   *
   * @return array
   *   The definitions.
   */
  public function getDefinitionForToolbar(string $toolbarId) {
    $definitions = $this->getDefinitions();
    $definitions = array_filter($definitions, function ($definition) use ($toolbarId) {
      return empty($definition['toolbar']) || $definition['toolbar'] === $toolbarId;
    });
    return $definitions;
  }

}
