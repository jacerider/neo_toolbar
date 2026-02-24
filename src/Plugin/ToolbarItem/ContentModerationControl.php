<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar\Plugin\ToolbarItem;

use Drupal\Component\Plugin\Exception\ContextException;
use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\content_moderation\Form\EntityModerationForm;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_icon\IconRepositoryTrait;
use Drupal\neo_toolbar\Attribute\ToolbarItem;
use Drupal\neo_toolbar\ToolbarItemElement;
use Drupal\neo_toolbar\ToolbarItemLinkTrait;
use Drupal\neo_toolbar\ToolbarItemPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_toolbar_item.
 */
#[ToolbarItem(
  id: 'content_moderation_control',
  label: new TranslatableMarkup('Content Moderation Control'),
  description: new TranslatableMarkup('Content moderation control within a modal.'),
  provider: 'content_moderation',
  context_definitions: [
    "node" => new EntityContextDefinition(
      data_type: "entity:node",
      label: new TranslatableMarkup("Node"),
      required: TRUE,
    ),
  ],
)]
class ContentModerationControl extends ToolbarItemPluginBase {
  use ToolbarItemLinkTrait;
  use IconRepositoryTrait;

  /**
   * The Moderation Information service.
   *
   * @var \Drupal\content_moderation\ModerationInformationInterface
   */
  protected $moderationInfo;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\CurrentRouteMatch
   */
  protected CurrentRouteMatch $currentRouteMatch;

  /**
   * Creates a toolbar item instance.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    TransliterationInterface $transliteration,
    ModerationInformationInterface $moderation_info = NULL,
    EntityTypeManagerInterface $entity_type_manager = NULL,
    CurrentRouteMatch $current_route_match = NULL,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $transliteration);
    $this->moderationInfo = $moderation_info;
    $this->entityTypeManager = $entity_type_manager;
    $this->currentRouteMatch = $current_route_match;
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
      $container->get('content_moderation.moderation_information'),
      $container->get('entity_type.manager'),
      $container->get('current_route_match'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function itemForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $form = parent::itemForm($form, $form_state, $complete_form);

    if (empty($complete_form['label']['#default_value'])) {
      $complete_form['label']['#default_value'] = 'Content Moderation';
    }

    $complete_form['id'] = [
      '#type' => 'value',
      '#value' => $complete_form['id']['#default_value'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getIcon(): string|null {
    return 'code-merge';
  }

  /**
   * {@inheritdoc}
   */
  public function getStyle(): string {
    return 'pill';
  }

  /**
   * {@inheritdoc}
   */
  public function getElement(): ToolbarItemElement {
    $element = parent::getElement();
    /** @var \Drupal\node\NodeInterface $entity */
    try {
      $entity = $this->getContextValue('node');
      $this->linkProcessElement($element, $entity->toUrl());

      $build = [];
      $build['content_moderation_control'] = \Drupal::formBuilder()->getForm(EntityModerationForm::class, $entity);
      $build['content_moderation_control']['#weight'] = -10;
      $build['content_moderation_control']['#attributes']['class'][] = 'p-4';
      $element->setModal($build, $this->t('Content Moderation Control'));
    }
    catch (ContextException $e) {
      // Do nothing if the context is not available.
    }
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  protected function itemAccess(AccountInterface $account) {
    /** @var \Drupal\node\NodeInterface $entity */
    $entity = $this->getContextValue('node');
    if (!$this->moderationInfo->isModeratedEntity($entity)) {
      return AccessResult::forbidden();
    }
    if (isset($entity->in_preview) && $entity->in_preview) {
      return AccessResult::forbidden();
    }
    // The moderation form should be displayed only when viewing the latest
    // (translation-affecting) revision, unless it was created as published
    // default revision.
    if (($entity->isDefaultRevision() || $entity->wasDefaultRevision()) && $entity->isPublished()) {
      return AccessResult::forbidden();
    }
    if (!$entity->isLatestRevision() && !$entity->isLatestTranslationAffectedRevision()) {
      return AccessResult::forbidden();
    }
    return parent::itemAccess($account);
  }

}
