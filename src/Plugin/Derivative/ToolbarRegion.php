<?php

namespace Drupal\neo_toolbar\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\neo_toolbar\ToolbarItemPluginManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Retrieves block plugin definitions for all toolbar region items.
 */
final class ToolbarRegion extends DeriverBase implements ContainerDeriverInterface {
  use StringTranslationTrait;

  /**
   * The toolbar item manager.
   *
   * @var \Drupal\neo_toolbar\ToolbarItemPluginManager
   */
  protected $toolbarItemManager;

  /**
   * The toolbar region item storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $neoToolbarItemStorage;

  /**
   * Constructs a NeoToolbarRegion object.
   *
   * @param \Drupal\neo_toolbar\ToolbarItemPluginManager $toolbar_item_manager
   *   The toolbar item manager.
   * @param \Drupal\Core\Entity\EntityStorageInterface $toolbar_item_storage
   *   The toolbar item storage.
   */
  public function __construct(ToolbarItemPluginManager $toolbar_item_manager, EntityStorageInterface $toolbar_item_storage) {
    $this->toolbarItemManager = $toolbar_item_manager;
    $this->neoToolbarItemStorage = $toolbar_item_storage;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    $entity_type_manager = $container->get('entity_type.manager');
    return new static(
      $container->get('plugin.manager.neo_toolbar_item'),
      $entity_type_manager->getStorage('neo_toolbar_item'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    $regionSupport = array_filter($this->toolbarItemManager->getDefinitions(), function ($definition) {
      return $definition['region_create'] === TRUE;
    });
    $this->derivatives = [];
    if ($regionSupport) {
      /** @var \Drupal\neo_toolbar\ToolbarItemInterface[] $items */
      $items = $this->neoToolbarItemStorage->loadByProperties(['plugin' => array_keys($regionSupport)]);
      $this->derivatives = [];
      foreach ($items as $item) {
        $derivative = [
          'label' => $this->t('Item: @label', [
            '@label' => $item->label(),
          ]),
          'toolbar' => $item->getToolbarId(),
          'toolbar_item' => $item->id(),
          'weight' => $base_plugin_definition['weight'] + $item->getWeight(),
        ] + $base_plugin_definition;
        $this->derivatives[$item->id()] = $derivative;
      }
    }
    return $this->derivatives;
  }

}
