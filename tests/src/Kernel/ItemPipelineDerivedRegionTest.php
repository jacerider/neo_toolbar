<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_toolbar\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_toolbar\Entity\Toolbar;
use Drupal\neo_toolbar\Entity\ToolbarItem;
use Drupal\neo_toolbar\ToolbarItemInterface;
use Drupal\neo_toolbar\ToolbarRepository;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;

/**
 * Covers the one thing both region rules have to agree about.
 *
 * A derived region is `item:` plus the machine name of the item that opens it,
 * and two of the four pipeline rules go looking for one: region collapse drops
 * an opener whose region emptied, and the restore brings back an opener whose
 * region did not. They used to disagree about what a derived region is, and
 * they disagreed in both directions:
 *
 * - Collapse stripped `item:` from every region id it saw, prefix or no
 *   prefix, and treated the remainder as an item id. A real region called
 *   `test_vertical` therefore looked up an item called `test_vertical`, and a
 *   toolbar item that happened to share a region's machine name was dropped
 *   whenever that region emptied.
 * - The restore tested `item:region` — five characters longer — so it fired
 *   only when the opener's own machine name began with `region`. That is true
 *   of items built on the `region` plugin and false of items built on `user`,
 *   the only other plugin in the module that declares `region_create`. A user
 *   dropdown's links were never protected by it on any site.
 *
 * Both rules now test the `item:` prefix and both take the opener's id from
 * what follows it, so the two questions "did this region empty?" and "does
 * this region still have children?" are asked about the same set of regions.
 *
 * The `user` plugin is used as itself rather than through a fixture twin,
 * because the criterion is about that plugin: its items are the ones the old
 * restore rule could not see.
 */
