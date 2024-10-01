<?php

namespace Drupal\neo_toolbar;

use Drupal\Core\Render\BubbleableMetadata;

/**
 * A trait that provides token utilities.
 */
trait ToolbarItemTokenTrait {

  /**
   * The token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $token;

  /**
   * Get the token element.
   *
   * @return array
   *   The token element.
   */
  protected function getTokenElement() {
    return [
      '#type' => 'details',
      '#title' => $this->t('Available tokens'),
      '#open' => FALSE,
      'tokens' => \Drupal::service('token.tree_builder')->buildRenderable(['user']) + [
        '#attributes' => [
          'class' => ['m-0'],
        ],
      ],
    ];
  }

  /**
   * Replace a token.
   *
   * @return string
   *   The entered plain text with tokens replaced.
   */
  protected function tokenReplace($markup, array $data = [], array $options = [], ?BubbleableMetadata $bubbleable_metadata = NULL) {
    $currentUser = \Drupal::currentUser();
    $data['user'] = $currentUser->getAccount();
    return $this->getToken()->replace($markup, $data, $options, $bubbleable_metadata);
  }

  /**
   * Retrieves the token service.
   *
   * @return \Drupal\Core\Utility\Token
   *   The token service.
   */
  protected function getToken() {
    if (!isset($this->token)) {
      $this->token = \Drupal::token();
    }
    return $this->token;
  }

}
