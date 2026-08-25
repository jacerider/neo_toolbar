<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_toolbar\Kernel;

use Drupal\block\BlockInterface;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_toolbar\Entity\Toolbar;
use Drupal\neo_toolbar\Entity\ToolbarItem;
use Drupal\neo_toolbar\Hook\NeoToolbarHooks;
use Drupal\neo_toolbar\ToolbarInterface;
use Drupal\neo_toolbar\ToolbarItemInterface;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * The module's three behavioural hooks, driven through the hook system.
 *
 * Page top, the local tasks alter and block access are methods on
 * `Drupal\neo_toolbar\Hook\NeoToolbarHooks`, with the toolbar repository and
 * the toolbar access gate injected. Nothing any of them decides moved with
 * them, so what is at risk is not a body but a registration: a wrong hook
 * attribute, a wrong hook name or a class in a namespace nothing scans
 * produces a class that reads correctly and is never called. A method nobody
 * invokes is not a hook implementation.
 *
 * So every assertion below goes through the module handler rather than through
 * the object. Each test invokes the hook the way core invokes it — page top
 * with `invokeAllWith()`, exactly as `HtmlRenderer::buildPageTopAndBottom()`
 * does, the alter through `alter()` and block access through `invokeAll()` —
 * and each also names the implementation the hook system actually resolved,
 * because "the hook still answers" and "the class is what answers it" are two
 * different statements and only the second one fails when a hook silently
 * stays procedural.
 *
 * Core's `toolbar` is enabled, for two reasons that pull the same way. It is
 * the other module implementing `hook_page_top()` here, so the ordering
 * criterion has something to be last among; and its implementation is the one
 * this module's page top removes from the page before adding its own, which is
 * the whole reason the ordering exists. That removal is only observable when
 * this module runs last, so the ordering criterion asserts the registered
 * order and the effect of it together.
 *
 * The toolbar and its items are built here rather than installed from
 * `neo_toolbar`'s `config/install`, for the reason the rest of this suite
 * builds its own: one shipped default carries a plugin from `neo_favicon`, a
 * package this module does not depend on. The items themselves use the real
 * `local_tasks` and `local_actions` plugins, because the two block criteria
 * are about those two plugin ids specifically.
 */
