<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar_test\Plugin\ToolbarItem;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_toolbar\Attribute\ToolbarItem;
use Drupal\neo_toolbar\ToolbarItemPluginBase;

/**
 * A toolbar item whose view access is declared by the item, not the plugin.
 *
 * The `access` setting on each item entity chooses the answer — `allowed`,
 * `forbidden` or `neutral` — so one plugin serves every permutation the item
 * pipeline's four rules need, and two items of the same plugin stay
 * distinguishable. A state-driven or globally-switched plugin could not tell
 * them apart, which is exactly what the region-collapse and triggering-item
 * rules require.
 *
 * `neutral` is not the same as `forbidden`: a neutral plugin answer lets
 * `ToolbarItemAccessControlHandler` fall through to the visibility pass, which
 * allows. It is here so a test can say which of the two it means.
 *
 * The answer carries a cache tag naming the item, so a test can prove that a
 * given item's access cacheability — and not merely some item's — reached the
 * metadata the caller passed to `Toolbar::getItems()`.
 */
#[ToolbarItem(
  id: 'neo_toolbar_test_access',
  label: new TranslatableMarkup('Test item'),
  description: new TranslatableMarkup('A toolbar item whose view access answer is read from its own settings.'),
)]
class TestAccess extends ToolbarItemPluginBase {

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'access' => 'allowed',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getIcon(): string|null {
    return 'circle';
  }

  /**
   * Returns the id of the item this plugin was configured for.
   *
   * @return string
   *   The item id, or the plugin id when the plugin was built outside an item.
   */
  public function getItemId(): string {
    return (string) ($this->configuration['id'] ?? $this->getPluginId());
  }

  /**
   * {@inheritdoc}
   */
  protected function itemAccess(AccountInterface $account) {
    $access = match ($this->configuration['access']) {
      'forbidden' => AccessResult::forbidden(),
      'neutral' => AccessResult::neutral(),
      default => AccessResult::allowed(),
    };
    return $access->addCacheTags(['neo_toolbar_test:' . $this->getItemId()]);
  }

}
