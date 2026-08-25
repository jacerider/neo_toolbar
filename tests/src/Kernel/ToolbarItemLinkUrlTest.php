<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_toolbar\Kernel;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Url;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_toolbar\ToolbarItemElement;
use Drupal\neo_toolbar_test\TestToolbarItemLink;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;

/**
 * Characterises the link trait's url building against the real router.
 *
 * `ToolbarItemLinkTrait` splits cleanly in two. Its uri round-trip is two pure
 * static converters over strings and is covered by `ToolbarItemLinkUriTest` as
 * a unit test. This is the other half: turning a uri into the attributes a
 * link carries, applying those attributes to an element, and answering whether
 * the account may reach the target. Every one of those goes through
 * `Url::fromUri()`, the path validator, the url generator and the access
 * manager, so a stub would be choosing the answers rather than reading them —
 * these criteria are only meaningful against a router that really resolves
 * `/user/login` to `user.login`.
 *
 * `getUriAsAttributes()` has four shapes to answer for, and they are four
 * separate branches rather than one path with conditionals:
 *
 * 1. A routed internal uri gets an href and `data-drupal-link-system-path`,
 *    the attribute `core/drupal.active-link` compares against the current
 *    request's own path. The front page is the one route whose internal path
 *    is the empty string, so it is written as the `<front>` token instead —
 *    without that, every item on the site would carry an empty path attribute
 *    and the comparison would be meaningless.
 * 2. A routed uri carrying query parameters additionally gets
 *    `data-drupal-link-query`, key-sorted and JSON-encoded. That attribute is
 *    the 2026-08-15 fix. `active-link.js` treats a missing attribute as "no
 *    query at all", so before it a group of items separated only by their
 *    query all lit up as current on the bare path. The `ksort()` is
 *    load-bearing rather than tidiness: the script compares the JSON of its
 *    own sorted copy, so an unsorted encode never matches.
 * 3. An external uri gets a plain href and nothing else. The branch calls
 *    `toString(FALSE)` because external URLs cannot carry cacheable metadata,
 *    and it never reaches the routed block, so an external link with a query
 *    gets no query attribute either.
 * 4. A `<nolink>` uri gets an empty href — and, because it returns before the
 *    routed block, no system path attribute. That is what stops a non-link
 *    item from being reported as the current page on the front page, since
 *    `<nolink>`'s own internal path is empty and would otherwise be written as
 *    `<front>`.
 *
 * `linkProcessElement()` is the one consumer of all four: it turns the element
 * into an anchor, writes whatever attributes the uri produced, and adds the
 * configured `target` on top. `uriAccess()` is the gate in front of it, and it
 * has exactly one rule of its own — an empty uri is forbidden outright —
 * before deferring to the url's own answer.
 *
 * The trait's members are reached through `TestToolbarItemLink`, the one
 * concrete class in the toolbar test fixtures that uses the trait and forwards
 * to its protected members. The unit half uses the same class, which is why it
 * lives in the fixtures rather than in either test file.
 *
 * `neo_toolbar`'s own `config/install` is deliberately not installed, for the
 * same reason the other kernel tests here skip it: one shipped item carries a
 * plugin from `neo_favicon`, a package this module does not depend on. Nothing
 * in this class needs a toolbar entity at all.
 */