#[Group('neo_toolbar')]
final class BehaviouralHooksTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * `breakpoint` is here only because core's `toolbar` names it as a
   * dependency; nothing on this path reads a breakpoint. `neo_toolbar_test`
   * provides the two fixture regions the items are placed in.
   */
  protected static $modules = [
    'system',
    'user',
    'breakpoint',
    'toolbar',
    'neo_toolbar',
    'neo_toolbar_test',
  ];

  /**
   * The next account id to hand out.
   *
   * Every account is given its id explicitly, because the first account saved
   * into an empty `users` table would otherwise become user 1 and be handed
   * every permission on the site by the super user access policy.
   *
   * @var int
   */
  protected int $nextUid = 2;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['user']);
    // With uid 1 taken, no fixture account can inherit the super user's
    // permissions by being the first row in the table.
    User::create([
      'uid' => 1,
      'name' => 'user_one',
      'status' => 1,
    ])->save();
    $this->createToolbar('main');
  }

  /**
   * The page-top hook builds the toolbar element for an allowed account.
   *
   * Covers: it puts a toolbar element at page top for an account the toolbar
   * access gate allows.
   */
  public function testPutsToolbarElementAtPageTopForAnAllowedAccount(): void {
    $this->signIn($this->createAccount('viewer', ['access neo_toolbar']));
    $this->assertTrue($this->container->get('neo_toolbar.access_gate')->hasAccess());

    $pageTop = $this->invokePageTop();

    $this->assertArrayHasKey('neo_toolbar', $pageTop);
    $this->assertSame('neo_toolbar', $pageTop['neo_toolbar']['#type']);
    $this->assertInstanceOf(ToolbarInterface::class, $pageTop['neo_toolbar']['#toolbar']);
    $this->assertSame('main', $pageTop['neo_toolbar']['#toolbar']->id());
    $this->assertSame(['neo_toolbar'], $pageTop['neo_toolbar']['#cache']['keys']);

    // The element came from the hook class, not from a function the collector
    // is still reading out of the `.module` file.
    $this->assertContains(
      'neo_toolbar: ' . NeoToolbarHooks::class . '::pageTop',
      $this->hookImplementations('page_top')
    );
  }

  /**
   * A denied account gets no element at all, not an empty one.
   *
   * Covers: it puts nothing at page top for an account the gate denies.
   */
  public function testPutsNothingAtPageTopForDeniedAccount(): void {
    $this->signIn($this->createAccount('nobody', []));
    $this->assertFalse($this->container->get('neo_toolbar.access_gate')->hasAccess());

    $pageTop = $this->invokePageTop();

    $this->assertArrayNotHasKey('neo_toolbar', $pageTop);
    // The gate is the only thing that stopped it: there is an active toolbar
    // waiting, and the same call with an allowed account builds an element.
    $this->assertNotNull($this->container->get('neo_toolbar.repository')->getActive());

    $this->assertContains(
      'neo_toolbar: ' . NeoToolbarHooks::class . '::pageTop',
      $this->hookImplementations('page_top')
    );
  }

  /**
   * The ordering is what lets the module unset core's toolbar.
   *
   * Covers: it runs its page-top implementation last among the modules
   * implementing that hook.
   */
  public function testRunsItsPageTopImplementationLast(): void {
    $this->signIn($this->createAccount('viewer', ['access neo_toolbar']));

    $modules = $this->hookModules('page_top');
    // Something to be last among: core's toolbar implements this hook too, and
    // sorts before `neo_toolbar` in module order, so being last is a decision
    // rather than an accident of the module list.
    $this->assertContains('toolbar', $modules);
    $this->assertSame('neo_toolbar', end($modules));

    $implementations = $this->hookImplementations('page_top');
    $this->assertSame(
      'neo_toolbar: ' . NeoToolbarHooks::class . '::pageTop',
      end($implementations)
    );

    // What the ordering buys, seen on the page: core's toolbar puts its own
    // element in, and this module's implementation — running afterwards —
    // takes it back out before adding its own.
    $pageTop = $this->invokePageTop();
    $this->assertArrayNotHasKey('toolbar', $pageTop);
    $this->assertArrayHasKey('neo_toolbar', $pageTop);
  }

  /**
   * The local actions block gives way to the toolbar's own item.
   *
   * Covers: it forbids the local actions block when the active toolbar carries
   * a local actions item.
   */
  public function testForbidsTheLocalActionsBlockWhenTheToolbarCarriesLocalActionsItem(): void {
    $this->createItem('actions', 'local_actions');
    $this->signIn($this->createAccount('viewer', ['access neo_toolbar']));

    $access = $this->invokeBlockAccess('local_actions_block');

    $this->assertInstanceOf(AccessResultInterface::class, $access);
    $this->assertTrue($access->isForbidden());
    $this->assertContains('user.permissions', $access->getCacheContexts());

    $this->assertContains(
      'neo_toolbar: ' . NeoToolbarHooks::class . '::blockAccess',
      $this->hookImplementations('block_access')
    );
  }

  /**
   * The local tasks block gives way to the toolbar's own item.
   *
   * Covers: it forbids the local tasks block when the active toolbar carries a
   * local tasks item.
   */
  public function testForbidsTheLocalTasksBlockWhenTheToolbarCarriesLocalTasksItem(): void {
    $this->createItem('tasks', 'local_tasks');
    $this->signIn($this->createAccount('viewer', ['access neo_toolbar']));

    $access = $this->invokeBlockAccess('local_tasks_block');

    $this->assertInstanceOf(AccessResultInterface::class, $access);
    $this->assertTrue($access->isForbidden());
    $this->assertContains('user.permissions', $access->getCacheContexts());

    $this->assertContains(
      'neo_toolbar: ' . NeoToolbarHooks::class . '::blockAccess',
      $this->hookImplementations('block_access')
    );
  }

  /**
   * Everything the hook does not claim, it leaves alone.
   *
   * Covers: it answers neutrally for a block of any other plugin, and for
   * every block when the gate denies.
   */
  public function testAnswersNeutrallyForOtherPluginsAndForEveryBlockWhenTheGateDenies(): void {
    $this->createItem('actions', 'local_actions');
    $this->createItem('tasks', 'local_tasks');

    // An allowed account, so the only reason to answer neutrally is the plugin
    // id: the two blocks it does claim are forbidden for this same account.
    $this->signIn($this->createAccount('viewer', ['access neo_toolbar']));
    $this->assertNull($this->invokeBlockAccess('system_branding_block'));
    $this->assertNull($this->invokeBlockAccess('local_tasks_block_not_really'));
    $this->assertTrue($this->invokeBlockAccess('local_tasks_block')->isForbidden());

    // A denied account: the gate is the first thing the hook asks, so every
    // block is answered neutrally no matter what the toolbar carries.
    $this->signIn($this->createAccount('nobody', []));
    $this->assertNull($this->invokeBlockAccess('local_tasks_block'));
    $this->assertNull($this->invokeBlockAccess('local_actions_block'));
    $this->assertNull($this->invokeBlockAccess('system_branding_block'));

    $this->assertContains(
      'neo_toolbar: ' . NeoToolbarHooks::class . '::blockAccess',
      $this->hookImplementations('block_access')
    );
  }

  /**
   * The media collection tab is given its own base route.
   *
   * Covers: it re-parents the media collection local task onto its own base
   * route.
   */
  public function testReParentsTheMediaCollectionLocalTask(): void {
    $definitions = [
      'entity.media.collection' => ['base_route' => 'system.admin_content'],
      'entity.node.canonical' => ['base_route' => 'entity.node.canonical'],
    ];

    $this->container->get('module_handler')->alter('local_tasks', $definitions);

    $this->assertSame('entity.media.collection', $definitions['entity.media.collection']['base_route']);
    // Nothing else is touched, and a definition set without the media task
    // present is left exactly as it arrived.
    $this->assertSame('entity.node.canonical', $definitions['entity.node.canonical']['base_route']);
    $untouched = ['entity.node.canonical' => ['base_route' => 'entity.node.canonical']];
    $this->container->get('module_handler')->alter('local_tasks', $untouched);
    $this->assertSame(['entity.node.canonical' => ['base_route' => 'entity.node.canonical']], $untouched);

    $this->assertContains(
      'neo_toolbar: ' . NeoToolbarHooks::class . '::localTasksAlter',
      $this->hookImplementations('local_tasks_alter')
    );
  }

  /**
   * Invokes hook_page_top() the way core's HTML renderer invokes it.
   *
   * @return array
   *   The page top render array every implementation has had its turn at.
   *
   * @see \Drupal\Core\Render\MainContent\HtmlRenderer::buildPageTopAndBottom()
   */
  protected function invokePageTop(): array {
    $pageTop = [];
    $this->container->get('module_handler')->invokeAllWith(
      'page_top',
      static function (callable $implementation, string $module) use (&$pageTop): void {
        $implementation($pageTop);
      }
    );
    return $pageTop;
  }

  /**
   * Invokes hook_block_access() for a block of one plugin id.
   *
   * The block is a double rather than a real entity because the hook reads one
   * thing off it, and installing `block` to save a config entity would put a
   * module on this test that nothing else here needs.
   *
   * @param string $pluginId
   *   The block plugin id.
   *
   * @return \Drupal\Core\Access\AccessResultInterface|null
   *   The single answer this module gave, or NULL where it abstained.
   */
  protected function invokeBlockAccess(string $pluginId): ?AccessResultInterface {
    $block = $this->createMock(BlockInterface::class);
    $block->method('getPluginId')->willReturn($pluginId);
    $results = $this->container->get('module_handler')->invokeAll('block_access', [
      $block,
      'view',
      \Drupal::currentUser()->getAccount(),
    ]);
    // `invokeAll()` drops a NULL answer, so an abstention is an empty list.
    $this->assertLessThanOrEqual(1, count($results));
    return $results[0] ?? NULL;
  }

  /**
   * The implementations the hook system resolved for a hook, in order.
   *
   * @param string $hook
   *   The hook name, without the `hook_` prefix.
   *
   * @return string[]
   *   One `module: identifier` string per implementation, where the identifier
   *   is `Class::method` for a class-based implementation and the function
   *   name for a procedural one.
   */
  protected function hookImplementations(string $hook): array {
    $implementations = [];
    $this->container->get('module_handler')->invokeAllWith(
      $hook,
      static function (callable $implementation, string $module) use (&$implementations): void {
        if (is_array($implementation)) {
          $identifier = get_class($implementation[0]) . '::' . $implementation[1];
        }
        elseif (is_string($implementation)) {
          $identifier = $implementation;
        }
        else {
          $identifier = get_debug_type($implementation);
        }
        $implementations[] = $module . ': ' . $identifier;
      }
    );
    return $implementations;
  }

  /**
   * The modules implementing a hook, in the order the hook system runs them.
   *
   * @param string $hook
   *   The hook name, without the `hook_` prefix.
   *
   * @return string[]
   *   The module names, which may repeat where one module implements a hook
   *   more than once.
   */
  protected function hookModules(string $hook): array {
    $modules = [];
    $this->container->get('module_handler')->invokeAllWith(
      $hook,
      static function (callable $implementation, string $module) use (&$modules): void {
        $modules[] = $module;
      }
    );
    return $modules;
  }

  /**
   * Creates a toolbar.
   *
   * @param string $id
   *   The toolbar id, which is also its label.
   * @param array $values
   *   Entity values overriding the defaults.
   *
   * @return \Drupal\neo_toolbar\ToolbarInterface
   *   The saved toolbar.
   */
  protected function createToolbar(string $id, array $values = []): ToolbarInterface {
    $toolbar = Toolbar::create($values + [
      'id' => $id,
      'label' => $id,
      'weight' => 0,
    ]);
    $toolbar->save();
    return $toolbar;
  }

  /**
   * Creates a toolbar item running one of the module's real plugins.
   *
   * @param string $id
   *   The item id, which is also its label.
   * @param string $plugin
   *   The toolbar item plugin id.
   * @param array $values
   *   Entity values overriding the defaults.
   *
   * @return \Drupal\neo_toolbar\ToolbarItemInterface
   *   The saved item.
   */
  protected function createItem(string $id, string $plugin, array $values = []): ToolbarItemInterface {
    $item = ToolbarItem::create($values + [
      'id' => $id,
      'label' => $id,
      'toolbar' => 'main',
      'region' => 'test_horizontal',
      'plugin' => $plugin,
      'weight' => 0,
    ]);
    $item->save();
    return $item;
  }

  /**
   * Creates an account holding exactly the given permissions.
   *
   * @param string $name
   *   The account name, which also names the role created for it.
   * @param string[] $permissions
   *   The permissions the account's role grants.
   *
   * @return \Drupal\user\UserInterface
   *   The saved account, reloaded.
   */
  protected function createAccount(string $name, array $permissions): UserInterface {
    Role::create([
      'id' => $name,
      'label' => $name,
      'permissions' => $permissions,
    ])->save();
    $uid = $this->nextUid++;
    User::create([
      'uid' => $uid,
      'name' => $name,
      'status' => 1,
      'roles' => [$name],
    ])->save();
    return User::load($uid);
  }

  /**
   * Makes an account the current user.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The account.
   */
  protected function signIn(AccountInterface $account): void {
    \Drupal::currentUser()->setAccount($account);
  }

}
