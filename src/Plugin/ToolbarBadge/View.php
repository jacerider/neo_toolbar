<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar\Plugin\ToolbarBadge;

use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Database\Query\Select;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_toolbar\Attribute\ToolbarBadge;
use Drupal\neo_toolbar\ToolbarBadgePluginBase;
use Drupal\neo_toolbar\ToolbarItemElement;
use Drupal\views\Views;

/**
 * Plugin implementation of the neo_toolbar_badge.
 */
#[ToolbarBadge(
  id: 'view',
  label: new TranslatableMarkup('View'),
  description: new TranslatableMarkup('Use a view to calculate the count for a badge.'),
)]
final class View extends ToolbarBadgePluginBase {
  use StringTranslationTrait;

  /**
   * The view.
   *
   * @var \Drupal\views\ViewExecutable|null
   */
  protected $view;

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'view_id' => '',
      'view_display_id' => '',
      'view_argument' => '',
    ] + parent::defaultConfiguration();
  }

  /**
   * {@inheritdoc}
   */
  public function getBadge(ToolbarItemElement $element): string|int|null {
    if ($view = $this->getView()) {
      $view->build();
      $this->addCacheTags($view->getCacheTags());
      $query = $view->getQuery()->query(TRUE);
      if ($query instanceof Select) {
        return $query->execute()->fetchField();
      }
    }
    return parent::getBadge($element);
  }

  /**
   * Configuration form for the toolbar item plugin.
   */
  protected function badgeForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    $id = 'neo-toolbar-badge-view';

    $form['view_id'] = [
      '#type' => 'select',
      '#title' => $this->t('View'),
      '#options' => $this->getViews(),
      '#default_value' => $this->configuration['view_id'],
      '#required' => TRUE,
      '#empty_option' => $this->t('- Select -'),
      '#multiple' => FALSE,
      '#ajax' => [
        'callback' => [get_class($this), 'exoToolbarBadgeViewFormAjax'],
        'event' => 'change',
        'wrapper' => $id,
        'progress' => [
          'type' => 'throbber',
          'message' => t('Getting display Ids...'),
        ],
      ],
    ];

    $form['view_display_id'] = [
      '#type' => 'select',
      '#title' => $this->t('View Display'),
      '#options' => [],
      '#default_value' => $this->configuration['view_display_id'],
      '#empty_option' => $this->t('- Select -'),
      '#multiple' => FALSE,
      '#wrapper_attributes' => [
        'id' => $id,
      ],
      '#states' => [
        'visible' => [
          ':input[name="settings[badge][settings][view_id]"]' => ['filled' => TRUE],
        ],
      ],
    ];

    if ($this->configuration['view_id']) {
      $form['view_display_id'] = [
        '#options' => $this->getViewDisplayIds($this->configuration['view_id']),
        '#required' => TRUE,
      ] + $form['view_display_id'];
    }

    $form['view_argument'] = [
      '#title' => 'Argument',
      '#type' => 'textfield',
      '#default_value' => $this->configuration['view_argument'],
      '#states' => [
        'visible' => [
          ':input[name="settings[badge][settings][view_id]"]' => ['filled' => TRUE],
        ],
      ],
    ];

    return $form;
  }

  /**
   * AJAX function to get display IDs for a particular View.
   */
  public static function exoToolbarBadgeViewFormAjax(array &$form, FormStateInterface $form_state) {
    $parents = array_splice($form_state->getTriggeringElement()['#parents'], 0, -1);
    return NestedArray::getValue($form, $parents)['view_display_id'];
  }

  /**
   * Helper function to get all display ids.
   */
  protected function getView() {
    if (!isset($this->view)) {
      $argument = $this->configuration['view_argument'];
      $arguments = [$argument];
      if (preg_match('/\//', $argument)) {
        $arguments = explode('/', $argument);
      }
      $this->view = Views::getView($this->configuration['view_id']);
      if ($this->view) {
        $this->view->setDisplay($this->configuration['view_display_id']);
        $this->view->setArguments($arguments);
      }
    }
    return $this->view;
  }

  /**
   * Helper function to get all display ids.
   */
  protected function getViews() {
    $views = Views::getEnabledViews();
    $options = [];
    foreach ($views as $view) {
      if ($view->status()) {
        $options[$view->get('id')] = $view->get('label');
      }
    }
    return $options;
  }

  /**
   * Helper to get display ids for a particular View.
   */
  protected function getViewDisplayIds($entity_id) {
    $views = Views::getEnabledViews();
    $options = [];
    foreach ($views as $view) {
      if ($view->get('id') == $entity_id) {
        foreach ($view->get('display') as $display) {
          $options[$display['id']] = $display['display_title'];
        }
      }
    }
    return $options;
  }

}
