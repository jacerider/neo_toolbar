<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;
use Drupal\Core\Plugin\ContextAwarePluginInterface;
use Drupal\Core\Plugin\PluginFormFactoryInterface;
use Drupal\Core\Plugin\PluginWithFormsInterface;
use Drupal\neo\VisibilityFormTrait;
use Drupal\neo_toolbar\Entity\ToolbarItem;
use Drupal\neo_toolbar\ToolbarItemInterface;
use Drupal\neo_toolbar\ToolbarItemPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Neo Toolbar Item form.
 */
final class ToolbarItemForm extends EntityForm {
  use VisibilityFormTrait;

  /**
   * The block storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected $storage;

  /**
   * The plugin form manager.
   *
   * @var \Drupal\Core\Plugin\PluginFormFactoryInterface
   */
  protected $pluginFormFactory;

  /**
   * The entity.
   *
   * @var \Drupal\neo_toolbar\ToolbarItemInterface
   */
  protected $entity;

  /**
   * Constructs a BlockForm object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity manager.
   * @param \Drupal\Core\Plugin\PluginFormFactoryInterface $plugin_form_manager
   *   The plugin form manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, PluginFormFactoryInterface $plugin_form_manager) {
    $this->storage = $entity_type_manager->getStorage('neo_toolbar_item');
    $this->pluginFormFactory = $plugin_form_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('plugin_form.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);
    $plugin = $this->entity->getPlugin();

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $this->entity->label(),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => !$this->entity->isNew() ? $this->entity->id() : $this->getUniqueMachineName($this->entity),
      '#machine_name' => [
        'exists' => [ToolbarItem::class, 'load'],
      ],
      '#disabled' => !$this->entity->isNew(),
    ];

    $form['type'] = [
      '#type' => 'item',
      '#title' => $this->t('Item type'),
      '#plain_text' => $plugin->label(),
    ];

    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $this->entity->status(),
    ];

    $form['#tree'] = TRUE;
    $form['settings'] = [];
    $subformState = SubformState::createForSubform($form['settings'], $form, $form_state);
    $form['settings'] = $this->getPluginForm($plugin)->buildConfigurationForm($form['settings'], $subformState, $form);

    $form['visibility'] = $this->buildVisibility([], $form_state);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    $plugin = $this->entity->getPlugin();
    if ($settings = $form_state->getValue('settings')) {
      $plugin->setConfiguration($settings);
    }
    $this->getPluginForm($plugin)->validateConfigurationForm($form['settings'], SubformState::createForSubform($form['settings'], $form, $form_state));
    $this->validateVisibility($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);
    $plugin = $this->entity->getPlugin();
    $subformState = SubformState::createForSubform($form['settings'], $form, $form_state);
    $this->getPluginForm($plugin)->submitConfigurationForm($form['settings'], $subformState);
    // If this block is context-aware, set the context mapping.
    if ($plugin instanceof ContextAwarePluginInterface && $plugin->getContextDefinitions()) {
      $context_mapping = $subformState->getValue('context_mapping', []);
      $plugin->setContextMapping($context_mapping);
    }
    $this->submitVisibility($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    if ($this->entity->isNew()) {
      $weight = 0;
      foreach ($this->entity->getToolbar()->getItems() as $item) {
        $weight = max($weight, $item->getWeight() + 1);
      }
      $this->entity->set('weight', $weight);
    }
    $result = parent::save($form, $form_state);
    $message_args = ['%label' => $this->entity->label()];
    $this->messenger()->addStatus(
      match($result) {
        \SAVED_NEW => $this->t('Created new example %label.', $message_args),
        \SAVED_UPDATED => $this->t('Updated example %label.', $message_args),
      }
    );
    $form_state->setRedirectUrl($this->entity->toUrl('collection', [
      'neo_toolbar' => $this->entity->getToolbarId(),
    ]));
    return $result;
  }

  /**
   * Generates a unique machine name for a block.
   *
   * @param \Drupal\neo_toolbar\ToolbarItemInterface $item
   *   The item entity.
   *
   * @return string
   *   Returns the unique name.
   */
  public function getUniqueMachineName(ToolbarItemInterface $item) {
    $suggestion = $item->getPlugin()->getMachineNameSuggestion();

    // Get all the blocks which starts with the suggested machine name.
    $query = $this->storage->getQuery();
    $query->condition('id', $suggestion, 'CONTAINS');
    $item_ids = $query->accessCheck(FALSE)->execute();

    $item_ids = array_map(function ($item_id) {
      $parts = explode('.', $item_id);
      return end($parts);
    }, $item_ids);

    // Iterate through potential IDs until we get a new one. E.g.
    // 'plugin', 'plugin_2', 'plugin_3', etc.
    $count = 1;
    $machine_default = $suggestion;
    while (in_array($machine_default, $item_ids)) {
      $machine_default = $suggestion . '_' . ++$count;
    }
    return $machine_default;
  }

  /**
   * Retrieves the plugin form for a given item and operation.
   *
   * @param \Drupal\neo_toolbar\ToolbarItemPluginInterface $item
   *   The item plugin.
   *
   * @return \Drupal\Core\Plugin\PluginFormInterface
   *   The plugin form for the item.
   */
  protected function getPluginForm(ToolbarItemPluginInterface $item) {
    if ($item instanceof PluginWithFormsInterface) {
      return $this->pluginFormFactory->createInstance($item, 'configure');
    }
    return $item;
  }

}
