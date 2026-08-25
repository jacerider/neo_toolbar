<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar\Plugin\ToolbarItem;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\CurrentRouteMatch;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\neo_icon\IconRepositoryTrait;
use Drupal\neo_toolbar\Attribute\ToolbarItem;
use Drupal\neo_toolbar\ToolbarItemCollection;
use Drupal\neo_toolbar\ToolbarItemElement;
use Drupal\neo_toolbar\ToolbarItemLinkTrait;
use Drupal\neo_toolbar\ToolbarItemPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the neo_toolbar_item.
 */
#[ToolbarItem(
  id: 'taxonomy',
  label: new TranslatableMarkup('Taxonomy'),
  description: new TranslatableMarkup('Taxonomy management within a modal.'),
  provider: 'taxonomy',
  icon: 'tags',
)]
class Taxonomy extends ToolbarItemPluginBase {
  use ToolbarItemLinkTrait;
  use IconRepositoryTrait;

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
    ?EntityTypeManagerInterface $entity_type_manager = NULL,
    ?CurrentRouteMatch $current_route_match = NULL,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $transliteration);
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
      $complete_form['label']['#default_value'] = 'Manage Taxonomy';
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function itemAccess(AccountInterface $account) {
    return Url::fromRoute('entity.taxonomy_vocabulary.collection')
      ->access($account) ? AccessResult::allowed() : AccessResult::forbidden();
  }

  /**
   * {@inheritdoc}
   */
  public function getElement(): ToolbarItemElement {
    $element = parent::getElement();
    $currentRouteName = $this->currentRouteMatch->getRouteName();
    $currentRouteVocabulary = NULL;
    if ($currentRouteName === 'entity.taxonomy_vocabulary.overview_form') {
      $currentRouteVocabulary = $this->currentRouteMatch->getParameter('taxonomy_vocabulary');
    }
    $this->linkProcessElement($element, Url::fromRoute('entity.taxonomy_vocabulary.collection'));

    /** @var \Drupal\taxonomy\Entity\Vocabulary[] $vocabularies */
    $vocabularies = $this->entityTypeManager->getStorage('taxonomy_vocabulary')->loadMultiple();
    $collection = new ToolbarItemCollection($element->getAlignment(), 'modal');
    foreach ($vocabularies as $vocabulary) {
      if ($vocabulary->access('access taxonomy overview')) {
        $itemElement = new ToolbarItemElement($vocabulary->id(), $vocabulary->label(), 'horizontal');
        $vocabularyUrl = $vocabulary->toUrl('overview-form');
        $this->linkProcessElement($itemElement, $vocabularyUrl);
        $itemElement->setWeight($vocabulary->get('weight'));
        if ($currentRouteVocabulary && $currentRouteVocabulary->id() === $vocabulary->id()) {
          $this->linkProcessElement($element, $vocabularyUrl);
        }
        if ($icon = $this->loadIcon($vocabulary->label(), NULL, NULL, [
          'entity',
          'entity.taxonomy_term',
        ])) {
          $itemElement->setIcon($icon->getName());
        }
        $collection->add($itemElement);
      }
    }
    if (!$collection->isEmpty()) {
      $build = $collection->toRenderable();
      $element->setModal($build, $this->configuration['title'], ['class' => ['px-3']]);
    }

    return $element;
  }

}
