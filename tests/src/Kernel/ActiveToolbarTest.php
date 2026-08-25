<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_toolbar\Kernel;

use Drupal\Core\Routing\RouteMatch;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_toolbar\Entity\Toolbar;
use Drupal\neo_toolbar\Entity\ToolbarItem;
use Drupal\neo_toolbar\ToolbarInterface;
use Drupal\neo_toolbar\ToolbarItemInterface;
use Drupal\neo_toolbar\ToolbarRepository;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Routing\Route;

/**
 * Characterises the active toolbar resolution.
 *
 * `ToolbarRepository` answers which toolbar a request renders and which of its
 * items are of a given plugin type. It has two branches: a route carrying a
 * `neo_toolbar` parameter wins outright and puts that toolbar into edit mode,
 * and otherwise the enabled toolbars are filtered by view access, sorted with
 * `Toolbar::sort()` and the first one wins.
 *
 * One behaviour is pinned as current rather than defended: `getActive(FALSE)`
 * cannot return a toolbar, because the assignment that sets it sits inside the
 * `if ($checkAccess)` block along with the filter. The one parameter whose
 * whole purpose is to skip the access check is the one thing it cannot do.
 * Nothing passes `FALSE` anywhere today, which is why it is latent rather than
 * live; the test is what makes the eventual three-line fix visible.
 *
 * The toolbars and items are built here rather than installed from
 * `neo_toolbar`'s `config/install`, for the same reason the item pipeline
 * tests build theirs: one shipped default carries a plugin from `neo_favicon`,
 * a package this module does not depend on.
 *
 * The repository is constructed directly rather than taken from the container,
 * because its memo is per-object and half these criteria are about what a
 * second call does. The route-free half passes the container's real
 * `current_route_match`, which in a kernel test is a `NullRouteMatch` and is
 * therefore the honest "no toolbar parameter" input.
 */
