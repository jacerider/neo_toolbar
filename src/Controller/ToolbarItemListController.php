<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\neo_toolbar\ToolbarInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns responses for Neo | Toolbar routes.
 */
final class ToolbarItemListController extends ControllerBase {

  /**
   * Builds the response.
   */
  public function __invoke(Request $request, ToolbarInterface $neo_toolbar): array {
    return $this->entityTypeManager()->getListBuilder('neo_toolbar_item')->render($request, $neo_toolbar);
  }

  /**
   * The _title_callback for the toolbar edit route.
   */
  public function titleEdit(ToolbarInterface $neo_toolbar) {
    return $this->t('Toolbar: %label', ['%label' => $neo_toolbar->label()]);
  }

  /**
   * The _title_callback for the toolbar items route.
   */
  public function titleItems(ToolbarInterface $neo_toolbar) {
    return $this->t('Toolbar Items: %label', ['%label' => $neo_toolbar->label()]);
  }

}
