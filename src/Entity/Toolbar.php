<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Config\Entity\ConfigEntityInterface;
use Drupal\neo\VisibilityEntityTrait;
use Drupal\neo_toolbar\ToolbarInterface;

/**
 * Defines the toolbar entity type.
 *
 * @ConfigEntityType(
 *   id = "neo_toolbar",
 *   label = @Translation("Toolbar"),
 *   label_collection = @Translation("Toolbars"),
 *   label_singular = @Translation("toolbar"),
 *   label_plural = @Translation("toolbars"),
 *   label_count = @PluralTranslation(
 *     singular = "@count toolbar",
 *     plural = "@count toolbars",
 *   ),
 *   handlers = {
 *     "access" = "Drupal\neo_toolbar\ToolbarAccessControlHandler",
 *     "list_builder" = "Drupal\neo_toolbar\ToolbarListBuilder",
 *     "form" = {
 *       "add" = "Drupal\neo_toolbar\Form\ToolbarForm",
 *       "edit" = "Drupal\neo_toolbar\Form\ToolbarForm",
 *       "delete" = "Drupal\Core\Entity\EntityDeleteForm",
 *     },
 *   },
 *   config_prefix = "neo_toolbar",
 *   admin_permission = "administer neo_toolbar",
 *   links = {
 *     "collection" = "/admin/config/neo/toolbar",
 *     "add-form" = "/admin/config/neo/toolbar/add",
 *     "edit-form" = "/admin/config/neo/toolbar/{neo_toolbar}",
 *     "delete-form" = "/admin/config/neo/toolbar/{neo_toolbar}/delete",
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *     "weight" = "weight"
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "weight",
 *     "visibility",
 *   },
 * )
 */
final class Toolbar extends ConfigEntityBase implements ToolbarInterface {
  use VisibilityEntityTrait;

  /**
   * The toolbar id.
   */
  protected string $id;

  /**
   * The toolbar label.
   */
  protected string $label;

  /**
   * The toolbar weight.
   *
   * @var int
   */
  protected $weight = 0;

  /**
   * Edit mode flag.
   *
   * @var bool
   */
  protected $isEditMode = FALSE;

  /**
   * {@inheritdoc}
   */
  public function setEditMode(bool $isEditMode = TRUE):self {
    $this->isEditMode = $isEditMode;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function isEditMode():bool {
    return $this->isEditMode;
  }

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
