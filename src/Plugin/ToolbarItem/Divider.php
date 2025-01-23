<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar\Plugin\ToolbarItem;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_toolbar\Attribute\ToolbarItem;
use Drupal\neo_toolbar\ToolbarItemElement;
use Drupal\neo_toolbar\ToolbarItemInterface;
use Drupal\neo_toolbar\ToolbarItemPluginBase;

/**
 * Plugin implementation of the neo_toolbar_item.
 */
#[ToolbarItem(
  id: 'divider',
  label: new TranslatableMarkup('Divider'),
  description: new TranslatableMarkup('A divider between toolbar items.'),
)]
final class Divider extends ToolbarItemPluginBase {

  /**
   * {@inheritdoc}
   */
  public function itemForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $form = parent::itemForm($form, $form_state, $complete_form);

    $complete_form['label'] = [
      '#type' => 'value',
      '#value' => 'Divider',
    ];

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
    return 'grip-lines';
  }

  /**
   * {@inheritdoc}
   */
  public function getElement(): ToolbarItemElement {
    $element = parent::getElement();
    $element->showTooltip(FALSE);
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  public function accessBySiblings(ToolbarItemInterface $previous = NULL, ToolbarItemInterface $next = NULL): AccessResultInterface {
    if (!$previous) {
      return AccessResult::forbidden();
    }
    if (!$next) {
      return AccessResult::forbidden();
    }
    if ($previous->getPluginId() === 'divider' || $next->getPluginId() === 'divider') {
      return AccessResult::forbidden()->addCacheableDependency($previous);
    }
    return AccessResult::allowed();
  }

}
