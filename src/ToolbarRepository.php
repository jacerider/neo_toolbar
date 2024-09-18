<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;

/**
 * Toolbar repository manager.
 */
final class ToolbarRepository {

  /**
   * The toolbar.
   *
   * @var \Drupal\neo_toolbar\ToolbarInterface|null
   */
  protected ToolbarInterface|null $toolbar;

  /**
   * Constructs a ToolbarRepository object.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly RouteMatchInterface $routeMatch,
  ) {}

  /**
   * {@inheritDoc}
   */
  public function getActive($checkAccess = TRUE):ToolbarInterface|null {
    if (!isset($this->toolbar)) {
      $this->toolbar = NULL;
      $toolbar = $this->routeMatch->getParameter('neo_toolbar');
      if ($toolbar instanceof ToolbarInterface) {
        $toolbar->setEditMode();
        $this->toolbar = $toolbar;
      }
      else {
        $toolbars = $this->entityTypeManager->getStorage('neo_toolbar')->loadByProperties([
          'status' => TRUE,
        ]);
        if ($checkAccess) {
          $toolbars = array_filter($toolbars, function (ToolbarInterface $toolbar) {
            return $toolbar->access('view');
          });
          if ($toolbars) {
            uasort($toolbars, 'Drupal\neo_toolbar\Entity\Toolbar::sort');
            $this->toolbar = reset($toolbars);
          }
        }
      }
    }
    return $this->toolbar;
  }

}