#[Group('neo_toolbar')]
final class ActiveToolbarTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * `KernelTestBase` installs exactly what is named here and does not resolve
   * the info file's dependency closure, so `token`, `neo`, `neo_modal`,
   * `neo_tooltip` and `neo_image` stay out: their classes autoload, and only a
   * service lookup would force one in. Nothing on this path performs one.
   */
  protected static $modules = [
    'system',
    'user',
    'neo_toolbar',
    'neo_toolbar_test',
  ];

  /**
   * The route's toolbar wins outright and is put into edit mode.
   *
   * Covers: it answers the route's toolbar parameter and puts that toolbar in
   * edit mode.
   */
  public function testRouteParameterWinsAndEntersEditMode(): void {
    // What the other branch would answer: enabled, lightest, and viewable.
    $this->createToolbar('would_win', ['weight' => -10]);
    // What the route carries: heaviest, and disabled, so the other branch
    // could never reach it — its query conditions on status.
    $onTheRoute = $this->createToolbar('on_the_route', [
      'weight' => 100,
      'status' => FALSE,
    ]);
    $this->assertFalse($onTheRoute->isEditMode());

    $active = $this->repository($onTheRoute)->getActive();

    $this->assertInstanceOf(ToolbarInterface::class, $active);
    $this->assertSame('on_the_route', $active->id());
    // The parameter object itself comes back, not a reload of it, which is why
    // the edit mode the branch sets is visible on the caller's own entity.
    $this->assertSame($onTheRoute, $active);
    $this->assertTrue($active->isEditMode());
    $this->assertTrue($onTheRoute->isEditMode());
  }

  /**
   * The first viewable enabled toolbar in sort order wins.
   *
   * Covers: it answers the first enabled toolbar the account may view, in sort
   * order.
   */
  public function testAnswersFirstViewableToolbarInSortOrder(): void {
    // Created out of weight order and out of alphabetical order, so neither
    // creation order nor id ordering can produce a passing result by accident.
    $alphaLast = $this->createToolbar('alpha_last', ['weight' => 10]);
    $mid = $this->createToolbar('mid', ['weight' => 0]);
    $zetaFirst = $this->createToolbar('zeta_first', ['weight' => -10]);
    // Lighter than every enabled one, and disabled: the query drops it before
    // the sort is ever reached.
    $this->createToolbar('disabled_lightest', [
      'weight' => -100,
      'status' => FALSE,
    ]);

    $this->assertSame('zeta_first', $this->activeId());

    // Peeling the winner off exposes the next one, which is what makes this an
    // assertion about the order rather than about a single value.
    $zetaFirst->delete();
    $this->assertSame('mid', $this->activeId());
    $mid->delete();
    $this->assertSame('alpha_last', $this->activeId());

    // With only the disabled one left there is no answer at all.
    $alphaLast->delete();
    $this->assertNull($this->activeId());
  }

  /**
   * A toolbar the account may not view is passed over.
   *
   * Covers: it skips a toolbar whose visibility conditions forbid the account.
   */
  public function testSkipsToolbarWhoseVisibilityForbidsTheAccount(): void {
    $this->setUpAccount();

    // The lighter of the two, so nothing but the access filter can explain the
    // other one winning.
    $restricted = $this->createToolbar('restricted', [
      'weight' => -10,
      'visibility' => $this->userRoleCondition('toolbar_only'),
    ]);
    $open = $this->createToolbar('open', [
      'weight' => 0,
      'visibility' => $this->userRoleCondition('authenticated'),
    ]);

    // Both directions of one rule, on one account in one pass: the role the
    // account holds allows, the role it does not hold forbids.
    $this->assertFalse($restricted->access('view'));
    $this->assertTrue($open->access('view'));

    $this->assertSame('open', $this->activeId());
  }

  /**
   * Skipping the access check answers NULL.
   *
   * Pinned as current behaviour, not defended: `$this->toolbar` is only ever
   * assigned inside `if ($checkAccess)`, so the argument that exists to skip
   * the filter also skips the answer. Nothing in the module or in any sibling
   * package passes `FALSE`, which is why this is latent rather than live.
   *
   * Covers: it answers NULL when asked to skip the access check.
   */
  public function testAnswersNullWhenAskedToSkipTheAccessCheck(): void {
    $this->createToolbar('only', ['weight' => 0]);

    $repository = $this->repository();
    $this->assertNull($repository->getActive(FALSE));

    // Not for want of a toolbar to answer: the very same repository, asked
    // with the access check left on, answers one. NULL is never memoised —
    // `$toolbar` is a nullable typed property and `isset()` is FALSE for a
    // property holding NULL just as it is for an uninitialised one — so the
    // second call recomputes from scratch rather than repeating the NULL.
    $this->assertSame('only', $this->idOf($repository->getActive()));
  }

  /**
   * A second call is answered from the memo.
   *
   * Covers: it answers the same toolbar on a second call without re-querying.
   */
  public function testAnswersTheSameToolbarOnSecondCall(): void {
    $this->createToolbar('first', ['weight' => 0]);

    $repository = $this->repository();
    $active = $repository->getActive();
    $this->assertSame('first', $this->idOf($active));

    // A new enabled toolbar that would win the sort outright is invisible to
    // the memoised repository, which is how the absence of a second query is
    // observed.
    $this->createToolbar('earlier', ['weight' => -50]);
    $this->assertSame($active, $repository->getActive());
    $this->assertSame('first', $this->idOf($repository->getActive()));

    // The memo lives on the repository object, not in a shared cache: a fresh
    // repository queries again and sees the new toolbar.
    $this->assertSame('earlier', $this->activeId());
  }

  /**
   * The items of one plugin type, and whether there are any.
   *
   * The lookups run `Toolbar::getItems()` underneath, so an item the pipeline
   * dropped is absent from them too.
   *
   * Covers: it answers the items of one plugin type, and whether the active
   * toolbar has any.
   */
  public function testAnswersItemsOfOnePluginType(): void {
    $active = $this->createToolbar('active', ['weight' => 0]);
    // Heavier, so it never becomes active; its item must not leak in.
    $other = $this->createToolbar('other', ['weight' => 10]);

    $this->createItem('alpha', ['weight' => 10]);
    $this->createItem('omega', ['weight' => 20], 'forbidden');
    $this->createItem('sigma', [
      'weight' => 30,
      'plugin' => 'neo_toolbar_test_region',
    ]);
    $this->createItem('elsewhere', ['weight' => 10, 'toolbar' => 'other']);

    $repository = $this->repository();

    // Answered in the pipeline's weight order, keyed as a list rather than by
    // id, and with the forbidden item already gone.
    $this->assertSame(
      ['alpha'],
      $this->itemIds($repository->getToolbarItemsOfType('neo_toolbar_test_access'))
    );
    $this->assertSame(
      ['sigma'],
      $this->itemIds($repository->getToolbarItemsOfType('neo_toolbar_test_region'))
    );
    // A real plugin id the active toolbar has no item for.
    $this->assertSame([], $repository->getToolbarItemsOfType('link'));

    $this->assertTrue($repository->hasToolbarItemsOfType('neo_toolbar_test_access'));
    $this->assertTrue($repository->hasToolbarItemsOfType('neo_toolbar_test_region'));
    $this->assertFalse($repository->hasToolbarItemsOfType('link'));

    // With no active toolbar both answer empty rather than failing.
    $active->delete();
    $other->delete();
    $emptyRepository = $this->repository();
    $this->assertNull($emptyRepository->getActive());
    $this->assertSame([], $emptyRepository->getToolbarItemsOfType('neo_toolbar_test_access'));
    $this->assertFalse($emptyRepository->hasToolbarItemsOfType('neo_toolbar_test_access'));
  }

  /**
   * Builds a repository over a chosen route match.
   *
   * @param \Drupal\neo_toolbar\ToolbarInterface|null $routeToolbar
   *   The toolbar the route carries as its `neo_toolbar` parameter, or NULL for
   *   the container's own route match, which in a kernel test carries no route
   *   and therefore no parameter.
   *
   * @return \Drupal\neo_toolbar\ToolbarRepository
   *   A repository with an empty memo.
   */
  protected function repository(?ToolbarInterface $routeToolbar = NULL): ToolbarRepository {
    $routeMatch = $this->container->get('current_route_match');
    if ($routeToolbar) {
      // The shape the entity param converter leaves behind on the toolbar's
      // own edit route: the upcast entity under the parameter name, the raw id
      // beside it.
      $routeMatch = new RouteMatch(
        'entity.neo_toolbar.edit_form',
        new Route('/admin/config/neo/toolbar/{neo_toolbar}'),
        ['neo_toolbar' => $routeToolbar],
        ['neo_toolbar' => $routeToolbar->id()]
      );
    }
    return new ToolbarRepository($this->container->get('entity_type.manager'), $routeMatch);
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
   * Creates a toolbar item.
   *
   * @param string $id
   *   The item id, which is also its label.
   * @param array $values
   *   Entity values overriding the defaults.
   * @param string $access
   *   The answer the fixture plugin gives to a view access check: `allowed`,
   *   `forbidden` or `neutral`.
   *
   * @return \Drupal\neo_toolbar\ToolbarItemInterface
   *   The saved item.
   */
  protected function createItem(string $id, array $values = [], string $access = 'allowed'): ToolbarItemInterface {
    $item = ToolbarItem::create($values + [
      'id' => $id,
      'label' => $id,
      'toolbar' => 'active',
      'region' => 'test_horizontal',
      'plugin' => 'neo_toolbar_test_access',
      'weight' => 0,
      'settings' => ['access' => $access],
    ]);
    $item->save();
    return $item;
  }

  /**
   * Installs the user entity and signs a plain authenticated account in.
   *
   * Only the visibility criterion needs this: `user_role` is the one condition
   * core ships that both answers per account and needs nothing beyond `user`,
   * and it resolves its context through `user.current_user_context`, which
   * loads the current user as an entity.
   */
  protected function setUpAccount(): void {
    $this->installEntitySchema('user');
    $this->installConfig(['user']);
    Role::create([
      'id' => 'toolbar_only',
      'label' => 'Toolbar only',
    ])->save();
    $account = User::create([
      'name' => 'toolbar_viewer',
      'status' => 1,
    ]);
    $account->save();
    // A plain account: `authenticated` and nothing else.
    $this->assertSame(['authenticated'], $account->getRoles());
    \Drupal::currentUser()->setAccount($account);
  }

  /**
   * Builds a visibility array holding one user_role condition.
   *
   * @param string $role
   *   The role the account must hold for the condition to pass.
   *
   * @return array
   *   A `visibility` value for a toolbar entity.
   */
  protected function userRoleCondition(string $role): array {
    return [
      'user_role' => [
        'id' => 'user_role',
        'negate' => FALSE,
        'context_mapping' => [
          'user' => '@user.current_user_context:current_user',
        ],
        'roles' => [$role => $role],
      ],
    ];
  }

  /**
   * Resolves the active toolbar's id through a repository with an empty memo.
   *
   * @return string|null
   *   The id, or NULL when nothing is active.
   */
  protected function activeId(): ?string {
    return $this->idOf($this->repository()->getActive());
  }

  /**
   * Reads a toolbar's id.
   *
   * @param \Drupal\neo_toolbar\ToolbarInterface|null $toolbar
   *   The toolbar, or NULL.
   *
   * @return string|null
   *   The id, or NULL.
   */
  protected function idOf(?ToolbarInterface $toolbar): ?string {
    return $toolbar === NULL ? NULL : (string) $toolbar->id();
  }

  /**
   * Reads the ids off a list of toolbar items.
   *
   * @param \Drupal\neo_toolbar\ToolbarItemInterface[] $items
   *   The items.
   *
   * @return string[]
   *   The ids, in the order given.
   */
  protected function itemIds(array $items): array {
    return array_values(array_map(
      static fn (ToolbarItemInterface $item): string => (string) $item->id(),
      $items
    ));
  }

}
