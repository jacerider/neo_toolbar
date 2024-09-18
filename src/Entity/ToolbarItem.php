<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\neo\VisibilityEntityTrait;
use Drupal\neo_toolbar\ToolbarItemInterface;

/**
 * Defines the Toolbar item entity type.
 *
 * @ConfigEntityType(
 *   id = "neo_toolbar_item",
 *   label = @Translation("Toolbar Item"),
 *   label_collection = @Translation("Toolbar Items"),
 *   label_singular = @Translation("Toolbar item"),
 *   label_plural = @Translation("Toolbar items"),
 *   label_count = @PluralTranslation(
 *     singular = "@count Toolbar item",
 *     plural = "@count Toolbar items",
 *   ),
 *   handlers = {
 *     "list_builder" = "Drupal\neo_toolbar\ToolbarItemListBuilder",
 *     "form" = {
 *       "add" = "Drupal\neo_toolbar\Form\ToolbarItemForm",
 *       "edit" = "Drupal\neo_toolbar\Form\ToolbarItemForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm",
 *     },
 *   },
 *   config_prefix = "neo_toolbar_item",
 *   admin_permission = "administer neo_toolbar",
 *   links = {
 *     "collection" = "/admin/structure/neo-toolbar-item",
 *     "add-form" = "/admin/structure/neo-toolbar-item/add",
 *     "edit-form" = "/admin/structure/neo-toolbar-item/{neo_toolbar_item}",
 *     "delete-form" = "/admin/structure/neo-toolbar-item/{neo_toolbar_item}/delete",
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "toolbar",
 *     "region",
 *     "weight",
 *     "visibility",
 *   },
 * )
 */
final class ToolbarItem extends ConfigEntityBase implements ToolbarItemInterface {
  use VisibilityEntityTrait;

  /**
   * The toolbar item ID.
   */
  protected string $id;

  /**
   * The toolbar item label.
   */
  protected string $label;

  /**
   * The toolbar id.
   */
  protected string $toolbar;

  /**
   * The toolbar region.
   */
  protected string $region;

  /**
   * The toolbar item weight.
   *
   * @var int
   */
  protected $weight = 0;

  /**
   * {@inheritdoc}
   */
  public function getPluginCollections() {
    return [
      'visibility' => $this->getVisibilityConditions(),
    ];
  }

  /**
   * Sorts active toolbars by weight; sorts inactive toolbars by name.
   */
  public static function sort(ConfigEntityInterface $a, ConfigEntityInterface $b) {
    // Separate enabled from disabled.
    $status = (int) $b->status() - (int) $a->status();
    if ($status !== 0) {
      return $status;
    }

    // Sort by weight.
    $weight = $a->get('weight') - $b->get('weight');
    if ($weight) {
      return $weight;
    }

    // Sort by label.
    return strcmp($a->label(), $b->label());
  }

}
