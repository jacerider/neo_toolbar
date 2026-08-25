<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar\Attribute;

use Drupal\Component\Plugin\Attribute\AttributeBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * The neo_toolbar_item attribute.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class ToolbarItem extends AttributeBase {

  /**
   * Constructs a new ToolbarItem instance.
   *
   * @param string $id
   *   The plugin ID. There are some implementation bugs that make the plugin
   *   available only if the ID follows a specific pattern. It must be either
   *   identical to group or prefixed with the group. E.g. if the group is "foo"
   *   the ID must be either "foo" or "foo:bar".
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $label
   *   (optional) The human-readable name of the plugin.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|null $description
   *   (optional) A brief description of the plugin.
   * @param bool|null $region_create
   *   (optional) Whether the plugin should create a new region.
   * @param \Drupal\Core\Plugin\Context\ContextDefinitionInterface[] $context_definitions
   *   (optional) An array of context definitions describing the context used by
   *   the plugin. The array is keyed by context names.
   * @param class-string|null $deriver
   *   (optional) The deriver class.
   * @param string|null $provider
   *   (optional) The module that provides this plugin.
   * @param string|null $icon
   *   (optional) The icon the plugin shows on the toolbar. A plugin whose icon
   *   is fixed declares it here and inherits
   *   \Drupal\neo_toolbar\ToolbarItemPluginBase::getIcon(); a plugin whose icon
   *   comes from its own configuration overrides that method instead. This
   *   parameter is deliberately last, after the provider: the attribute is
   *   public API, so inserting it beside the label would shift every parameter
   *   after it and break any positional declaration.
   */
  public function __construct(
    public readonly string $id,
    public readonly ?TranslatableMarkup $label,
    public readonly ?TranslatableMarkup $description = NULL,
    public readonly ?bool $region_create = FALSE,
    public readonly array $context_definitions = [],
    public readonly ?string $deriver = NULL,
    public ?string $provider = NULL,
    public readonly ?string $icon = NULL,
  ) {}

}
