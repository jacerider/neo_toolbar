<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar;

use Drupal\Component\Serialization\Json;
use Drupal\Component\Utility\Html;
use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Form\FormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\neo_icon\IconTranslationTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides a listing of neo toolbar items.
 */
final class ToolbarItemListBuilder extends ConfigEntityListBuilder implements FormInterface {

  use IconTranslationTrait;

  /**
   * The form builder.
   *
   * @var \Drupal\Core\Form\FormBuilderInterface
   */
  protected $formBuilder;

  /**
   * The current request.
   *
   * @var \Symfony\Component\HttpFoundation\Request
   */
  protected $request;

  /**
   * The eXo toolbar.
   *
   * @var \Drupal\neo_toolbar\Entity\ToolbarInterface
   */
  protected $toolbar;

  /**
   * Constructs a new BlockListBuilder object.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type definition.
   * @param \Drupal\Core\Entity\EntityStorageInterface $storage
   *   The entity storage class.
   * @param \Drupal\Core\Form\FormBuilderInterface $form_builder
   *   The form builder.
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    FormBuilderInterface $form_builder
  ) {
    parent::__construct($entity_type, $storage);
    $this->formBuilder = $form_builder;
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('form_builder')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'neo_toolbar_items_list';
  }

  /**
   * {@inheritdoc}
   *
   * Builds the entity listing as renderable array for table.html.twig.
   *
   * @todo Add a link to add a new item to the #empty text.
   */
  public function render(Request $request = NULL, ToolbarInterface $neo_toolbar = NULL) {
    if ($request && $neo_toolbar) {
      $this->request = $request;
      $this->toolbar = $neo_toolbar;
      return $this->formBuilder->getForm($this);
    }
    return parent::render();
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['#attached']['library'][] = 'core/drupal.tableheader';
    $form['#attached']['library'][] = 'core/drupal.dialog.ajax';
    $form['#attributes']['class'][] = 'clearfix';

    // Build the form tree.
    $form['items'] = $this->buildItemsForm();

    $form['actions'] = [
      '#tree' => FALSE,
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save Items'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  /**
   * Builds the main "items" portion of the form.
   *
   * @return array
   *   The form array.
   */
  protected function buildItemsForm() {
    $items = [];
    $entities = $this->load();
    /** @var \Drupal\neo_toolbar\Entity\ToolbarItemInterface[] $entities */
    foreach ($entities as $entityId => $entity) {
      $plugin = $entity->getPlugin();
      $label = $this->t('@label <small>@type</small>', [
        '@label' => $entity->label(),
        '@type' => $plugin->label(),
      ]);
      if ($icon = $plugin->getIcon()) {
        $label = $this->icon($label, $icon);
      }
      $items[$entity->getRegionId()][$entityId] = [
        'label' => $label,
        'entity_id' => $entityId,
        'weight' => $entity->getWeight(),
        'entity' => $entity,
        'toolbar' => $entity->getToolbarId(),
        'region' => $entity->getRegionId(),
        'category' => $plugin->getCategory(),
        'status' => $entity->status(),
      ];
    }

    $form = [];
    $regions = $this->toolbar->getRegions();
    if ($regions) {
      foreach ($regions as $region) {
        $regionId = $region->getPluginId();
        $region_items = $items[$region->getPluginId()] ?? [];
        $form[$regionId] = $this->buildRegionsForm($region, $region_items) + [
          '#type' => 'details',
          '#title' => $region->label(),
          '#open' => !empty($region_items),
        ];
      }
    }
    else {
      return [
        '#markup' => '<em>' . $this->t('No regions are currently enabled in this toolbar') . '</em>',
      ];
    }

    return $form;
  }

  /**
   * Build the "regions" portion of the form.
   *
   * @param Drupal\neo_toolbar\ToolbarRegionPluginInterface $region
   *   The region.
   * @param array $items
   *   An array of region items.
   *
   * @return array
   *   The form array.
   */
  protected function buildRegionsForm(ToolbarRegionPluginInterface $region, array $items) {
    $form = [];
    $regionId = $region->getPluginId();

    if (!empty($items)) {
      $form['#attached']['library'][] = 'core/drupal.tableheader';
      $form['#attached']['library'][] = 'core/drupal.dialog.ajax';
      $regionOptions = array_map(function ($region) {
        return $region->label();
      }, $this->toolbar->getRegions());
      $weightDelta = round(count($items) / 2);
      $table = [
        '#type' => 'table',
        '#header' => [
          $this->t('Name'),
          $this->t('Category'),
          $this->t('Region'),
          $this->t('Weight'),
          $this->t('Operations'),
        ],
        '#attributes' => [
          'id' => Html::getId('items-' . $regionId),
        ],
      ];

      $table['#tabledrag'][] = [
        'table_id' => Html::getId('items-' . $regionId),
        'action' => 'order',
        'relationship' => 'sibling',
        'group' => Html::getId('item-weight-' . $regionId),
      ];
      foreach ($items as $info) {
        $row = [];
        $entityId = $info['entity_id'];

        $row = [
          '#attributes' => [
            'class' => ['draggable'],
          ],
        ];
        $row['#attributes']['class'][] = $info['status'] ? 'item-enabled' : 'item-disabled';
        $row['info'] = [
          '#markup' => $info['status'] ? $info['label'] : $this->t('@label (disabled)', ['@label' => $info['label']]),
          '#wrapper_attributes' => [
            'class' => ['item'],
          ],
        ];
        $row['type'] = [
          '#markup' => $info['category'],
        ];
        $row['region-toolbar']['region'] = [
          '#type' => 'select',
          '#default_value' => $regionId,
          '#required' => TRUE,
          '#title' => $this->t('Region for @item item', ['@item' => $info['label']]),
          '#title_display' => 'invisible',
          '#options' => $regionOptions,
          '#parents' => ['items', $entityId, 'region'],
        ];
        $row['region-toolbar']['toolbar'] = [
          '#type' => 'hidden',
          '#value' => $info['toolbar'],
          '#parents' => ['items', $entityId, 'toolbar'],
        ];
        $row['weight'] = [
          '#type' => 'weight',
          '#default_value' => $info['weight'],
          '#delta' => $weightDelta,
          '#title' => $this->t('Weight for @item item', ['@item' => $info['label']]),
          '#title_display' => 'invisible',
          '#attributes' => [
            'class' => [Html::getId('item-weight-' . $regionId)],
          ],
        ];
        $row['operations'] = $this->buildOperations($info['entity']);
        $table[$entityId] = $row;
      }
      $form['items'] = $table;
    }

    $form['add'] = [
      '#type' => 'link',
      '#title' => $this->t('Place item in the %name', ['%name' => $region->label()]),
      '#url' => Url::fromRoute('entity.neo_toolbar_item.library', [
        'neo_toolbar' => $this->toolbar->id(),
        'neo_toolbar_region' => $regionId,
      ]),
      '#attributes' => [
        'class' => ['use-ajax', 'btn', 'btn-xs'],
        'data-dialog-type' => 'modal',
        'data-dialog-options' => Json::encode([
          'width' => 700,
        ]),
      ],
      '#weight' => -100,
      '#prefix' => '<div>',
      '#suffix' => '</div>',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    // No validation.
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\neo_toolbar\Entity\ToolbarItemInterface[] $entities */
    $entities = $this->storage->loadMultiple(array_keys($form_state->getValue('items')));
    foreach ($entities as $entityId => $entity) {
      $entity_values = $form_state->getValue(['items', $entityId]);
      $entity->set('weight', $entity_values['weight']);
      $entity->set('region', $entity_values['region']);
      $entity->save();
    }
    $this->messenger()->addMessage(t('The item settings have been updated.'));
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['label'] = $this->t('Label');
    $header['id'] = $this->t('Machine name');
    $header['status'] = $this->t('Status');
    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\neo_toolbar\ToolbarItemInterface $entity */
    $row['label'] = $entity->label();
    $row['id'] = $entity->id();
    $row['status'] = $entity->status() ? $this->t('Enabled') : $this->t('Disabled');
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $entity) {
    $operations = parent::getDefaultOperations($entity);
    if (!empty($operations['edit'])) {
      $operations['edit']['attributes'] = [
        'class' => ['use-ajax'],
        'data-dialog-type' => 'modal',
        'data-dialog-options' => Json::encode([
          'width' => '100%',
          'height' => '100%',
        ]),
      ];
    }
    return $operations;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntityIds() {
    $query = $this->getStorage()->getQuery()
      ->sort($this->entityType->getKey('id'));

    if (isset($this->toolbar)) {
      $query->condition('toolbar', $this->toolbar->id());
    }

    // Only add the pager if a limit is specified.
    if ($this->limit) {
      $query->pager($this->limit);
    }
    return $query->accessCheck(FALSE)->execute();
  }

}
