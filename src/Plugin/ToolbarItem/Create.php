<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar\Plugin\ToolbarItem;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\neo_icon\IconRepositoryTrait;
use Drupal\neo_toolbar\Attribute\ToolbarItem;
use Drupal\neo_toolbar\ToolbarItemCollection;
use Drupal\neo_toolbar\ToolbarItemColorSchemeTrait;
use Drupal\neo_toolbar\ToolbarItemElement;
use Drupal\neo_toolbar\ToolbarItemLinkTrait;
use Drupal\neo_toolbar\ToolbarItemPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_toolbar_item.
 */
#[ToolbarItem(
  id: 'create',
  label: new TranslatableMarkup('Create'),
  description: new TranslatableMarkup('Links to create content, taxonomy terms, and media.'),
)]
class Create extends ToolbarItemPluginBase {
  use ToolbarItemLinkTrait;
  use IconRepositoryTrait;
  use ToolbarItemColorSchemeTrait;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Creates a toolbar item instance.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    TransliterationInterface $transliteration,
    ?EntityTypeManagerInterface $entity_type_manager = NULL,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $transliteration);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('transliteration'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'icon' => 'plus-circle',
      'scheme' => '',
      'node_enabled' => TRUE,
      'node_bundles' => [],
      'node_exclude' => FALSE,
      'taxonomy_enabled' => FALSE,
      'taxonomy_bundles' => [],
      'taxonomy_exclude' => FALSE,
      'media_enabled' => FALSE,
      'media_bundles' => [],
      'media_exclude' => FALSE,
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function itemForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $form = parent::itemForm($form, $form_state, $complete_form);

    if (empty($complete_form['label']['#default_value'])) {
      $complete_form['label']['#default_value'] = 'Create';
    }

    $form['icon'] = [
      '#type' => 'neo_icon_select',
      '#title' => $this->t('Icon'),
      '#required' => TRUE,
      '#description' => $this->t('The icon of the modal.'),
      '#default_value' => $this->configuration['icon'],
    ];

    $form['scheme'] = $this->getSchemeElement($this->configuration['scheme']);

    $form['entity_types'] = [
      '#type' => 'vertical_tabs',
      '#title' => $this->t('Entity types'),
    ];

    // Node types.
    if ($this->entityTypeManager->hasDefinition('node_type')) {
      $form['node'] = [
        '#type' => 'details',
        '#title' => $this->t('Content'),
        '#group' => 'settings][entity_types',
      ];
      $form['node']['node_enabled'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable content'),
        '#default_value' => $this->configuration['node_enabled'],
      ];
      /** @var \Drupal\node\NodeTypeInterface[] $node_types */
      $node_types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
      $node_options = [];
      foreach ($node_types as $type) {
        $isMicrocontent = $type->getThirdPartySettings('micronode')['micronode_is_microcontent'] ?? FALSE;
        if ($isMicrocontent) {
          continue;
        }
        $node_options[$type->id()] = $type->label();
      }
      asort($node_options);
      $form['node']['node_bundles'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Content types'),
        '#description' => $this->t('Select which content types to include. Leave empty to include all.'),
        '#options' => $node_options,
        '#default_value' => $this->configuration['node_bundles'],
        '#states' => [
          'visible' => [
            ':input[name="settings[node][node_enabled]"]' => ['checked' => TRUE],
          ],
        ],
      ];
      $form['node']['node_exclude'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Exclude selected content types'),
        '#description' => $this->t('If checked, the selected content types will be excluded instead of included.'),
        '#default_value' => $this->configuration['node_exclude'],
        '#states' => [
          'visible' => [
            ':input[name="settings[node][node_enabled]"]' => ['checked' => TRUE],
          ],
        ],
      ];
    }

    // Media types.
    if ($this->entityTypeManager->hasDefinition('media_type')) {
      $form['media'] = [
        '#type' => 'details',
        '#title' => $this->t('Media'),
        '#group' => 'settings][entity_types',
      ];
      $form['media']['media_enabled'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable media'),
        '#default_value' => $this->configuration['media_enabled'],
      ];
      $media_types = $this->entityTypeManager->getStorage('media_type')->loadMultiple();
      $media_options = [];
      foreach ($media_types as $type) {
        $media_options[$type->id()] = $type->label();
      }
      asort($media_options);
      $form['media']['media_bundles'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Media types'),
        '#description' => $this->t('Select which media types to include. Leave empty to include all.'),
        '#options' => $media_options,
        '#default_value' => $this->configuration['media_bundles'],
        '#states' => [
          'visible' => [
            ':input[name="settings[media][media_enabled]"]' => ['checked' => TRUE],
          ],
        ],
      ];
      $form['media']['media_exclude'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Exclude selected media types'),
        '#description' => $this->t('If checked, the selected media types will be excluded instead of included.'),
        '#default_value' => $this->configuration['media_exclude'],
        '#states' => [
          'visible' => [
            ':input[name="settings[media][media_enabled]"]' => ['checked' => TRUE],
          ],
        ],
      ];
    }

    // Taxonomy vocabularies.
    if ($this->entityTypeManager->hasDefinition('taxonomy_vocabulary')) {
      $form['taxonomy'] = [
        '#type' => 'details',
        '#title' => $this->t('Taxonomy'),
        '#group' => 'settings][entity_types',
      ];
      $form['taxonomy']['taxonomy_enabled'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Enable taxonomy'),
        '#default_value' => $this->configuration['taxonomy_enabled'],
      ];
      $vocabularies = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->loadMultiple();
      $vocab_options = [];
      foreach ($vocabularies as $vocab) {
        $vocab_options[$vocab->id()] = $vocab->label();
      }
      asort($vocab_options);
      $form['taxonomy']['taxonomy_bundles'] = [
        '#type' => 'checkboxes',
        '#title' => $this->t('Taxonomy vocabularies'),
        '#description' => $this->t('Select which vocabularies to include. Leave empty to include all.'),
        '#options' => $vocab_options,
        '#default_value' => $this->configuration['taxonomy_bundles'],
        '#states' => [
          'visible' => [
            ':input[name="settings[taxonomy][taxonomy_enabled]"]' => ['checked' => TRUE],
          ],
        ],
      ];
      $form['taxonomy']['taxonomy_exclude'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Exclude selected vocabularies'),
        '#description' => $this->t('If checked, the selected vocabularies will be excluded instead of included.'),
        '#default_value' => $this->configuration['taxonomy_exclude'],
        '#states' => [
          'visible' => [
            ':input[name="settings[taxonomy][taxonomy_enabled]"]' => ['checked' => TRUE],
          ],
        ],
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function itemValidate(array $form, FormStateInterface $form_state): void {
    $node = $form_state->getValue('node', []);
    $values['icon'] = $form_state->getValue('icon');
    $values['scheme'] = $form_state->getValue('scheme');
    $values['node_enabled'] = (bool) ($node['node_enabled'] ?? FALSE);
    $values['node_bundles'] = array_filter($node['node_bundles'] ?? []);
    $values['node_exclude'] = (bool) ($node['node_exclude'] ?? FALSE);
    $media = $form_state->getValue('media', []);
    $values['media_enabled'] = (bool) ($media['media_enabled'] ?? FALSE);
    $values['media_bundles'] = array_filter($media['media_bundles'] ?? []);
    $values['media_exclude'] = (bool) ($media['media_exclude'] ?? FALSE);
    $taxonomy = $form_state->getValue('taxonomy', []);
    $values['taxonomy_enabled'] = (bool) ($taxonomy['taxonomy_enabled'] ?? FALSE);
    $values['taxonomy_bundles'] = array_filter($taxonomy['taxonomy_bundles'] ?? []);
    $values['taxonomy_exclude'] = (bool) ($taxonomy['taxonomy_exclude'] ?? FALSE);
    $form_state->setValues($values);
  }

  /**
   * {@inheritdoc}
   */
  public function getIcon(): string|null {
    return $this->configuration['icon'];
  }

  /**
   * {@inheritdoc}
   */
  protected function itemAccess(AccountInterface $account) {
    // Allow access if user can create any enabled entity type.
    if (!empty($this->configuration['node_enabled']) && $this->entityTypeManager->hasDefinition('node_type')) {
      foreach ($this->getFilteredBundles('node_type', 'node_bundles', 'node_exclude') as $type) {
        if (Url::fromRoute('node.add', ['node_type' => $type->id()])->access($account)) {
          return AccessResult::allowed();
        }
      }
    }
    if (!empty($this->configuration['media_enabled']) && $this->entityTypeManager->hasDefinition('media_type')) {
      foreach ($this->getFilteredBundles('media_type', 'media_bundles', 'media_exclude') as $type) {
        if (Url::fromRoute('entity.media.add_form', ['media_type' => $type->id()])->access($account)) {
          return AccessResult::allowed();
        }
      }
    }
    if (!empty($this->configuration['taxonomy_enabled']) && $this->entityTypeManager->hasDefinition('taxonomy_vocabulary')) {
      foreach ($this->getFilteredBundles('taxonomy_vocabulary', 'taxonomy_bundles', 'taxonomy_exclude') as $vocab) {
        $url = Url::fromRoute('entity.taxonomy_term.add_form', ['taxonomy_vocabulary' => $vocab->id()]);
        if ($url->access($account)) {
          return AccessResult::allowed();
        }
      }
    }
    return AccessResult::forbidden();
  }

  /**
   * {@inheritdoc}
   */
  public function getElement(): ToolbarItemElement {
    $element = parent::getElement();
    $this->linkProcessElement($element, Url::fromRoute('node.add_page'));

    $collection = new ToolbarItemCollection($element->getAlignment(), 'grid');
    $this->buildNodeElements($collection);
    $this->buildMediaElements($collection);
    $this->buildTaxonomyElements($collection);
    $this->processSchemeElement($element);

    if (!$collection->isEmpty()) {
      $build = $collection->toRenderable();
      $element->setModal($build, $this->configuration['title'], ['class' => ['px-3']]);
    }

    return $element;
  }

  /**
   * Build node creation elements.
   */
  protected function buildNodeElements(ToolbarItemCollection $collection): void {
    if (empty($this->configuration['node_enabled']) || !$this->entityTypeManager->hasDefinition('node_type')) {
      return;
    }
    $elements = [];
    foreach ($this->getFilteredBundles('node_type', 'node_bundles', 'node_exclude') as $type) {
      /** @var \Drupal\node\NodeTypeInterface $type */
      $isMicrocontent = $type->getThirdPartySettings('micronode')['micronode_is_microcontent'] ?? FALSE;
      if ($isMicrocontent) {
        continue;
      }
      $url = Url::fromRoute('node.add', ['node_type' => $type->id()]);
      if (!$url->access()) {
        continue;
      }
      $itemElement = new ToolbarItemElement('node_' . $type->id(), $type->label(), 'horizontal');
      $this->linkProcessElement($itemElement, $url);
      if ($icon = $this->loadIcon($type->label(), NULL, NULL, [
        'entity',
        'entity.node',
      ])) {
        $itemElement->setIcon($icon->getName());
      }
      $elements[] = $itemElement;
    }
    if (!empty($elements)) {
      $itemElement = new ToolbarItemElement('node', 'Content', 'horizontal');
      $itemElement->setStyle('heading', TRUE);
      $itemElement->addClass('col-span-2');
      $collection->add($itemElement);
      foreach ($elements as $element) {
        $collection->add($element);
      }
    }
  }

  /**
   * Build taxonomy term creation elements.
   */
  protected function buildTaxonomyElements(ToolbarItemCollection $collection): void {
    if (empty($this->configuration['taxonomy_enabled']) || !$this->entityTypeManager->hasDefinition('taxonomy_vocabulary')) {
      return;
    }
    $elements = [];
    foreach ($this->getFilteredBundles('taxonomy_vocabulary', 'taxonomy_bundles', 'taxonomy_exclude') as $vocab) {
      $url = Url::fromRoute('entity.taxonomy_term.add_form', ['taxonomy_vocabulary' => $vocab->id()]);
      if (!$url->access()) {
        continue;
      }
      $itemElement = new ToolbarItemElement('taxonomy_' . $vocab->id(), $vocab->label(), 'horizontal');
      $this->linkProcessElement($itemElement, $url);
      if ($icon = $this->loadIcon($vocab->label(), NULL, NULL, [
        'entity',
        'entity.taxonomy_term',
      ])) {
        $itemElement->setIcon($icon->getName());
      }
      $elements[] = $itemElement;
    }
    if (!empty($elements)) {
      $itemElement = new ToolbarItemElement('taxonomy', 'Taxonomy', 'horizontal');
      $itemElement->setStyle('heading', TRUE);
      $itemElement->addClass('col-span-2');
      $collection->add($itemElement);
      foreach ($elements as $element) {
        $collection->add($element);
      }
    }
  }

  /**
   * Build media creation elements.
   */
  protected function buildMediaElements(ToolbarItemCollection $collection): void {
    if (empty($this->configuration['media_enabled']) || !$this->entityTypeManager->hasDefinition('media_type')) {
      return;
    }
    $elements = [];
    foreach ($this->getFilteredBundles('media_type', 'media_bundles', 'media_exclude') as $type) {
      $url = Url::fromRoute('entity.media.add_form', ['media_type' => $type->id()]);
      if (!$url->access()) {
        continue;
      }
      $itemElement = new ToolbarItemElement('media_' . $type->id(), $type->label(), 'horizontal');
      $this->linkProcessElement($itemElement, $url);
      if ($icon = $this->loadIcon($type->label(), NULL, NULL, [
        'entity',
        'entity.media',
      ])) {
        $itemElement->setIcon($icon->getName());
      }
      $elements[] = $itemElement;
    }
    if (!empty($elements)) {
      $itemElement = new ToolbarItemElement('media', 'Media', 'horizontal');
      $itemElement->setStyle('heading', TRUE);
      $itemElement->addClass('col-span-2');
      $collection->add($itemElement);
      foreach ($elements as $element) {
        $collection->add($element);
      }
    }
  }

  /**
   * Get filtered bundle entities based on configuration.
   *
   * @param string $entity_type_id
   *   The bundle entity type ID (e.g., 'node_type', 'media_type').
   * @param string $config_key
   *   The configuration key for selected bundles.
   * @param string $exclude_key
   *   The configuration key for the exclude flag.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   The filtered bundle entities.
   */
  protected function getFilteredBundles(string $entity_type_id, string $config_key, string $exclude_key): array {
    $bundles = $this->entityTypeManager->getStorage($entity_type_id)->loadMultiple();
    $selected = array_filter($this->configuration[$config_key] ?? []);

    if (empty($selected)) {
      return $bundles;
    }

    $exclude = !empty($this->configuration[$exclude_key]);

    return array_filter($bundles, function ($bundle) use ($selected, $exclude) {
      $is_selected = isset($selected[$bundle->id()]);
      return $exclude ? !$is_selected : $is_selected;
    });
  }

}
