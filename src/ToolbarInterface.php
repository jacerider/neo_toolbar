<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\neo\VisibilityEntityInterface;

/**
 * Provides an interface defining a toolbar entity type.
 */
interface ToolbarInterface extends ConfigEntityInterface, VisibilityEntityInterface {

  /**
   * Sets the toolbar edit mode.
   *
   * @param bool $isEditMode
   *   TRUE if the toolbar is in edit mode, FALSE otherwise.
   *
   * @return $this
   */
  public function setEditMode(bool $isEditMode = TRUE):self;

  /**
   * Checks if the toolbar is in edit mode.
   *
   * @return bool
   *   TRUE if the toolbar is in edit mode, FALSE otherwise.
   */
  public function isEditMode():bool;

}
