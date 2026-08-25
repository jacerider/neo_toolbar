<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_toolbar\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_modal\Modal;
use Drupal\neo_toolbar\ToolbarItemElement;
use PHPUnit\Framework\Attributes\Group;

/**
 * Characterises the element's modal branch.
 *
 * Every other criterion of the element's render array is a unit test — see
 * `ToolbarItemElementRenderTest`. This one is not, and the reason is one line:
 * `setModal()` constructs a `neo_modal` `Modal`, whose constructor calls
 * `getSettings()->getDiffValues()` and therefore reads the `neo_modal.settings`
 * repository out of the container before it has done anything else. That is a
 * real service over real config, not a seam a stub can stand in for — the
 * trigger attributes the branch merges are a *diff* against exactly those
 * saved values, so a stub would be choosing the answer rather than reading it.
 *
 * The branch does three things, and the region flyout in `neo_toolbar`'s own
 * `Region` plugin depends on all three: the trigger attributes go onto the
 * element, so the item itself becomes the thing that opens the panel; the
 * modal's content goes under `#after`, so the `<template>` is a sibling of the
 * trigger rather than inside it; and the modal's attachments are folded into
 * the element's, so `neo_modal/modal` is loaded by the item that needs it.
 *
 * The seventeen `set*()` calls in `setModal()` are the toolbar's shelf preset —
 * a full-height panel sliding in from the left, under the toolbar's z-index,
 * with its header inside the content. They are asserted through the trigger
 * attributes rather than one by one, because the attributes are what the
 * browser reads and `Modal`'s own setters are `neo_modal`'s to test.
 */
#[Group('neo_toolbar')]
final class ToolbarItemElementModalTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * Wider than the `system`/`user`/`neo_toolbar` floor the other kernel tests
   * keep to, and the spec names this as one of the two tests expected to go
   * wider: `neo_modal` and its `neo_settings` repository have to be installed
   * for the modal to be constructible at all, and `neo` and `neo_tooltip` are
   * `neo_modal`'s own container dependencies.
   */
  protected static $modules = [
    'system',
    'user',
    'neo',
    'neo_settings',
    'neo_tooltip',
    'neo_modal',
    'neo_toolbar',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The trigger attributes are a diff against the saved defaults, so the
    // defaults have to exist. `neo_toolbar`'s own `config/install` is
    // deliberately not installed — one of its shipped items carries a plugin
    // from `neo_favicon`, a package this module does not depend on.
    $this->installConfig(['neo_modal']);
  }

  /**
   * Nothing happens until a modal is set.
   *
   * The whole branch is guarded on `getModal()`, which answers NULL while the
   * property is unset — the `?? NULL` is doing real work, because the property
   * is typed and reading it uninitialised would be a fatal.
   */
  public function testAnElementWithoutModalHasNoModalBranch(): void {
    $element = $this->element();

    $this->assertNull($element->getModal());

    $build = $element->toRenderable();
    $this->assertArrayNotHasKey('#after', $build);
    $this->assertSame(['library' => ['neo_toolbar/toolbar']], $build['#attached']);
    $this->assertSame([], $build['#attributes']->toArray());
  }

  /**
   * The three things the branch does, on one element.
   *
   * Covers: it merges a modal's trigger attributes, puts its content under
   * #after and collects its attachments.
   */
  public function testModalTriggerAttributesContentAndAttachments(): void {
    $element = $this->element();
    $element->addClass('neo-toolbar-item');
    $element->setModal('Panel content', 'Panel title', ['class' => ['panel-title']]);

    $modal = $element->getModal();
    $this->assertInstanceOf(Modal::class, $modal);

    $build = $element->toRenderable();

    // 1. The trigger attributes are merged into the element's own bag, beside
    // what the caller had already put there.
    $attributes = $build['#attributes']->toArray();
    $this->assertSame(['neo-toolbar-item', 'use-neo-modal'], $attributes['class']);
    // Everything the modal asked for arrived, and nothing else did. Compared
    // without the class, which is the one key the merge has two sources for.
    $trigger = $modal->getTriggerAttributes()->toArray();
    unset($trigger['class']);
    $merged = $attributes;
    unset($merged['class']);
    $this->assertSame(
      $trigger,
      $merged,
      'The element carries exactly the modal trigger attributes.'
    );

    // The toolbar's shelf preset, as the browser reads it: a full-height
    // 300px panel sliding in from the left, under the toolbar's own z-index,
    // with the close button outside the panel's end edge.
    $this->assertSame('left', $attributes['data-neo-modal-placement']);
    $this->assertSame('300px', $attributes['data-neo-modal-width']);
    $this->assertSame('100%', $attributes['data-neo-modal-height']);
    $this->assertSame(60, $attributes['data-neo-modal-zIndex']);
    $this->assertSame('0px', $attributes['data-neo-modal-displaceTop']);
    $this->assertSame('end-out', $attributes['data-neo-modal-closeButton']);
    $this->assertSame('slideInLeft', $attributes['data-neo-modal-contentAnimateIn']);
    $this->assertSame('slideOutLeft', $attributes['data-neo-modal-contentAnimateOut']);

    // 2. The content is a sibling of the trigger, not a child of it.
    $this->assertArrayHasKey('#after', $build);
    $this->assertSame(['modal'], array_keys($build['#after']));
    $content = $build['#after']['modal'];
    $this->assertSame('neo_toolbar_modal', $content['#theme']);
    $this->assertSame('Panel title', $content['#title']);
    $this->assertSame('Panel content', $content['#content']);
    $this->assertSame(['class' => ['panel-title']], $content['#title_attributes']);
    // `buildContent()` wraps it in the tag the modal renders from.
    $this->assertStringStartsWith('<template', (string) $content['#prefix']);
    $this->assertSame('</template>', (string) $content['#suffix']);

    // 3. The modal's attachments are appended to the element's own.
    $this->assertContains('neo_modal/modal', $build['#attached']['library']);
    $this->assertSame(
      ['neo_toolbar/toolbar', 'neo_modal/modal'],
      $build['#attached']['library'],
      "The element's own library is kept and the modal's is appended."
    );
    foreach ($modal->getAttachments() as $type => $attachments) {
      foreach ($attachments as $attachment) {
        $this->assertContains($attachment, $build['#attached'][$type]);
      }
    }
  }

  /**
   * Builds a horizontal element with a title, and one library of its own.
   *
   * Horizontal-with-a-title is what keeps the tooltip branch out of the way:
   * a hidden title would put `Tooltip`'s attributes into the same bag and its
   * library into the same list, and this class is about the modal's.
   *
   * @return \Drupal\neo_toolbar\ToolbarItemElement
   *   A toolbar item element with no modal set.
   */
  private function element(): ToolbarItemElement {
    $element = new ToolbarItemElement('neo_toolbar_test', 'Test item', 'horizontal');
    $element->addLibrary('neo_toolbar/toolbar');
    return $element;
  }

}
