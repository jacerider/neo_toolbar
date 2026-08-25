<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyInterface;
use Drupal\Core\Cache\RefinableCacheableDependencyTrait;
use Drupal\Core\Entity\EntityInterface;
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
   * Whether the toolbar item element style is forced.
   *
   * @var bool
   */
  protected $styleForce = FALSE;

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
   * The toolbar item element image size.
   *
   * @var array|null
   */
  protected $imageSize = NULL;

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
   * The element's attribute bags, keyed by the ElementAttributeBag value.
   *
   * The storage for every attribute bag the element carries, and the only
   * declaration of it. A key is present once its bag has been asked for; an
   * element that was never written to holds none. ::attributeBag() is the sole
   * reader and writer of this array — nothing else in the class, the
   * constructor and the render array included, knows a bag is stored here at
   * all, which is what makes a sixth bag one ElementAttributeBag case and three
   * forwarders rather than a property, a constructor line and a render-array
   * line as well.
   *
   * @var array<string, \Drupal\Core\Template\Attribute>
   */
  protected array $attributeBags = [];

  /**
   * The toolbar item element attached.
   *
   * @var array
   */
  protected $attached = [];

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
   * @param bool $force
   *   Whether to force the toolbar item element style, preventing it from being
   *   overridden by child elements.
   *
   * @return $this
   */
  public function setStyle(string $style, $force = FALSE): self {
    if ($force) {
      $this->styleForce = TRUE;
    }
    $this->style = $style;
    return $this;
  }

  /**
   * Get whether the toolbar item element style is forced.
   *
   * @return bool
   *   Whether the toolbar item element style is forced.
   */
  public function isStyleForced(): bool {
    return $this->styleForce;
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
   * Check whether the toolbar item element will be rendered.
   *
   * @return bool
   *   TRUE if the element is accessible, FALSE otherwise.
   */
  public function isAccessible(): bool {
    $access = $this->getAccess();
    return $access instanceof AccessResultInterface ? $access->isAllowed() : $access;
  }

  /**
   * Set the toolbar item element title.
   *
   * @param string|\Drupal\Component\Render\MarkupInterface $title
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
   * @return string|\Drupal\Component\Render\MarkupInterface
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
   * @param string|\Drupal\Component\Render\MarkupInterface $text
   *   The text to use to find the icon.
   *
   * @return $this
   */
  public function setDynamicIcon(string|MarkupInterface $text): self {
    // Entity will be found in the route parameters.
    $prefix = ['admin'];
    $route_match = \Drupal::routeMatch();
    if (($route = $route_match->getRouteObject()) && ($parameters = $route->getOption('parameters'))) {
      // Determine if the current route represents an entity.
      foreach ($parameters as $name => $options) {
        if (isset($options['type']) && strpos($options['type'], 'entity:') === 0) {
          $entity = $route_match->getParameter($name);
          if ($entity instanceof EntityInterface) {
            $prefix[] = 'entity';
            $prefix[] = 'entity.' . $entity->getEntityTypeId();
          }
        }
      }
    }
    if ($icon = $this->loadIcon($text, NULL, NULL, $prefix)) {
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
   * Set the toolbar item element image size.
   *
   * @param int $width
   *   The image width.
   * @param int|null $height
   *   The image height. Defaults to the width when not provided.
   *
   * @return $this
   */
  public function setImageSize(int $width, ?int $height = NULL): self {
    $this->imageSize = [
      'width' => $width,
      'height' => $height ?? $width,
    ];
    return $this;
  }

  /**
   * Get the toolbar item element image size.
   *
   * @return array|null
   *   The toolbar item element image size, or NULL when not set.
   */
  public function getImageSize(): ?array {
    return $this->imageSize;
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
   * Get one of the element's five attribute bags, creating it on first use.
   *
   * The one route to a bag, and the only method in the class that knows where
   * a bag is stored. All fifteen public writers below reach their bag through
   * here, as does ::toRenderable(), so a sixth bag costs one
   * ElementAttributeBag case and three forwarders rather than six edits in
   * three places — in the storage as well as in the writers, because the bags
   * are one array keyed by the case's value rather than a property apiece.
   *
   * Two behaviours here are load-bearing.
   *
   * It answers the element's *own* object, never a clone. The render array
   * carries these objects by reference, and ::toRenderable() writes into the
   * element bag twice after the array has been assembled: a tooltip applies
   * itself to it, and a modal merges its trigger attributes into it. Neither
   * reaches a template if a copy is handed out.
   *
   * It creates a bag on first use rather than in the constructor, which is
   * what lets an element be built without five Attribute objects. A bag is
   * created once and memoised, so the object handed to the render array is the
   * object every later call answers.
   *
   * Protected, deliberately: all five bags are already reachable from a
   * fixture-free unit test through the fifteen methods that have always been
   * public, so publishing this would add a permanent promise to an API another
   * package calls and change nothing about what a test has to build.
   *
   * @param \Drupal\neo_toolbar\ElementAttributeBag $bag
   *   The bag to answer.
   *
   * @return \Drupal\Core\Template\Attribute
   *   The element's own attribute object for that bag.
   */
  protected function attributeBag(ElementAttributeBag $bag): Attribute {
    return $this->attributeBags[$bag->value] ??= new Attribute();
  }

  /**
   * Add a class to the toolbar item element attributes.
   *
   * The collected classes are handed to Attribute::addClass() as a single
   * argument rather than spread, which is what this method has always done.
   * For the plain strings every caller passes the two are identical; for an
   * array argument they are not, and that difference is behaviour.
   *
   * @param string|string[] ...$classes
   *   CSS classes to add to the class attribute array.
   *
   * @return $this
   */
  public function addClass(string|array ...$classes): self {
    if ($classes) {
      $this->attributeBag(ElementAttributeBag::Element)->addClass($classes);
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
    $this->attributeBag(ElementAttributeBag::Element)->setAttribute($key, $value);
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
    $this->attributeBag(ElementAttributeBag::Element)
      ->merge(is_array($attributes) ? new Attribute($attributes) : $attributes);
    return $this;
  }

  /**
   * Add a class to the toolbar item element title attributes.
   *
   * @param string|string[] ...$classes
   *   CSS classes to add to the class attribute array.
   *
   * @return $this
   */
  public function addTitleClass(string|array ...$classes): self {
    if ($classes) {
      $this->attributeBag(ElementAttributeBag::Title)->addClass($classes);
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
    $this->attributeBag(ElementAttributeBag::Title)->setAttribute($key, $value);
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
    $this->attributeBag(ElementAttributeBag::Title)
      ->merge(is_array($attributes) ? new Attribute($attributes) : $attributes);
    return $this;
  }

  /**
   * Add a class to the toolbar item element icon attributes.
   *
   * @param string|string[] ...$classes
   *   CSS classes to add to the class attribute array.
   *
   * @return $this
   */
  public function addIconClass(string|array ...$classes): self {
    if ($classes) {
      $this->attributeBag(ElementAttributeBag::Icon)->addClass($classes);
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
    $this->attributeBag(ElementAttributeBag::Icon)->setAttribute($key, $value);
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
    $this->attributeBag(ElementAttributeBag::Icon)
      ->merge(is_array($attributes) ? new Attribute($attributes) : $attributes);
    return $this;
  }

  /**
   * Add a class to the toolbar item element image attributes.
   *
   * @param string|string[] ...$classes
   *   CSS classes to add to the class attribute array.
   *
   * @return $this
   */
  public function addImageClass(string|array ...$classes): self {
    if ($classes) {
      $this->attributeBag(ElementAttributeBag::Image)->addClass($classes);
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
    $this->attributeBag(ElementAttributeBag::Image)->setAttribute($key, $value);
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
    $this->attributeBag(ElementAttributeBag::Image)
      ->merge(is_array($attributes) ? new Attribute($attributes) : $attributes);
    return $this;
  }

  /**
   * Add a class to the toolbar item element badge attributes.
   *
   * @param string|string[] ...$classes
   *   CSS classes to add to the class attribute array.
   *
   * @return $this
   */
  public function addBadgeClass(string|array ...$classes): self {
    if ($classes) {
      $this->attributeBag(ElementAttributeBag::Badge)->addClass($classes);
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
    $this->attributeBag(ElementAttributeBag::Badge)->setAttribute($key, $value);
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
    $this->attributeBag(ElementAttributeBag::Badge)
      ->merge(is_array($attributes) ? new Attribute($attributes) : $attributes);
    return $this;
  }

  /**
   * Add a library attachment to the toolbar item element.
   *
   * @param string $attachment
   *   The attachment.
   *
   * @return $this
   */
  public function addLibrary(string $attachment): self {
    $this->attached['library'][] = $attachment;
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
   * @param array $titleAttributes
   *   The modal title attributes.
   *
   * @return $this
   */
  public function setModal($content, $title = NULL, $titleAttributes = []): self {
    $build = [
      '#theme' => 'neo_toolbar_modal',
      '#title' => $title,
      '#content' => $content,
      '#title_attributes' => $titleAttributes,
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
    $modal->setContentScroll(TRUE);
    $modal->setHeaderColor('rgb(var(--color-base-950))');
    $modal->setHeaderColorBg('rgb(var(--color-base-0))');
    $modal->setContentColor('rgb(var(--color-base-950))');
    $modal->setContentColorBg('rgb(var(--color-base-0))');
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
      '#image_size' => $this->getImageSize(),
      '#badge' => $this->getBadge(),
      '#access' => $access,
      '#weight' => $this->weight,
      '#attached' => $this->attached,
      '#cache' => [
        'contexts' => $this->getCacheContexts(),
        'tags' => $this->getCacheTags(),
        'max-age' => $this->getCacheMaxAge(),
      ],
    ];
    // The five bags, from the one place the set is stated. Each case's value is
    // the render-array key its bag is emitted under, and what goes in is the
    // element's own object: both branches below write into the element bag
    // after this point and reach the template only by reference.
    foreach (ElementAttributeBag::cases() as $bag) {
      $build['#' . $bag->value] = $this->attributeBag($bag);
    }
    if ($this->tooltipStatus && !$titleStatus) {
      $tooltip = new Tooltip($title);
      $tooltip->setPlacement($alignment === 'vertical' ? 'right' : 'bottom');
      $tooltip->applyToAttribute($this->attributeBag(ElementAttributeBag::Element));
      foreach ($tooltip->getAttachments() as $type => $attachments) {
        foreach ($attachments as $attachment) {
          $build['#attached'][$type][] = $attachment;
        }
      }
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
