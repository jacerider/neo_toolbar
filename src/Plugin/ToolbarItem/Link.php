<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar\Plugin\ToolbarItem;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_toolbar\Attribute\ToolbarItem;
use Drupal\neo_toolbar\ToolbarItemBadgeTrait;
use Drupal\neo_toolbar\ToolbarItemElement;
use Drupal\neo_toolbar\ToolbarItemLinkTrait;
use Drupal\neo_toolbar\ToolbarItemPluginBase;

/**
 * Plugin implementation of the neo_toolbar_item.
 */
#[ToolbarItem(
  id: 'link',
  label: new TranslatableMarkup('Link'),
  description: new TranslatableMarkup('Internal or external link.'),
)]
final class Link extends ToolbarItemPluginBase {
  use ToolbarItemLinkTrait;
  use ToolbarItemBadgeTrait;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'url' => '',
      'target' => '',
      'icon' => '',
      'badge' => [
        'type' => '',
        'settings' => [],
      ],
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
    $this->badgeProcessElement($element);
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
    $form['token'] = $this->getTokenElement();

    $form['target'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Open link in new window'),
      '#return_value' => '_blank',
      '#default_value' => $this->configuration['target'],
    ];

    $form['icon'] = [
      '#type' => 'neo_icon_select',
      '#title' => $this->t('Icon'),
      '#required' => TRUE,
      '#description' => $this->t('The icon of the modal.'),
      '#default_value' => $this->configuration['icon'],
    ];

    $form['badge'] = $this->badgeForm([], $form_state, $this->configuration['badge']);

    return $form;
  }

}
