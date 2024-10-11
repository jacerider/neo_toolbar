<?php

declare(strict_types = 1);

namespace Drupal\neo_toolbar;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyTrait;
use Drupal\Core\Template\Attribute;
use Drupal\neo_icon\IconRepositoryTrait;
use Drupal\neo_modal\Modal;
use Drupal\neo_tooltip\Tooltip;

/**
 * A toolbar item element.
 */
class ToolbarItemElement implements RefinableCacheableDependencyInterface {
  use IconRepositoryTrait;
  use RefinableCacheableDependencyTrait;

  /**
   * The toolbar item element ID.
   *
   * @var string
   */
  protected $id;

  /**
   * The toolbar item element tag.
   *
   * @var string
   */
  protected $tag = 'span';

  /**
   * The toolbar item element style.
   *
   * @var string
   */
  protected $style = 'default';

  /**
   * The toolbar item element access property.
   *
   * @var \Drupal\Core\Access\AccessResult|bool
   */
  protected $access = TRUE;

  /**
   * The toolbar item element title.
   *
   * @var string
   */
  protected $title = '';

  /**
   * Whether to show the toolbar item element title.
   *
   * @var bool
   */
  protected $titleStatus = TRUE;

  /**
   * The toolbar item element alignment.
   *
   * @var string
   */
  protected $alignment;

  /**
   * The toolbar item element icon.
   *
   * @var string
   */
  protected $icon = '';

  /**
   * The toolbar item element image URI.
   *
   * @var string
   */
  protected $image = '';

  /**
   * The toolbar item element badge.
   *
   * @var string|int|null
   */
  protected $badge = NULL;

  /**
   * The toolbar item element weight.
   *
   * @var int
   */
  protected $weight = 0;

  /**
   * Whether to show the title as a tooltip.
   *
   * @var bool
   */
  protected $tooltipStatus = TRUE;

  /**
   * The toolbar item element attributes.
   *
   * @var \Drupal\Core\Template\Attribute
   */
  protected $attributes;

  /**
   * The toolbar item element title attributes.
   *
   * @var \Drupal\Core\Template\Attribute
   */
  protected $titleAttributes;

  /**
   * The toolbar item element icon attributes.
   *
   * @var \Drupal\Core\Template\Attribute
   */
  protected $iconAttributes;

  /**
   * The toolbar item element image attributes.
   *
   * @var \Drupal\Core\Template\Attribute
   */
  protected $imageAttributes;

  /**
   * The toolbar item element badge attributes.
   *
   * @var \Drupal\Core\Template\Attribute
   */
  protected $badgeAttributes;

  /**
   * The toolbar item element children.
   *
   * @var \Drupal\neo_toolbar\ToolbarItemElement[]
   */
  protected $children = [];

  /**
   * The toolbar item element children style.
   *
   * @var string
   */
  protected $childrenStyle = '';

  /**
   * The toolbar item element modal.
   *
   * @var \Drupal\neo_modal\Modal
   */
  protected $modal;

  /**
   * Constructs a new ToolbarItemElement.
   *
   * @param string $id
   *   The toolbar item element ID.
   * @param string $title
   *   The toolbar item element title.
   * @param string $alignment
   *   The toolbar item element alignment.
   */
  public function __construct($id, $title, $alignment) {
    $this->id = $id;
    $this->title = $title;
    $this->setAlignment($alignment);
    $this->attributes = new Attribute();
    $this->titleAttributes = new Attribute();
    $this->iconAttributes = new Attribute();
    $this->imageAttributes = new Attribute();
    $this->badgeAttributes = new Attribute();
  }

  /**
   * Gets the toolbar item element ID.
   *
   * @return string
   *   The toolbar item element ID.
   */
  public function id(): string {
    return $this->id;
  }

  /**
   * Set the toolbar item element tag.
   *
   * @param string $tag
   *   The toolbar item element tag.
   *
   * @return $this
   */
  public function setTag(string $tag): self {
    $this->tag = $tag;
    return $this;
  }

