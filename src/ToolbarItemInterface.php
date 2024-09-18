<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar;

use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\neo\VisibilityEntityInterface;

/**
 * Provides an interface defining a neo toolbar item entity type.
 */
interface ToolbarItemInterface extends ConfigEntityInterface, VisibilityEntityInterface {

}
