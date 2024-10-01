<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\neo_toolbar\ToolbarInterface;

/**
 * Returns responses for Neo | Toolbar routes.
 */
final class ToolbarItemAddController extends ControllerBase {

  /**
   * Builds the response.
   */
  public function __invoke(ToolbarInterface $neo_toolbar, string $neo_toolbar_region, string $plugin_id): array {
    $entity = $this->entityTypeManager()->getStorage('neo_toolbar_item')->create([
      'toolbar' => $neo_toolbar->id(),
      'region' => $neo_toolbar_region,
      'plugin' => $plugin_id,
    ]);
    return $this->entityFormBuilder()->getForm($entity, 'add');
  }

}
