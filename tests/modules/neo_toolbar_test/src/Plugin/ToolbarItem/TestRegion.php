<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar_test\Plugin\ToolbarItem;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_toolbar\Attribute\ToolbarItem;

/**
 * A region-creating twin of the settings-driven test item.
 *
 * Identical to \Drupal\neo_toolbar_test\Plugin\ToolbarItem\TestAccess in every
 * respect but one: it declares `region_create`, so
 * \Drupal\neo_toolbar\Plugin\Derivative\ToolbarRegion derives an `item:<id>`
 * region for each item using it, and the item itself becomes that region's
 * triggering item.
 *
 * neo_toolbar's own `region` plugin also declares `region_create`, but it
 * resolves its access through a configured url, so it cannot be driven to a
 * chosen access outcome — which is the whole point of the fixture.
 */
#[ToolbarItem(
  id: 'neo_toolbar_test_region',
  label: new TranslatableMarkup('Test region item'),
  description: new TranslatableMarkup('A test item that creates a region of its own.'),
  region_create: TRUE,
)]
class TestRegion extends TestAccess {

}
