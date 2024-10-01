<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar;

use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\neo\VisibilityEntityAccessControlTrait;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Access\AccessResult;

/**
 * Defines the access control handler for the Neo Toolbar entity type.
 *
 * @see \Drupal\neo_toolbar\Entity\ToolbarItem
 */
class ToolbarItemAccessControlHandler extends EntityAccessControlHandler implements EntityHandlerInterface {
  use VisibilityEntityAccessControlTrait {
    VisibilityEntityAccessControlTrait::checkAccess as checkVisibilityAccess;
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    /** @var \Drupal\neo_toolbar\ToolbarItemInterface $entity */
    if ($operation !== 'view') {
      $admin_permission = $entity->getEntityType()->getAdminPermission();
      return AccessResult::allowedIfHasPermission($account, $admin_permission);
    }
    $itemAccess = $entity->getPlugin()->access($account, TRUE);
    if ($itemAccess->isForbidden()) {
      return $itemAccess;
    }
    return $this->checkVisibilityAccess($entity, $operation, $account);
  }

  /**
   * {@inheritdoc}
   */
  protected function getDefaultVisibilityAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    return AccessResult::allowed()->addCacheContexts(['user.permissions']);
  }

}