  /**
   * Get the toolbar item element tag.
   *
   * @return string
   *   The toolbar item element tag.
   */
  public function getTag(): string {
    return $this->tag;
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
   * Set the toolbar item element access property.
   *
   * @param \Drupal\Core\Access\AccessResult|bool $access
   *   The toolbar item element access property.
   *
   * @return $this
   */
  public function setAccess(AccessResult|bool $access): self {
    $this->access = $access;
    return $this;
  }

  /**
   * Get the toolbar item element access property.
   *
   * @return \Drupal\Core\Access\AccessResult|bool
   *   The toolbar item element access property.
   */
  public function getAccess(): AccessResult|bool {
    return $this->access;
  }

  /**
   * Set the toolbar item element title.
   *
   * @param string|Drupal\Component\Render\MarkupInterface $title
   *   The toolbar item element title.
   *
   * @return $this
   */
  public function setTitle(string|MarkupInterface $title): self {
    $this->title = $title;
    return $this;
  }

  /**
   * Get the toolbar item element title.
   *
   * @return string|Drupal\Component\Render\MarkupInterface
   *   The toolbar item element title.
   */
  public function getTitle(): string|MarkupInterface {
    return $this->title;
  }

  /**
   * Set whether to show the toolbar item element title.
   *
   * @param bool $showTitle
   *   Whether to show the toolbar item element title.
   *
   * @return $this
   */
  public function showTitle(bool $showTitle): self {
    $this->titleStatus = $showTitle;
    return $this;
  }

  /**
   * Set the toolbar item element alignment.
   *
   * @param string $alignment
   *   The toolbar item element alignment.
   *
   * @return $this
   */
  public function setAlignment(string $alignment): self {
    $this->alignment = $alignment;
    $this->showTitle($alignment !== 'vertical');
    $this->showTooltip($alignment !== 'horizontal');
    return $this;
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
   * Set the toolbar item element icon.
   *
   * @param string $icon
   *   The toolbar item element icon.
   *
   * @return $this
   */
  public function setIcon(string $icon): self {
    $this->icon = $icon;
    return $this;
  }

  /**
   * Set the toolbar item element icon dynamically.
   *
   * @param string|Drupal\Component\Render\MarkupInterface $text
   *   The text to use to find the icon.
   *
   * @return $this
   */
  public function setDynamicIcon(string|MarkupInterface $text): self {
    if ($icon = $this->loadIcon($text, NULL, NULL, ['admin'])) {
      $this->setIcon($icon->getName());
    }
    return $this;
  }

  /**
   * Get the toolbar item element icon.
   *
   * @return string
   *   The toolbar item element icon.
   */
  public function getIcon(): string {
    return $this->icon;
  }

  /**
   * Set the toolbar item element image URI.
   *
   * @param string $image
   *   The toolbar item element image URI.
   *
   * @return $this
   */
  public function setImage(string $image): self {
    $this->image = $image;
    return $this;
  }

  /**
   * Get the toolbar item element image URI.
   *
   * @return string
   *   The toolbar item element image URI.
   */
  public function getImage(): string {
    return $this->image;
  }

  /**
   * Set the toolbar item element badge.
   *
   * @param string|int|null $badge
   *   The toolbar item element badge.
   *
   * @return $this
   */
  public function setBadge(string|int|null $badge): self {
    $this->badge = $badge;
    return $this;
  }

  /**
   * Get the toolbar item element badge.
   *
   * @return string|int|null
   *   The toolbar item element badge.
   */
  public function getBadge(): string|int|null {
    return $this->badge;
  }

  /**
   * Set the toolbar item element weight.
   *
   * @param int $weight
   *   The toolbar item element weight.
   *
   * @return $this
   */
  public function setWeight(int $weight): self {
    $this->weight = $weight;
    return $this;
  }

  /**
   * Set whether to show the title as a tooltip.
   *
   * @param bool $showTooltip
   *   Whether to show the title as a tooltip.
   *
   * @return $this
   */
  public function showTooltip(bool $showTooltip): self {
    $this->tooltipStatus = $showTooltip;
    return $this;
  }

  /**
   * Add a class to the toolbar item element attributes.
   *
   * @param string|array ...
   *   CSS classes to add to the class attribute array.
   *
   * @return $this
   */
  public function addClass(): self {
    $args = func_get_args();
    if ($args) {
      $this->attributes->addClass($args);
    }
    return $this;
  }

  /**
   * Set an attribute to the toolbar item element attributes.
   *
   * @param string $key
   *   The attribute key.
   * @param string $value
   *   The attribute value.
   *
   * @return $this
   */
  public function setAttribute(string $key, string $value): self {
    $this->attributes->setAttribute($key, $value);
    return $this;
  }

  /**
   * Merge attributes into the toolbar item element attributes.
   *
   * @param array|\Drupal\Core\Template\Attribute $attributes
   *   The attributes to merge.
   *
   * @return $this
   */
  public function mergeAttributes(array|Attribute $attributes): self {
    if (is_array($attributes)) {
      $attributes = new Attribute($attributes);
    }
    $this->attributes->merge($attributes);
    return $this;
  }

  /**
   * Add a class to the toolbar item element title attributes.
   *
   * @param string|array ...
   *   CSS classes to add to the class attribute array.
   *
   * @return $this
   */
  public function addTitleClass(): self {
    $args = func_get_args();
    if ($args) {
      $this->titleAttributes->addClass($args);
    }
    return $this;
  }

  /**
   * Set an attribute to the toolbar item element title attributes.
   *
   * @param string $key
   *   The attribute key.
   * @param string $value
   *   The attribute value.
   *
   * @return $this
   */
  public function setTitleAttribute(string $key, string $value): self {
    $this->titleAttributes->setAttribute($key, $value);
    return $this;
  }

  /**
   * Merge attributes into the toolbar item element title attributes.
   *
   * @param array|\Drupal\Core\Template\Attribute $attributes
   *   The attributes to merge.
   *
   * @return $this
   */
  public function mergeTitleAttributes(array|Attribute $attributes): self {
    if (is_array($attributes)) {
      $attributes = new Attribute($attributes);
    }
    $this->titleAttributes->merge($attributes);
    return $this;
  }

  /**
   * Add a class to the toolbar item element icon attributes.
   *
   * @param string|array ...
   *   CSS classes to add to the class attribute array.
   *
   * @return $this
   */
  public function addIconClass(): self {
    $args = func_get_args();
    if ($args) {
      $this->iconAttributes->addClass($args);
    }
    return $this;
  }

  /**
   * Set an attribute to the toolbar item element icon attributes.
   *
   * @param string $key
   *   The attribute key.
   * @param string $value
   *   The attribute value.
   *
   * @return $this
   */
  public function setIconAttribute(string $key, string $value): self {
    $this->iconAttributes->setAttribute($key, $value);
    return $this;
  }

  /**
   * Merge attributes into the toolbar item element icon attributes.
   *
   * @param array|\Drupal\Core\Template\Attribute $attributes
   *   The attributes to merge.
   *
   * @return $this
   */
  public function mergeIconAttributes(array|Attribute $attributes): self {
    if (is_array($attributes)) {
      $attributes = new Attribute($attributes);
    }
    $this->iconAttributes->merge($attributes);
    return $this;
  }

  /**
   * Add a class to the toolbar item element image attributes.
   *
   * @param string|array ...
   *   CSS classes to add to the class attribute array.
   *
   * @return $this
   */
  public function addImageClass(): self {
    $args = func_get_args();
    if ($args) {
      $this->imageAttributes->addClass($args);
    }
    return $this;
  }

  /**
   * Set an attribute to the toolbar item element image attributes.
   *
   * @param string $key
   *   The attribute key.
   * @param string $value
   *   The attribute value.
   *
   * @return $this
   */
  public function setImageAttribute(string $key, string $value): self {
    $this->imageAttributes->setAttribute($key, $value);
    return $this;
  }

  /**
   * Merge attributes into the toolbar item element image attributes.
   *
   * @param array|\Drupal\Core\Template\Attribute $attributes
   *   The attributes to merge.
   *
   * @return $this
   */
  public function mergeImageAttributes(array|Attribute $attributes): self {
    if (is_array($attributes)) {
      $attributes = new Attribute($attributes);
    }
    $this->imageAttributes->merge($attributes);
    return $this;
  }

  /**
   * Add a class to the toolbar item element badge attributes.
   *
   * @param string|array ...
   *   CSS classes to add to the class attribute array.
   *
   * @return $this
   */
  public function addBadgeClass(): self {
    $args = func_get_args();
    if ($args) {
      $this->badgeAttributes->addClass($args);
    }
    return $this;
  }

  /**
   * Set an attribute to the toolbar item element badge attributes.
   *
   * @param string $key
   *   The attribute key.
   * @param string $value
   *   The attribute value.
   *
   * @return $this
   */
  public function setBadgeAttribute(string $key, string $value): self {
    $this->badgeAttributes->setAttribute($key, $value);
    return $this;
  }

  /**
   * Merge attributes into the toolbar item element badge attributes.
   *
   * @param array|\Drupal\Core\Template\Attribute $attributes
   *   The attributes to merge.
   *
   * @return $this
   */
  public function mergeBadgeAttributes(array|Attribute $attributes): self {
    if (is_array($attributes)) {
      $attributes = new Attribute($attributes);
    }
    $this->badgeAttributes->merge($attributes);
    return $this;
  }

  /**
   * Add a child to the toolbar item element.
   *
   * @param \Drupal\neo_toolbar\ToolbarItemElement $element
   *   The toolbar item element.
   *
   * @return $this
   */
  public function addChild(ToolbarItemElement $element): self {
    $this->children[] = $element;
    return $this;
  }

  /**
   * Get the toolbar item element children.
   *
   * @return \Drupal\neo_toolbar\ToolbarItemElement[]
   *   The toolbar item element children.
   */
  public function getChildren(): array {
    return $this->children;
  }

  /**
   * Set the toolbar item element children style.
   *
   * @param string $style
   *   The toolbar item element children style.
   *
   * @return $this
   */
  public function setChildrenStyle(string $style): self {
    $this->childrenStyle = $style;
    return $this;
  }

  /**
   * Get the toolbar item element children style.
   *
   * @return string
   *   The toolbar item element children style.
   */
  public function getChildrenStyle(): string {
    return $this->childrenStyle ?: $this->getStyle();
  }

  /**
   * Set a modal for the toolbar item element.
   *
   * @param string $content
   *   The modal content.
   * @param string|null $title
   *   The modal title.
   *
   * @return $this
   */
  public function setModal($content, $title = NULL): self {
    $build = [
      '#theme' => 'neo_toolbar_modal',
      '#title' => $title,
      '#content' => $content,
    ];
    $modal = new Modal($build);
    $modal->setPlacementToLeft();
    $modal->setHeight('100%');
    $modal->setWidth('300px');
    $modal->setHeaderInContent();
    $modal->setNest(FALSE);
    $modal->setDisplaceTop('0px');
    $modal->setZindex(60);
    $modal->setCloseButton('end-out');
    $modal->setContentAnimateIn('slideInLeft');
    $modal->setContentAnimateOut('slideOutLeft');
    $modal->setContentPadding('0px');
    $this->modal = $modal;
    return $this;
  }

  /**
   * Get the toolbar item element modal.
   *
   * @return \Drupal\neo_modal\Modal|null
   *   The toolbar item element modal.
   */
  public function getModal(): Modal|null {
    return $this->modal ?? NULL;
  }

  /**
   * Get render array.
   *
   * @return array
   *   The render array.
   */
  public function toRenderable(): array {
    $title = $this->getTitle();
    $alignment = $this->getAlignment();
    $icon = $this->getIcon();
    $image = $this->getImage();
    $access = $this->getAccess();
    $titleStatus = $this->titleStatus;
    if ($alignment === 'horizontal') {
      if (!$icon && !$image) {
        $titleStatus = TRUE;
      }
    }
    if ($access instanceof AccessResult) {
      $this->addCacheableDependency($access);
    }
    $build = [
      '#theme' => 'neo_toolbar_element',
      '#id' => $this->id(),
      '#tag' => $this->getTag(),
      '#alignment' => $alignment,
      '#style' => $this->getStyle(),
      '#title' => $titleStatus ? $title : '',
      '#icon' => $icon,
      '#image' => $image,
      '#badge' => $this->getBadge(),
      '#attributes' => $this->attributes,
      '#title_attributes' => $this->titleAttributes,
      '#icon_attributes' => $this->iconAttributes,
      '#image_attributes' => $this->imageAttributes,
      '#badge_attributes' => $this->badgeAttributes,
      '#access' => $access,
      '#weight' => $this->weight,
      '#cache' => [
        'contexts' => $this->getCacheContexts(),
        'tags' => $this->getCacheTags(),
        'max-age' => $this->getCacheMaxAge(),
      ],
    ];
    if ($this->tooltipStatus && !$titleStatus) {
      $tooltip = new Tooltip($title);
      $tooltip->setPlacement($alignment === 'vertical' ? 'right' : 'bottom');
      $tooltip->applyToAttribute($this->attributes);
      $build['#attached'] = $tooltip->getAttachments();
    }
    if ($modal = $this->getModal()) {
      $this->mergeAttributes($modal->getTriggerAttributes());
      $build['#after']['modal'] = $modal->buildContent();
      foreach ($modal->getAttachments() as $type => $attachments) {
        foreach ($attachments as $attachment) {
          $build['#attached'][$type][] = $attachment;
        }
      }
    }
    if ($children = $this->getChildren()) {
      $collection = new ToolbarItemCollection($alignment, $this->getChildrenStyle());
      foreach ($children as $child) {
        $collection->add($child);
      }
      $build['#children'] = $collection->toRenderable();
    }
    return $build;
  }

}
