<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar\Hook;

use Drupal\block\BlockInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Hook\Order\Order;
use Drupal\Core\Session\AccountInterface;
use Drupal\neo_toolbar\ToolbarAccessGate;
use Drupal\neo_toolbar\ToolbarRepository;

/**
 * The module's behavioural hook implementations.
 *
 * Page top, the local tasks alter and block access, split from the theme hooks
 * the way core splits its own dozen-odd `…Hooks` / `…ThemeHooks` pairs: these
 * three need collaborators and the theme hooks need none.
 *
 * Every body below is what stood in `neo_toolbar.module`, with one
 * substitution — the three `\Drupal::service()` calls became the two
 * constructor arguments. Nothing about what any of them decides moved with
 * them: not which blocks are hidden, not which local task is re-parented, not
 * what the page-top element carries.
 *
 * This is not an API and it is not `final`. The methods are public because
 * core's hook collector only reads public methods, and nothing but the hook
 * system calls them.
 */
class NeoToolbarHooks {

  /**
   * Constructs a NeoToolbarHooks object.
   *
   * @param \Drupal\neo_toolbar\ToolbarRepository $toolbarRepository
   *   The toolbar repository, which answers which toolbar a request renders
   *   and whether it carries items of a given plugin type.
   * @param \Drupal\neo_toolbar\ToolbarAccessGate $accessGate
   *   The toolbar access gate, the module's single access answer.
   */
  public function __construct(
    protected readonly ToolbarRepository $toolbarRepository,
    protected readonly ToolbarAccessGate $accessGate,
  ) {}

  /**
   * Implements hook_page_top().
   *
   * Add toolbar to the top of the page.
   *
   * This implementation runs last so that it can remove core's toolbar from
   * the page before adding its own. That instruction used to be
   * `neo_toolbar_module_implements_alter()`, which is gone: core's hook
   * collector keeps `hook_module_implements_alter()` on a static deny list of
   * hooks that must stay procedural and throws at container build if one
   * appears on a class, so moving it was never available, and a procedural
   * implementation of it without core's legacy attribute is deprecated as of
   * Drupal 11.2 and removed in Drupal 12. The ordering attribute states the
   * same thing in the place it applies to, and core applies ordering
   * attributes after every module's implements-alter has run, so it is at
   * least as decisive as what it replaces.
   */
  #[Hook('page_top', order: Order::Last)]
  public function pageTop(array &$page_top): void {
    if (!$this->accessGate->hasAccess()) {
      return;
    }

    if ($toolbar = $this->toolbarRepository->getActive()) {
      // Remove the core toolbar if it exists.
      unset($page_top['toolbar']);

      $page_top['neo_toolbar'] = [
        '#type' => 'neo_toolbar',
        '#toolbar' => $toolbar,
        '#cache' => [
          'keys' => ['neo_toolbar'],
          'contexts' => $toolbar->getCacheContexts(),
          'tags' => $toolbar->getCacheTags(),
        ],
      ];
    }
  }

  /**
   * Implements hook_local_tasks_alter().
   */
  #[Hook('local_tasks_alter')]
  public function localTasksAlter(&$definitions): void {
    if (isset($definitions['entity.media.collection'])) {
      // Move media collection to its own tab.
      $definitions['entity.media.collection']['base_route'] = 'entity.media.collection';
    }
  }

  /**
   * Implements hook_block_access().
   */
  #[Hook('block_access')]
  public function blockAccess(BlockInterface $block, string $operation, AccountInterface $account): ?AccessResultInterface {
    if (!$this->accessGate->hasAccess()) {
      return NULL;
    }
    switch ($block->getPluginId()) {
      case 'local_actions_block':
        // Hide local tasks block if user can use the toolbar AND the local
        // tasks plugin is enabled.
        if ($this->toolbarRepository->hasToolbarItemsOfType('local_actions')) {
          return AccessResult::forbidden()->cachePerPermissions();
        }
        break;

      case 'local_tasks_block':
        // Hide local actions block if the user can use the toolbar AND the
        // local tasks plugin is enabled.
        if ($this->toolbarRepository->hasToolbarItemsOfType('local_tasks')) {
          return AccessResult::forbidden()->cachePerPermissions();
        }
        break;
    }
    return NULL;
  }

}
