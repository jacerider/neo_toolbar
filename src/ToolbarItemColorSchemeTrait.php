<?php

namespace Drupal\neo_toolbar;

/**
 * A trait that provides link utilities.
 */
trait ToolbarItemColorSchemeTrait {

  /**
   * {@inheritdoc}
   */
  public function getScheme(): string {
    return $this->configuration['scheme'] ?? '';
  }

  /**
   * Get the style element.
   *
   * @param string|null $defaultValue
   *   The default value.
   *
   * @return array
   *   The style element.
   */
  protected function getSchemeElement($defaultValue = NULL) {
    return [
      '#type' => 'neo_scheme',
      '#title' => $this->t('Scheme'),
      '#description' => $this->t('The color scheme of the element.'),
      '#default_value' => $defaultValue,
      '#empty_option' => $this->t('Default'),
      '#format' => 'class',
    ];
  }

  /**
   * Process the scheme-enabled element.
   *
   * @param ToolbarItemElement $element
   *   The toolbar item element.
   */
  public function processSchemeElement(ToolbarItemElement $element): void {
    if (!empty($this->configuration['scheme'])) {
      $element->addClass($this->configuration['scheme']);
    }
  }

}
