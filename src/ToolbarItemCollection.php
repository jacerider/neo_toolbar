<?php

declare(strict_types = 1);

namespace Drupal\neo_toolbar;

use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyTrait;
use Drupal\neo\Helpers\Str;

/**
 * A toolbar item collection.
 */
class ToolbarItemCollection implements RefinableCacheableDependencyInterface {
  use RefinableCacheableDependencyTrait;

  /**
   * The toolbar item element alignment.
   *
   * @var string
   */
  protected $alignment;

  /**
   * The toolbar item element style.
   *
   * @var string
   */
  protected $style;

  /**
   * The toolbar item element weight.
   *
   * @var int
   */
  protected $weight;

  /**
   * The toolbar items.
   *
   * @var \Drupal\neo_toolbar\ToolbarItemElement[]
   */
  protected $elements = [];

  /**
   * Constructs a new ToolbarItemCollection.
   *
   * @param string $alignment
   *   The toolbar item element alignment.
   * @param string $style
   *   The toolbar item element style.
   * @param int $weight
   *   The toolbar item element weight.
   */
  public function __construct($alignment, string $style = 'default', int $weight = 0) {
    $this->alignment = $alignment;
    $this->style = Str::snake($style);
    $this->weight = $weight;
  }

  /**
   * Get the toolbar item element alignment.
   *
   * @return string
   *   The toolbar item element alignment.
   */
  public function getAlignment(): string {
    return $this->alignment;
  }

  /**
   * Set the toolbar item element style.
   *
   * @param string $style
   *   The toolbar item element style.
   *
   * @return $this
   */
  public function setStyle(string $style): self {
    $this->style = $style;
    return $this;
  }

  /**
   * Get the toolbar item element style.
   *
   * @return string
   *   The toolbar item element style.
   */
  public function getStyle(): string {
    return $this->style;
  }

  /**
   * Get the toolbar item element weight.
   *
   * @return int
   *   The toolbar item element weight.
   */
  public function getWeight(): int {
    return $this->weight;
  }

  /**
   * Adds a toolbar element.
   *
   * @param \Drupal\neo_toolbar\ToolbarItemElement $element
   *   The toolbar item.
   *
   * @return $this
   */
  public function add(ToolbarItemElement $element): self {
    $this->elements[] = $element;
    $this->addCacheableDependency($element);
    return $this;
  }

  /**
   * Removes a toolbar element.
   *
   * @param string $id
   *   The toolbar item ID.
   *
   * @return $this
   */
  public function remove(string $id): self {
    $this->elements = array_filter($this->elements, static function (ToolbarItemElement $element) use ($id) {
      return $element->id() !== $id;
    });
    return $this;
  }

  /**
   * Gets the toolbar elements.
   *
   * @return \Drupal\neo_toolbar\ToolbarItemElement[]
   *   The toolbar items.
   */
  public function all(): array {
    return $this->elements;
  }

  /**
   * Checks if the collection is empty.
   *
   * @return bool
   *   TRUE if the collection is empty, FALSE otherwise.
   */
  public function isEmpty(): bool {
    return empty($this->elements);
  }

  /**
   * Get render array.
   *
   * @return array
   *   The render array.
   */
  public function toRenderable(): array {
    $build = [];
    if (!empty($this->elements)) {
      $style = $this->getStyle();
      $build = [
        '#theme' => 'neo_toolbar_item',
        '#alignment' => $this->getAlignment(),
        '#style' => $style,
        '#weight' => $this->getWeight(),
      ];
      foreach ($this->elements as $element) {
        $element->setStyle($style);
        $build['#elements'][] = $element->toRenderable();
      }
    }
    return $build;
  }

}
