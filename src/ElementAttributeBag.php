<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar;

/**
 * The attribute bags a toolbar item element carries, and the definition of set.
 *
 * A toolbar item element carries five `Attribute` objects, and every one of the
 * module's eight Twig templates reads them under the five render-array keys
 * this enum's cases are valued with. The set is closed: an element has these
 * bags and no others.
 *
 * The mapping is the decision the enum encodes. A case's backing value is the
 * render-array key its bag is emitted under, minus the `#` the renderer's own
 * property syntax adds — so `ToolbarItemElement::toRenderable()` builds the
 * five keys by iterating these cases rather than by naming them, and the bag
 * set is stated in exactly one place. Before this existed, adding a sixth bag
 * meant a property, a constructor line, three methods and a render-array line:
 * six edits across three parts of one 946-line file to add one object.
 *
 * `neo_build`'s `Scope` is the in-house precedent for a small backed enum
 * standing in for a closed set. This is the same shape and exists for the same
 * reason.
 *
 * The enum is public because PHP has no other way to spell one, not because
 * anything outside the element is expected to act on a case. It names five
 * things the render array already publishes to every template, and it has no
 * methods: the accessor that answers a bag for a case is deliberately
 * `protected` on the element, so a case's only use from outside is to be handed
 * back to code that already had it.
 */
enum ElementAttributeBag: string {

  case Element = 'attributes';
  case Title = 'title_attributes';
  case Icon = 'icon_attributes';
  case Image = 'image_attributes';
  case Badge = 'badge_attributes';

}
