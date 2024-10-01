<?php

declare(strict_types = 1);

namespace Drupal\neo_toolbar\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\neo_toolbar\ToolbarItemElement;

/**
 * Event that is fired when a badge is added to the toolbar.
 */
class ToolbarBadgeEvent extends Event {

  // This makes it easier for subscribers to reliably use our event name.
  const EVENT_NAME = 'neo_toolbar_badge';

  /**
   * The toolbar item element.
   *
   * @var \Drupal\neo_toolbar\ToolbarItemElement
   */
  protected ToolbarItemElement $element;

  /**
   * The badge value.
   *
   * @var string|int|null
   */
  protected string|int|null $badge = NULL;

  /**
   * Constructs the object.
   */
  public function __construct(ToolbarItemElement $element) {
    $this->element = $element;
  }

  /**
   * Get the toolbar item element.
   *
   * @return \Drupal\neo_toolbar\ToolbarItemElement
   *   The toolbar item element.
   */
  public function getElement(): ToolbarItemElement {
    return $this->element;
  }

  /**
   * Get the badge.
   *
   * @return string|int|null
   *   The badge value.
   */
  public function getBadge(): string|int|null {
    return $this->badge;
  }

  /**
   * Set the badge.
   *
   * @param string|int|null $badge
   *   The badge value.
   */
  public function setBadge(string|int|null $badge): void {
    $this->badge = $badge;
  }

}
