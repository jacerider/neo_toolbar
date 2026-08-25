<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar_test;

use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\neo_toolbar\ToolbarItemLinkTrait;

/**
 * Exposes ToolbarItemLinkTrait's protected members to the tests.
 *
 * Every useful member of the trait is `protected` or `protected static`, and
 * both the unit ticket and the kernel ticket need the same exposure. One
 * forwarding class here is the harness; two copies in two test files would
 * drift. The trait's already-public members — `getUrl()`,
 * `buildLinkAttributes()`, `linkProcessElement()` and the static
 * `validateUriElement()` — are reachable on this class directly and are not
 * re-wrapped.
 *
 * The trait reads `$this->configuration['url']` and `['target']`, so the
 * constructor takes the configuration a real item plugin would have been given.
 */
class TestToolbarItemLink {

  use ToolbarItemLinkTrait;
  use StringTranslationTrait;

  /**
   * The plugin configuration the trait reads.
   *
   * @var array
   */
  protected array $configuration;

  /**
   * Constructs a TestToolbarItemLink.
   *
   * @param array $configuration
   *   The configuration the trait reads, typically a `url` and a `target`.
   */
  public function __construct(array $configuration = []) {
    $this->configuration = $configuration;
  }

  /**
   * Forwards to ToolbarItemLinkTrait::getUriAsDisplayableString().
   *
   * @param string $uri
   *   The URI.
   *
   * @return string
   *   The displayable string.
   */
  public static function uriAsDisplayableString($uri) {
    return static::getUriAsDisplayableString($uri);
  }

  /**
   * Forwards to ToolbarItemLinkTrait::getUserEnteredStringAsUri().
   *
   * @param string $string
   *   The user-entered string.
   *
   * @return string
   *   The URI.
   */
  public static function userEnteredStringAsUri($string) {
    return static::getUserEnteredStringAsUri($string);
  }

  /**
   * Forwards to ToolbarItemLinkTrait::getUrlFromUri().
   *
   * @param mixed $uri
   *   A URI string or a \Drupal\Core\Url.
   *
   * @return \Drupal\Core\Url
   *   The URL.
   */
  public function urlFromUri($uri): Url {
    return $this->getUrlFromUri($uri);
  }

  /**
   * Forwards to ToolbarItemLinkTrait::getUriAsAttributes().
   *
   * @param mixed $uri
   *   A URI string or a \Drupal\Core\Url.
   * @param array $attributes
   *   Attributes to build on top of.
   *
   * @return array
   *   The attributes, including `href` and any active-link data attributes.
   */
  public function uriAsAttributes($uri, array $attributes = []): array {
    return $this->getUriAsAttributes($uri, $attributes);
  }

  /**
   * Forwards to ToolbarItemLinkTrait::uriAccess().
   *
   * @param mixed $uri
   *   A URI string or a \Drupal\Core\Url.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function accessForUri($uri): AccessResultInterface {
    return $this->uriAccess($uri);
  }

  /**
   * Forwards to ToolbarItemLinkTrait::urlForm().
   *
   * @param array $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string|null $defaultValue
   *   The default value.
   *
   * @return array
   *   The url element.
   */
  public function urlElement(array $form, FormStateInterface $form_state, $defaultValue = NULL): array {
    return $this->urlForm($form, $form_state, $defaultValue);
  }

}