#[Group('neo_toolbar')]
final class ItemPipelineDerivedRegionTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * The floor the two rules need. `user` is here for its entity type as much
   * as its module: the `user` toolbar item plugin loads the current account in
   * its constructor, so the schema and the anonymous account have to exist
   * before an item using it can be saved.
   */
  protected static $modules = [
    'system',
    'user',
    'neo_toolbar',
    'neo_toolbar_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    // What `user_install()` creates and a kernel test does not: uid 0. The
    // `user` item plugin loads `$current_user->id()` in its constructor and
    // types the result `UserInterface`, so without this row no item using that
    // plugin can be instantiated at all.
    User::create([
      'uid' => 0,
      'name' => '',
      'status' => 0,
    ])->save();
    Toolbar::create([
      'id' => 'test_toolbar',
      'label' => 'Test toolbar',
      'weight' => 0,
    ])->save();
  }

  /**
   * The restore rule fires for a dropdown a `user` item opens.
   *
   * Covers: the restore rule fires for a derived region whose triggering item
   * is a user item.
   */
  public function testRestoreFiresForDerivedRegionOpenedByUserItem(): void {
    $opener = $this->createUserItem('user');
    $child = $this->createItem('user_child', ['region' => 'item:user']);

    $allItems = ['user' => $opener, 'user_child' => $child];

    // The `user` item is forbidden to anonymous by its own plugin, so this is
    // the arrangement every site with a user dropdown produces the moment the
    // account menu is not available: the opener gone, its links still there
    // with no other way in. The rule appends the opener rather than strand
    // them.
    $restored = $this->repository()->restoreTriggeringItems(['user_child' => $child], $allItems);
    $this->assertSame(['user_child', 'user'], array_keys($restored));
    $this->assertSame($opener, $restored['user']);
  }

  /**
   * A real region is not a derived region, whatever its name looks like.
   *
   * Covers: the restore rule ignores a region id that carries no
   * derived-region prefix.
   */
  public function testRestoreIgnoresRegionIdWithNoDerivedRegionPrefix(): void {
    // `substr('test_vertical', 5)` is `vertical`, which is what the rule would
    // name as the opener of this region if it read a region id without first
    // establishing that the id is derived at all.
    $namesake = $this->createItem('vertical');
    $resident = $this->createItem('resident', ['region' => 'test_vertical']);

    $allItems = ['vertical' => $namesake, 'resident' => $resident];

    // `test_vertical` is a real region with a real item in it. Nothing about
    // it derives, so there is no opener to bring back.
    $restored = $this->repository()->restoreTriggeringItems(['resident' => $resident], $allItems);
    $this->assertSame(['resident'], array_keys($restored));

    // The guard is the prefix a derived region actually carries, and nothing
    // narrower. `item:region` matched a derived region only when the opener's
    // own machine name began with `region`, which is a property of the item
    // rather than of the region, and is why the rule never fired for a `user`
    // item. Asserted in the rule's own source because the two prefixes agree
    // about every region id that is not derived, so no answer can tell them
    // apart here — only the answer above and
    // \Drupal\Tests\neo_toolbar\Kernel\ItemPipelineDerivedRegionTest::testRestoreFiresForDerivedRegionOpenedByUserItem
    // together say which prefix is in force.
    $this->assertStringNotContainsString('item:region', $this->methodSource('restoreTriggeringItems'));
  }

  /**
   * An item that shares a region's machine name is not that region's opener.
   *
   * Covers: the collapse rule leaves a real region alone when a toolbar item
   * happens to share its machine name.
   */
  public function testCollapseLeavesRealRegionAloneWhenItemSharesItsMachineName(): void {
    // Nothing stops a site builder naming an item after a region: item ids and
    // region ids are different namespaces and neither validates against the
    // other.
    $namesake = $this->createItem('test_vertical');
    $resident = $this->createItem('resident', ['region' => 'test_vertical']);

    $allItems = ['test_vertical' => $namesake, 'resident' => $resident];

    // The real region `test_vertical` has emptied, and that says nothing at
    // all about the item called `test_vertical` sitting in another region: a
    // region only has an opener when an item derived it.
    $collapsed = $this->repository()->collapseEmptyRegions(['test_vertical' => $namesake], $allItems);
    $this->assertSame(['test_vertical'], array_keys($collapsed));
  }

  /**
   * The two rules name the same opener for the same region id.
   *
   * Covers: both rules extract a triggering item id from a derived region id
   * the same way.
   */
  public function testBothRulesExtractTheSameTriggeringItemIdFromDerivedRegionId(): void {
    $opener = $this->createUserItem('user');
    $child = $this->createItem('user_child', ['region' => 'item:user']);
    $namesake = $this->createItem('test_vertical');
    $resident = $this->createItem('resident', ['region' => 'test_vertical']);

    $allItems = [
      'user' => $opener,
      'user_child' => $child,
      'test_vertical' => $namesake,
      'resident' => $resident,
    ];

    $repository = $this->repository();

    // Two region ids, one derived and one real. To the collapse rule
    // `item:user` names `user`, so an emptied `item:user` takes the opener
    // with it; `test_vertical` names nobody, so an emptied `test_vertical`
    // takes nothing.
    $collapsed = $repository->collapseEmptyRegions([
      'user' => $opener,
      'test_vertical' => $namesake,
    ], $allItems);
    $this->assertSame(['test_vertical'], array_keys($collapsed));

    // The same two ids reach the restore rule and name the same two things:
    // `user` for `item:user`, nobody for `test_vertical`.
    $restored = $repository->restoreTriggeringItems([
      'user_child' => $child,
      'resident' => $resident,
    ], $allItems);
    $this->assertSame(['user_child', 'resident', 'user'], array_keys($restored));
  }

  /**
   * Reads a repository method's own source.
   *
   * @param string $method
   *   The method name.
   *
   * @return string
   *   The method's source, including its signature and excluding its docblock.
   */
  protected function methodSource(string $method): string {
    $reflection = new \ReflectionMethod(ToolbarRepository::class, $method);
    $file = $reflection->getFileName();
    $this->assertIsString($file);
    $lines = file($file);
    $this->assertIsArray($lines);
    return implode('', array_slice(
      $lines,
      $reflection->getStartLine() - 1,
      $reflection->getEndLine() - $reflection->getStartLine() + 1
    ));
  }

  /**
   * Creates a toolbar item on the test toolbar.
   *
   * @param string $id
   *   The item id, which is also the array key every rule answers under.
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
      'toolbar' => 'test_toolbar',
      'region' => 'test_horizontal',
      'plugin' => 'neo_toolbar_test_access',
      'weight' => 0,
      'settings' => ['access' => $access],
    ]);
    $item->save();
    return $item;
  }

  /**
   * Creates a real `user` toolbar item, which derives a region of its own.
   *
   * The `user` plugin is one of exactly two in the module that declare
   * `region_create`, and the one the old restore rule could never see. It is
   * used here rather than a fixture twin because "a user item" is the
   * criterion.
   *
   * @param string $id
   *   The item id.
   * @param array $values
   *   Entity values overriding the defaults.
   *
   * @return \Drupal\neo_toolbar\ToolbarItemInterface
   *   The saved item.
   */
  protected function createUserItem(string $id, array $values = []): ToolbarItemInterface {
    return $this->createItem($id, $values + [
      'plugin' => 'user',
      'settings' => [],
    ]);
  }

  /**
   * The repository service, which holds no state the rules read.
   *
   * @return \Drupal\neo_toolbar\ToolbarRepository
   *   The repository.
   */
  protected function repository(): ToolbarRepository {
    $repository = $this->container->get('neo_toolbar.repository');
    $this->assertInstanceOf(ToolbarRepository::class, $repository);
    return $repository;
  }

}
