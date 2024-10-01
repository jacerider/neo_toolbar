<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar\Plugin\ToolbarItem;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_toolbar\Attribute\ToolbarItem;
use Drupal\neo_toolbar\ToolbarItemElement;
use Drupal\neo_toolbar\ToolbarItemLinkTrait;
use Drupal\neo_toolbar\ToolbarItemPluginBase;
use Drupal\neo_toolbar\ToolbarItemRegionTrait;

/**
 * Plugin implementation of the neo_toolbar_item.
 */
#[ToolbarItem(
  id: 'region',
  label: new TranslatableMarkup('Region'),
  description: new TranslatableMarkup('A link with nested items.'),
  region_create: TRUE,
)]
final class Region extends ToolbarItemPluginBase {
  use ToolbarItemLinkTrait;
  use ToolbarItemRegionTrait;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'url' => '',
      'icon' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIcon(): string|null {
    return $this->configuration['icon'];
  }

  /**
   * Get the element that makes up the toolbar item.
   *
   * @return \Drupal\neo_toolbar\ToolbarItemElement
   *   The toolbar item element.
   */
  protected function getElement(): ToolbarItemElement {
    $element = parent::getElement();
    $this->linkProcessElement($element);
    $this->processRegionElementAsModal($element, $this->configuration['title']);
    return $element;
  }

  /**
   * {@inheritdoc}
   */
  protected function itemAccess(AccountInterface $account) {
    return $this->uriAccess($this->configuration['url']);
  }

  /**
   * {@inheritdoc}
   */
  public function itemForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $form = parent::itemForm($form, $form_state, $complete_form);

    $form['url'] = $this->urlForm([], $form_state, $this->configuration['url']);

    $form['icon'] = [
      '#type' => 'neo_icon_select',
      '#title' => $this->t('Icon'),
      '#required' => TRUE,
      '#description' => $this->t('The icon of the modal.'),
      '#default_value' => $this->configuration['icon'],
    ];
    return $form;
  }

}
