<?php

namespace Drupal\neo_toolbar;

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\user\Entity\User;

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
   * A link that opens the token browser on demand, rather than the tree
   * itself: built inline, the tree is over a thousand table rows shipped into
   * every form that carries this element, for a panel most people never open.
   *
   * Note this is a theme hook, not a render element -- there is no
   * token_tree_link element plugin, so '#type' would not resolve.
   *
   * @return array
   *   The token element.
   */
  protected function getTokenElement() {
    return [
      // Global token types come along by default, matching what this trait's
      // tokenReplace() can actually resolve.
      '#theme' => 'token_tree_link',
      '#token_types' => ['user'],
    ];
  }

  /**
   * Replace a token.
   *
   * @return string
   *   The entered plain text with tokens replaced.
   */
  protected function tokenReplace($markup, array $data = [], array $options = [], ?BubbleableMetadata $bubbleable_metadata = NULL) {
    $account = \Drupal::currentUser()->id();
    $user = User::load($account);
    if ($user) {
      $data['user'] = $user;
    }
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
