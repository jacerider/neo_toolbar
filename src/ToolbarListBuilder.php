<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar;

use Drupal\Core\Config\Entity\DraggableListBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;
use Drupal\neo_icon\IconTranslationTrait;

/**
 * Provides a listing of toolbars.
 */
final class ToolbarListBuilder extends DraggableListBuilder {

  use IconTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'neo_toolbar_overview';
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
    /** @var \Drupal\neo_toolbar\ToolbarInterface $entity */
    $row['label'] = $entity->label();
    $row['id']['data']['#markup'] = $entity->id();
    $row['status']['data']['#markup'] = $this->statusIcon($entity->status())->iconOnly();
    return $row + parent::buildRow($entity);
  }

  /**
   * {@inheritdoc}
   */
  public function getDefaultOperations(EntityInterface $entity) {
    /** @var \Drupal\Core\Config\Entity\ConfigEntityInterface $entity */
    $operations = parent::getDefaultOperations($entity);

    $operations['items'] = [
      'title' => t('Items'),
      'weight' => -20,
      'url' => Url::fromRoute('entity.neo_toolbar_item.collection', [
        'neo_toolbar' => $entity->id(),
      ]),
    ];

    return $operations;
  }

}
