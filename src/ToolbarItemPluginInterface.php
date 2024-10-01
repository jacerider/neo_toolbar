<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar;

use Drupal\Component\Plugin\ConfigurableInterface;
use Drupal\Component\Plugin\DerivativeInspectionInterface;
use Drupal\Component\Plugin\PluginInspectionInterface;
use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Plugin\PluginFormInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Interface for neo_toolbar_item plugins.
 */
interface ToolbarItemPluginInterface extends ConfigurableInterface, PluginFormInterface, PluginInspectionInterface, CacheableDependencyInterface, DerivativeInspectionInterface {

  /**
   * Returns the translated plugin label.
   */
  public function label(): string;

  /**
   * Get the plugin style.
   *
   * @return string
   *   The plugin style.
   */
  public function getStyle(): string;

  /**
   * Get the plugin provider.
   */
  public function getProvider(): string;

  /**
   * Get the plugin category.
   */
  public function getCategory(): string;

  /**
   * Get the plugin alignment.
   */
  public function getAlignment(): string;

  /**
   * Get the plugin title.
   */
  public function getTitle(): string;

  /**
   * Get the plugin URL.
   */
  public function getUrl(): string|null;

  /**
   * Get the plugin icon.
   */
  public function getIcon(): string|null;

  /**
   * Get the elements that make up the toolbar item.
   *
   * @return \Drupal\neo_toolbar\ToolbarItemElement[]
   *   The toolbar item elements.
   */
  public function getElements(): array;

  /**
   * Indicates whether the item should be shown.
   *
   * This method allows base implementations to add general access restrictions
   * that should apply to all extending item plugins.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user session for which to check access.
   * @param bool $return_as_object
   *   (optional) Defaults to FALSE.
   *
   * @return bool|\Drupal\Core\Access\AccessResultInterface
   *   The access result. Returns a boolean if $return_as_object is FALSE (this
   *   is the default) and otherwise an AccessResultInterface object.
   *   When a boolean is returned, the result of AccessInterface::isAllowed() is
   *   returned, i.e. TRUE means access is explicitly allowed, FALSE means
   *   access is either explicitly forbidden or "no opinion".
   *
   * @see \Drupal\neo_toolbar\ToolbarItemAccessControlHandler
   */
  public function access(AccountInterface $account, $return_as_object = FALSE);

  /**
   * Suggests a machine name to identify an instance of this item.
   *
   * The item plugin need not verify that the machine name is at all unique. It
   * is only responsible for providing a baseline suggestion; calling code is
   * responsible for ensuring whatever uniqueness is required for the use case.
   *
   * @return string
   *   The suggested machine name.
   */
  public function getMachineNameSuggestion();

}
