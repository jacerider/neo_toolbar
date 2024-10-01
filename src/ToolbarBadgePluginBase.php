<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar;

use Drupal\Component\Plugin\PluginBase;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyTrait;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\PluginWithFormsInterface;
use Drupal\Core\Plugin\PluginWithFormsTrait;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Base class for neo_toolbar_badge plugins.
 */
abstract class ToolbarBadgePluginBase extends PluginBase implements ToolbarBadgePluginInterface, PluginWithFormsInterface, RefinableCacheableDependencyInterface {
  use PluginWithFormsTrait;
  use RefinableCacheableDependencyTrait;
  use StringTranslationTrait;

  /**
   * Creates a toolbar item instance.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->setConfiguration($configuration);
  }

  /**
   * {@inheritdoc}
   */
  public function getConfiguration() {
    return $this->configuration;
  }

  /**
   * {@inheritdoc}
   */
  public function setConfiguration(array $configuration) {
    $this->configuration = NestedArray::mergeDeep(
      $this->baseConfigurationDefaults(),
      $this->defaultConfiguration(),
      $configuration
    );
  }

  /**
   * Returns generic default configuration for badge plugins.
   *
   * @return array
   *   An associative array with the default configuration.
   */
  protected function baseConfigurationDefaults() {
    return [
      'scheme' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function label(): string {
    // Cast the label to a string since it is a TranslatableMarkup object.
    return (string) $this->pluginDefinition['label'];
  }

  /**
   * {@inheritdoc}
   */
  public function getBadge(ToolbarItemElement $element): string|int|null {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getBadgeScheme(): string {
    return $this->configuration['scheme'] ?? '';
  }

  /**
   * {@inheritdoc}
   *
   * Creates a generic configuration form for all badge types. Individual
   * badge plugins can add elements to this form by overriding
   * ToolbarBadgePluginBase::badgeForm(). Most badge plugins should not override
   * this method unless they need to alter the generic form elements.
   *
   * @see \Drupal\neo_toolbar\ToolbarBadgePluginBase::badgeForm()
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state, array &$complete_form = NULL) {
    $form += $this->badgeForm($form, $form_state, $complete_form);
    $form['scheme'] = [
      '#type' => 'neo_scheme',
      '#title' => $this->t('Badge Scheme'),
      '#description' => $this->t('The color scheme of the badge.'),
      '#default_value' => $this->configuration['scheme'],
      '#empty_option' => $this->t('Default'),
      '#include' => ['secondary', 'accent', 'alert', 'warning', 'success'],
      '#format' => 'class',
    ];
    return $form;
  }

  /**
   * Configuration form for the toolbar badge plugin.
   */
  protected function badgeForm(array $form, FormStateInterface $form_state, array &$complete_form): array {
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * Most badge plugins should not override this method. To add validation
   * for specific badge type, override ToolbarBadgePluginBase::badgeValidate().
   *
   * @see \Drupal\neo_toolbar\ToolbarBadgePluginBase::badgeValidate()
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    // Remove the admin_label form badge element value so it will not persist.
    $form_state->unsetValue('label');
    $this->badgeValidate($form, $form_state);
  }

  /**
   * Form validation for the toolbar badge plugin configuration.
   */
  protected function badgeValidate(array $form, FormStateInterface $form_state): void {}

  /**
   * {@inheritdoc}
   *
   * This is currently not called but is added for future use.
   *
   * Most badge plugins should not override this method. To add submission
   * handling for a specific badge type, override
   * ToolbarBadgePluginBase::badgeSubmit().
   *
   * @see \Drupal\neo_toolbar\ToolbarBadgePluginBase::badgeSubmit()
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    // Process the badge's submission handling if no errors occurred only.
    if (!$form_state->getErrors()) {
      $this->badgeSubmit($form, $form_state);
    }
  }

  /**
   * Form submit for the toolbar badge plugin configuration.
   */
  protected function badgeSubmit(array $form, FormStateInterface $form_state): void {
  }

}