#[Group('neo_toolbar')]
final class ToolbarItemLinkUrlTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   *
   * `KernelTestBase` installs exactly what is named here and does not resolve
   * the info file's dependency closure, so `token`, `neo`, `neo_modal`,
   * `neo_tooltip` and `neo_image` stay out. `system` and `user` are not floor
   * here but instruments: they are what supplies the routes these criteria
   * resolve against — `<front>` and `<nolink>` from `system`, `/user/login`
   * from `user`, and `/admin/config/system/site-information` as a route that
   * anonymous cannot reach. `neo_toolbar_test` supplies the link harness.
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
    // Only the access criterion needs accounts, but the current user is read
    // through the same proxy on every route access check, so the user schema
    // and the anonymous role have to exist before any of them runs.
    $this->installEntitySchema('user');
    $this->installConfig(['user']);
  }

  /**
   * A routed uri answers an href and the path active-link compares against.
   *
   * The two attributes are produced from different services and mean different
   * things. The href comes from the url generator and is what the browser
   * follows — it carries the base path, the alias and anything else a route
   * processor added. `data-drupal-link-system-path` comes from
   * `Url::getInternalPath()` and is the *unaliased* route path, because that
   * is the form `core/drupal.active-link` can compare against the current
   * request without having to resolve an alias in JavaScript.
   *
   * The front page is the special case that has to be spelled out. Its route
   * path is `/`, so its internal path is the empty string, and an empty
   * attribute would compare equal to nothing at all. It is written as the
   * `<front>` token instead, which is the same token the url field's own
   * description tells an editor to type.
   *
   * The uri may arrive as a string or as a `Url` already — `getUrlFromUri()`
   * passes an object through untouched — and either way any attributes handed
   * in are built on top of rather than replaced, which is what lets a plugin
   * pass its own classes through the same call.
   *
   * Covers: it answers an href and the active-link system path for a routed
   * uri, writing the front page as <front>.
   */
  public function testRoutedUriAnswersHrefAndTheActiveLinkSystemPath(): void {
    $link = new TestToolbarItemLink();

    // An ordinary routed path: the href is what the browser follows, and the
    // system path is the same route without its leading slash.
    $this->assertSame(
      [
        'data-drupal-link-system-path' => 'user/login',
        'href' => '/user/login',
      ],
      $link->uriAsAttributes('internal:/user/login')
    );

    // A deeper path is not treated any differently — the whole internal path
    // goes in, not just its first segment.
    $this->assertSame(
      [
        'data-drupal-link-system-path' => 'admin/config/system/site-information',
        'href' => '/admin/config/system/site-information',
      ],
      $link->uriAsAttributes('internal:/admin/config/system/site-information')
    );

    // The front page: href `/`, and the token rather than the empty string
    // its internal path really is.
    $this->assertSame('', Url::fromRoute('<front>')->getInternalPath());
    $this->assertSame(
      [
        'data-drupal-link-system-path' => '<front>',
        'href' => '/',
      ],
      $link->uriAsAttributes('internal:/')
    );

    // A `route:` uri and a `Url` object reach the same place as the path did.
    $this->assertSame(
      $link->uriAsAttributes('internal:/user/login'),
      $link->uriAsAttributes('route:user.login')
    );
    $this->assertSame(
      $link->uriAsAttributes('internal:/user/login'),
      $link->uriAsAttributes(Url::fromRoute('user.login'))
    );

    // Attributes handed in are built on top of, never replaced.
    $this->assertSame(
      [
        'class' => ['toolbar-item'],
        'data-drupal-link-system-path' => 'user/login',
        'href' => '/user/login',
      ],
      $link->uriAsAttributes('internal:/user/login', ['class' => ['toolbar-item']])
    );
  }

  /**
   * A query on a routed uri becomes the key-sorted JSON query attribute.
   *
   * This is the 2026-08-15 fix, and the trait's own comment says what it was
   * fixing: `core/drupal.active-link` compares *both* the system path and the
   * query, and reads a missing `data-drupal-link-query` as "this link has no
   * query". A toolbar holding several items that differ only in their query —
   * `?type=inverter`, `?type=panel` — therefore had every one of them light up
   * as current the moment the bare path was visited, and an item pointing at
   * the front page with a parameter read as current on every visit to the
   * front page.
   *
   * The sort is not tidiness. `active-link.js` builds its own copy of the
   * current request's query, sorts it, JSON-encodes it and compares strings —
   * so an attribute encoded in the order the editor happened to type the
   * parameters would simply never match.
   *
   * The attribute is omitted rather than emitted empty when there is no query,
   * which is the state `active-link.js` reads as "no query", and is why the
   * fix could be added without changing what every existing item does.
   *
   * Covers: it answers the key-sorted JSON query attribute for a routed uri
   * with query parameters, and omits it without.
   */
  public function testRoutedUriWithQueryAnswersTheKeySortedJsonQueryAttribute(): void {
    $link = new TestToolbarItemLink();

    // The parameters are sorted by key before encoding, whatever order the
    // uri carried them in.
    $attributes = $link->uriAsAttributes('internal:/user/login?b=2&a=1');
    $this->assertSame('{"a":"1","b":"2"}', $attributes['data-drupal-link-query']);

    $attributes = $link->uriAsAttributes('internal:/user/login?z=1&a=2&m=3');
    $this->assertSame('{"a":"2","m":"3","z":"1"}', $attributes['data-drupal-link-query']);

    // Already in order, so the sort is invisible — the encode is not.
    $attributes = $link->uriAsAttributes('internal:/user/login?a=1&b=2');
    $this->assertSame('{"a":"1","b":"2"}', $attributes['data-drupal-link-query']);

    // A query carried in the url's options rather than in the uri string
    // reaches the same attribute, because the trait reads the option and not
    // the string.
    $attributes = $link->uriAsAttributes(
      Url::fromRoute('user.login', [], ['query' => ['b' => '2', 'a' => '1']])
    );
    $this->assertSame('{"a":"1","b":"2"}', $attributes['data-drupal-link-query']);

    // The front page with a parameter is the case the comment calls out by
    // name: it keeps its `<front>` token and gains a query of its own, so it
    // no longer reads as current on every plain visit to the front page.
    $this->assertSame(
      [
        'data-drupal-link-system-path' => '<front>',
        'data-drupal-link-query' => '{"page":"2"}',
        'href' => '/?page=2',
      ],
      $link->uriAsAttributes('internal:/?page=2')
    );

    // Without a query the attribute is absent, not empty. That absence is
    // what `active-link.js` reads as "no query".
    $this->assertArrayNotHasKey(
      'data-drupal-link-query',
      $link->uriAsAttributes('internal:/user/login')
    );
    $this->assertArrayNotHasKey(
      'data-drupal-link-query',
      $link->uriAsAttributes('internal:/')
    );
  }

  /**
   * An external uri answers its own string and nothing else.
   *
   * External URLs are the one branch that calls `toString(FALSE)`: there is no
   * route behind them, so there is no cacheable metadata to collect and
   * nothing for a render context to bubble.
   *
   * The branch returning early is as much of the contract as the href is. An
   * external uri never reaches the routed block, so it gets neither
   * `data-drupal-link-system-path` nor `data-drupal-link-query` — which is
   * correct, because both attributes describe a path on *this* site and
   * `active-link.js` would be comparing them against the wrong host.
   *
   * Covers: it answers a plain href for an external uri.
   */
  public function testExternalUriAnswersPlainHref(): void {
    $link = new TestToolbarItemLink();

    $this->assertSame(
      ['href' => 'https://example.com/foo'],
      $link->uriAsAttributes('https://example.com/foo')
    );

    // A mail uri and a protocol-relative url are external too.
    $this->assertSame(
      ['href' => 'mailto:someone@example.com'],
      $link->uriAsAttributes('mailto:someone@example.com')
    );
    $this->assertSame(
      ['href' => '//example.com/foo'],
      $link->uriAsAttributes('//example.com/foo')
    );

    // A query rides along in the href and produces no query attribute, since
    // the branch returns before the routed block that would have written one.
    $this->assertSame(
      ['href' => 'https://example.com/foo?ref=toolbar'],
      $link->uriAsAttributes('https://example.com/foo?ref=toolbar')
    );

    // Handed-in attributes still survive; it is only the trait's own two that
    // are never added.
    $this->assertSame(
      [
        'class' => ['toolbar-item'],
        'href' => 'https://example.com/foo',
      ],
      $link->uriAsAttributes('https://example.com/foo', ['class' => ['toolbar-item']])
    );
  }

  /**
   * A nolink uri answers an empty href and no path to compare against.
   *
   * `<nolink>` is how a toolbar item says it is a label or a trigger rather
   * than a destination. The href is emptied deliberately rather than omitted,
   * so the anchor still renders and still takes the toolbar's own styling.
   *
   * The absent system path is the half that matters more. `<nolink>`'s route
   * path is empty, exactly like the front page's, so had the branch fallen
   * through to the routed block every non-link item on the site would have
   * been labelled `<front>` and reported as the current page every time the
   * front page was visited.
   *
   * Covers: it answers an empty href for a nolink uri.
   */
  public function testNolinkUriAnswersEmptyHref(): void {
    $link = new TestToolbarItemLink();

    $this->assertSame(['href' => ''], $link->uriAsAttributes('route:<nolink>'));
    $this->assertSame(['href' => ''], $link->uriAsAttributes(Url::fromRoute('<nolink>')));

    // The route this branch guards against being mistaken for: its internal
    // path is empty, which is what the routed block would have written as
    // `<front>`.
    $this->assertSame('', Url::fromRoute('<nolink>')->getInternalPath());
    $this->assertArrayNotHasKey(
      'data-drupal-link-system-path',
      $link->uriAsAttributes('route:<nolink>')
    );

    // Only `<nolink>` is named. Its two siblings are routed like anything
    // else, and `<none>` shares the empty path, so it does get the token.
    $this->assertSame(
      [
        'data-drupal-link-system-path' => '<front>',
        'href' => '',
      ],
      $link->uriAsAttributes('route:<none>')
    );
  }

  /**
   * Applying a uri to an element makes it an anchor and adds the target.
   *
   * This is the only caller of the attribute builder that most item plugins
   * ever make. It does three things in order: the element's tag becomes `a`,
   * so the item renders as a link rather than the `span` it defaults to; every
   * attribute the uri produced is written onto the element; and `target` is
   * added last, from the argument if one was passed and from the item's own
   * configuration otherwise.
   *
   * The whole of it is guarded on there being a uri at all. An item with no
   * url configured is left exactly as it was — still a `span`, still with no
   * attributes — which is what lets a plugin call this unconditionally and let
   * the configuration decide.
   *
   * Covers: it sets the anchor tag, the link attributes and the configured
   * target on an element.
   */
  public function testProcessElementSetsTheAnchorTagAttributesAndTarget(): void {
    $link = new TestToolbarItemLink([
      'url' => 'internal:/user/login?b=2&a=1',
      'target' => '_blank',
    ]);

    $element = $this->element();
    $this->assertSame('span', $element->getTag());

    $link->linkProcessElement($element);

    $this->assertSame('a', $element->getTag());
    // The href keeps the order the uri was written in; only the attribute
    // `active-link.js` compares is sorted, and it is sorted independently of
    // what the browser is asked to follow.
    $this->assertSame(
      [
        'data-drupal-link-system-path' => 'user/login',
        'data-drupal-link-query' => '{"a":"1","b":"2"}',
        'href' => '/user/login?b=2&a=1',
        'target' => '_blank',
      ],
      $element->toRenderable()['#attributes']->toArray()
    );

    // Without a configured target there is no target attribute, rather than
    // an empty one.
    $noTarget = $this->element();
    (new TestToolbarItemLink(['url' => 'internal:/user/login']))->linkProcessElement($noTarget);
    $this->assertSame('a', $noTarget->getTag());
    $this->assertSame(
      [
        'data-drupal-link-system-path' => 'user/login',
        'href' => '/user/login',
      ],
      $noTarget->toRenderable()['#attributes']->toArray()
    );

    // Both arguments win over the configuration, which is how a plugin
    // carrying more than one link drives the same element.
    $overridden = $this->element();
    $link->linkProcessElement($overridden, 'https://example.com/foo', '_self');
    $this->assertSame('a', $overridden->getTag());
    $this->assertSame(
      [
        'href' => 'https://example.com/foo',
        'target' => '_self',
      ],
      $overridden->toRenderable()['#attributes']->toArray()
    );

    // No uri anywhere: the element is left untouched, tag included.
    $untouched = $this->element();
    (new TestToolbarItemLink())->linkProcessElement($untouched);
    $this->assertSame('span', $untouched->getTag());
    $this->assertSame([], $untouched->toRenderable()['#attributes']->toArray());
  }

  /**
   * Uri access forbids an empty uri, and otherwise defers to the url.
   *
   * The trait owns exactly one rule here: an empty uri is forbidden, and it is
   * forbidden before `Url::fromUri()` is reached — which is not only policy
   * but necessity, since `Url::fromUri('')` throws on a uri with no scheme.
   * Everything else is the url's own answer, which for a routed url is the
   * access manager against the current user and for an unrouted one is
   * unconditionally allowed.
   *
   * The answer is rebuilt rather than returned. `Url::access()` is called for
   * its boolean and a fresh `AccessResult` is constructed from it, so whatever
   * cache contexts and tags the route's access check bubbled up are dropped on
   * the floor. That matters to a caller that varies its own cacheability by
   * this result, and it is pinned here as the current behaviour rather than
   * defended.
   *
   * Covers: uri access forbids an empty uri, and otherwise answers the url's
   * own access.
   */
  public function testUriAccessForbidsEmptyUriAndOtherwiseAnswersTheUrlAccess(): void {
    $link = new TestToolbarItemLink();

    // The trait's own rule, before any url is built.
    $this->assertTrue($link->accessForUri('')->isForbidden());
    $this->assertTrue($link->accessForUri(NULL)->isForbidden());
    // `empty()` is the test, so the string `'0'` is an empty uri as well.
    $this->assertTrue($link->accessForUri('0')->isForbidden());

    // A route open to everyone is allowed, for anonymous as much as anyone.
    $this->assertTrue(\Drupal::currentUser()->isAnonymous());
    $this->assertTrue($link->accessForUri('internal:/')->isAllowed());

    // A route behind a permission the current user does not hold is not.
    $adminUri = 'internal:/admin/config/system/site-information';
    $this->assertFalse(\Drupal::currentUser()->hasPermission('administer site configuration'));
    $this->assertTrue($link->accessForUri($adminUri)->isForbidden());

    // The same uri flips the moment the current user holds the permission,
    // which is the whole of "answers the url's own access".
    \Drupal::currentUser()->setAccount($this->createAccount('site_admin', ['administer site configuration']));
    $this->assertTrue($link->accessForUri($adminUri)->isAllowed());

    // An unrouted url has nothing to check, so it is allowed outright.
    $this->assertTrue($link->accessForUri('https://example.com/foo')->isAllowed());
    $this->assertTrue($link->accessForUri('base:not/a/route')->isAllowed());

    // The result is rebuilt from a boolean, so the route access check's own
    // cacheability never reaches the caller.
    /** @var \Drupal\Core\Access\AccessResult $result */
    $result = $link->accessForUri($adminUri);
    $this->assertInstanceOf(AccessResult::class, $result);
    $this->assertSame([], $result->getCacheContexts());
    $this->assertSame([], $result->getCacheTags());
    $this->assertSame(-1, $result->getCacheMaxAge());
  }

  /**
   * Builds a horizontal element with a title and nothing else.
   *
   * Horizontal-with-a-title is what keeps the tooltip branch out of the way: a
   * hidden title would put `Tooltip`'s own attributes into the same bag these
   * criteria read.
   *
   * @return \Drupal\neo_toolbar\ToolbarItemElement
   *   A toolbar item element carrying no attributes of its own.
   */
  private function element(): ToolbarItemElement {
    return new ToolbarItemElement('neo_toolbar_test', 'Test item', 'horizontal');
  }

  /**
   * Creates an account holding the given permissions.
   *
   * The uid is given explicitly, because the first account saved into an empty
   * `users` table would become user 1 and be handed every permission on the
   * site by the super user access policy — which would make the forbidden half
   * of the access criterion unreachable.
   *
   * @param string $name
   *   The account and role name.
   * @param string[] $permissions
   *   The permissions the role grants.
   *
   * @return \Drupal\user\UserInterface
   *   The saved account.
   */
  private function createAccount(string $name, array $permissions): User {
    Role::create([
      'id' => $name,
      'label' => $name,
      'permissions' => $permissions,
    ])->save();
    User::create([
      'uid' => 2,
      'name' => $name,
      'status' => 1,
      'roles' => [$name],
    ])->save();
    return User::load(2);
  }

}
