<?php

namespace Drupal\neo_toolbar;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\SubformState;

/**
 * A trait that provides badge utilities.
 */
trait ToolbarItemBadgeTrait {

  /**
   * The token service.
   *
   * @var \Drupal\Core\Utility\Badge
   */
  protected $badgeManager;

  /**
   * Process the badge-enabled element.
   *
   * @param ToolbarItemElement $element
   *   The toolbar item element.
   * @param string $type
   *   The badge type.
   * @param array|null $settings
   *   The settings.
   */
  public function badgeProcessElement(ToolbarItemElement $element, string $type = NULL, array $settings = NULL): void {
    $type = $type ?? $this->configuration['badge']['type'] ?? '';
    $settings = $settings ?? $this->configuration['badge']['settings'] ?? [];
    if ($type && $this->getBadgeManager()->hasDefinition($type)) {
      /** @var \Drupal\neo_toolbar\ToolbarBadgePluginInterface $plugin */
      $plugin = $this->getBadgeManager()->createInstance($type, $settings);
      $element->setBadge($plugin->getBadge($element));
      $element->addCacheableDependency($plugin);
      if ($scheme = $plugin->getBadgeScheme()) {
        $element->addBadgeClass($scheme);
      }
    }
  }

  /**
   * Get the badge form element.
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array $defaultValue
   *   The default value.
   *
   * @return array
   *   The token element.
   */
  protected function badgeForm($form, FormStateInterface $form_state, $defaultValue = []) {
    $id = Html::getUniqueId('toolbar-item-badge');
    $type = $defaultValue['type'] ?? '';
    $settings = $defaultValue['settings'] ?? [];
    $build = [
      '#type' => 'details',
      '#title' => $this->t('Badge'),
      '#open' => !empty($type),
      '#tree' => TRUE,
      '#prefix' => '<div id="' . $id . '">',
      '#suffix' => '</div>',
      '#element_validate' => [[$this, 'badgeValidate']],
    ];
    $options = [];
    foreach ($this->getBadgeManager()->getDefinitions() as $definition) {
      $options[$definition['id']] = $definition['label'];
    }
    $build['type'] = [
      '#type' => 'select',
      '#title' => $this->t('Type'),
      '#options' => $options,
      '#empty_option' => $this->t('- None -'),
      '#default_value' => $type,
      '#limit_validation_errors' => [],
      '#ajax' => [
        'callback' => [self::class, 'ajaxBadgeCallback'],
        'wrapper' => $id,
      ],
    ];

    if ($type && $this->getBadgeManager()->hasDefinition($type)) {
      /** @var \Drupal\neo_toolbar\ToolbarBadgePluginInterface $plugin */
      $plugin = $this->getBadgeManager()->createInstance($type, $settings);
      $build['settings'] = [];
      $subform_state = SubformState::createForSubform($build['settings'], $build, $form_state);
      $build['settings'] = $plugin->buildConfigurationForm($build['settings'], $subform_state, $build);
    }

    return $build;
  }

  /**
   * Validate the badge form element.
   *
   * @param array $element
   *   The form element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function badgeValidate(array $element, FormStateInterface $form_state): void {
    $values = $form_state->getValue($element['#parents']);
    $type = $values['type'] ?? '';
    $settings = $values['settings'] ?? [];
    if ($type && $settings && $this->getBadgeManager()->hasDefinition($type)) {
      /** @var \Drupal\neo_toolbar\ToolbarBadgePluginInterface $plugin */
      $plugin = $this->getBadgeManager()->createInstance($type, $settings);
      $subform_state = SubformState::createForSubform($element['settings'], $form_state->getCompleteForm(), $form_state);
      $plugin->validateConfigurationForm($element['settings'], $subform_state);
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function ajaxBadgeCallback(array &$form, FormStateInterface $form_state): array {
    $parents = array_splice($form_state->getTriggeringElement()['#array_parents'], 0, -1);
    return NestedArray::getValue($form, $parents);
  }

  /**
   * Retrieves the badge manager.
   *
   * @return \Drupal\neo_toolbar\ToolbarBadgePluginManager
   *   The badge manager.
   */
  protected function getBadgeManager() {
    if (!isset($this->badgeManager)) {
      $this->badgeManager = \Drupal::service('plugin.manager.neo_toolbar_badge');
    }
    return $this->badgeManager;
  }

}
