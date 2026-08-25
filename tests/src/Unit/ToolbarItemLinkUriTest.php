<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_toolbar\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormState;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\neo_toolbar_test\TestToolbarItemLink;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Characterises the link trait's uri round-trip.
 *
 * `ToolbarItemLinkTrait` carries two `protected static` converters that are
 * documented inverses of one another, and one `public static` form validator
 * built on top of them. Nine toolbar item plugins in this module use the
 * trait, and at least one more does from a sibling package, so a defect here
 * is paid for nine times over — and the code path they feed needed a fix as
 * recently as 2026-08-15.
 *
 * `getUriAsDisplayableString()` turns a stored uri into the string an editor
 * sees in the url field: it strips the `internal:` scheme, renders the front
 * page as `<front>`, and renders an `entity:` uri as the entity autocomplete
 * would have written it. `getUserEnteredStringAsUri()` turns what the editor
 * typed back into a uri: an autocomplete string becomes `entity:node/N`, a
 * schemeless string gains `internal:`, and `<front>` becomes `/` on the way.
 * A string that already carries any other scheme passes through both
 * directions untouched, which is what keeps an external URL an external URL.
 *
 * Three things about how they are reached here.
 *
 * Both converters are `protected static`, so the tests call them through
 * `TestToolbarItemLink` — the one concrete class in the toolbar test fixtures
 * that uses the trait and forwards to its protected members. Duplicating that
 * exposure in this file is the alternative, and it would drift from the kernel
 * half that needs the same thing.
 *
 * The `entity:` branch is the only one that leaves the trait. It asks the
 * entity type manager for the definition and then for the entity, and hands
 * the result to `EntityAutocomplete::getEntityLabels()`, which reads the
 * entity's view-label access, its label and its id, through
 * `entity.repository` for the translation. A stub container carrying those two
 * services is the whole environment these tests need; nothing here touches a
 * database, a router or a url generator.
 *
 * The validator is asserted against a real `FormState` rather than a mock,
 * because both halves of the criterion are things the form state records — the
 * rewritten value and the error — and a mock would assert that the trait made
 * two calls rather than that the form ends up carrying the right values.
 *
 * One boundary. The trait's url building — `getUriAsAttributes()`,
 * `getUrlFromUri()` and `uriAccess()` — needs the real router and belongs to
 * the kernel half; this class stops at the two pure statics and the validator
 * that sits on them.
 */
#[Group('neo_toolbar')]
final class ToolbarItemLinkUriTest extends UnitTestCase {

  /**
   * The label the fixture entity answers with.
   *
   * @var string
   */
  private const ENTITY_LABEL = 'Test node';

  /**
   * The id the fixture entity answers with.
   *
   * @var string
   */
  private const ENTITY_ID = '42';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // The entity the `entity:` branch resolves. `getEntityLabels()` reads its
    // view-label access, its label, whether it is new and its id, and nothing
    // else — which is why a mock is enough instead of entity storage.
    $entity = $this->createMock(EntityInterface::class);
    $entity->method('access')->willReturn(TRUE);
    $entity->method('label')->willReturn(self::ENTITY_LABEL);
    $entity->method('isNew')->willReturn(FALSE);
    $entity->method('id')->willReturn(self::ENTITY_ID);

    // Only the one id resolves. Everything else answers NULL, so the branch
    // where the entity has been deleted out from under a saved uri is
    // reachable from a test.
    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->willReturnCallback(
      static function ($id) use ($entity) {
        return (string) $id === self::ENTITY_ID ? $entity : NULL;
      }
    );

    // `getDefinition($type, FALSE)` is the trait's own guard against a uri
    // naming an entity type this site does not have.
    $entityType = $this->createMock(EntityTypeInterface::class);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getDefinition')->willReturnCallback(
      static function ($type) use ($entityType) {
        return $type === 'node' ? $entityType : NULL;
      }
    );
    $entityTypeManager->method('getStorage')->willReturn($storage);

    // `getEntityLabels()` puts the entity in the display language first.
    $entityRepository = $this->createMock(EntityRepositoryInterface::class);
    $entityRepository->method('getTranslationFromContext')->willReturnArgument(0);

    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $entityTypeManager);
    $container->set('entity.repository', $entityRepository);
    $container->set('string_translation', $this->getStringTranslationStub());
    \Drupal::setContainer($container);
  }

  /**
   * An internal uri loses its scheme, and the front page gains its token.
   *
   * The url field an editor types into holds paths, not uris, so the whole of
   * the `internal:` scheme is display noise and comes off. The front page is
   * the one path that cannot survive that on its own: `internal:/` would show
   * as a bare `/`, which reads as "the site root" rather than as the front
   * page route, so it is rendered as the `<front>` token the field's own
   * description tells the editor to type.
   *
   * The token replaces only the leading slash, not the whole reference, which
   * is what keeps a fragment or a query attached to the front page intact.
   *
   * Covers: it strips the internal scheme, and renders the front page path as
   * <front>.
   */
  public function testInternalSchemeIsStrippedAndTheFrontPageRendersAsItsToken(): void {
    // The scheme comes off and the path is shown exactly as stored.
    $this->assertSame('/node/1', TestToolbarItemLink::uriAsDisplayableString('internal:/node/1'));
    $this->assertSame('/admin/config', TestToolbarItemLink::uriAsDisplayableString('internal:/admin/config'));

    // A query and a fragment ride along on the reference, untouched.
    $this->assertSame('/node/1?page=2', TestToolbarItemLink::uriAsDisplayableString('internal:/node/1?page=2'));
    $this->assertSame('/node/1#main', TestToolbarItemLink::uriAsDisplayableString('internal:/node/1#main'));

    // The front page is the special case: a bare `/` becomes the token.
    $this->assertSame('<front>', TestToolbarItemLink::uriAsDisplayableString('internal:/'));

    // And only the leading slash is replaced, so what hangs off the front page
    // survives the substitution.
    $this->assertSame('<front>#main', TestToolbarItemLink::uriAsDisplayableString('internal:/#main'));
    $this->assertSame('<front>?page=2', TestToolbarItemLink::uriAsDisplayableString('internal:/?page=2'));
  }

  /**
   * An entity uri is rendered the way the autocomplete wrote it.
   *
   * The url field is an entity autocomplete, so the string it shows for a
   * stored `entity:` uri has to be the string that autocomplete would have
   * produced — `label (id)` — or an editor opening the form and saving it
   * again would rewrite the item's uri into whatever the field happened to
   * display.
   *
   * The branch is guarded twice, and both guards fall back to showing the raw
   * uri rather than throwing: an entity type this site does not have, and an
   * entity that has since been deleted. That fallback is the reason a stale
   * item still opens in a form at all.
   *
   * Covers: it renders an entity uri as the entity autocomplete label.
   */
  public function testEntityUriRendersAsTheEntityAutocompleteLabel(): void {
    $this->assertSame(
      self::ENTITY_LABEL . ' (' . self::ENTITY_ID . ')',
      TestToolbarItemLink::uriAsDisplayableString('entity:node/' . self::ENTITY_ID)
    );

    // An entity type the site does not have falls back to the raw uri.
    $this->assertSame(
      'entity:unknown_type/42',
      TestToolbarItemLink::uriAsDisplayableString('entity:unknown_type/42')
    );

    // So does an entity that no longer loads.
    $this->assertSame(
      'entity:node/999',
      TestToolbarItemLink::uriAsDisplayableString('entity:node/999')
    );
  }

  /**
   * Any other scheme is left exactly as it stands, in both directions.
   *
   * `internal:` and `entity:` are the only two schemes either converter knows
   * about. Everything else — an external URL, a `route:` uri, a `base:` uri —
   * is already in the shape both the editor and `Url::fromUri()` want, so both
   * converters return it byte for byte. That is what stops a link to another
   * site from being rewritten into a path on this one.
   *
   * The reverse direction needs the pass-through for a second reason: it is
   * also how an `internal:` uri that was never displayed survives a round trip
   * through a form that did not touch it.
   *
   * Covers: it leaves a uri that already carries a scheme untouched, in both
   * directions.
   */
  public function testSchemedUriIsUntouchedInBothDirections(): void {
    $schemed = [
      'https://example.com/foo',
      'http://example.com',
      'mailto:someone@example.com',
      'route:<nolink>',
      'base:admin/config',
    ];
    foreach ($schemed as $uri) {
      $this->assertSame($uri, TestToolbarItemLink::uriAsDisplayableString($uri), $uri);
      $this->assertSame($uri, TestToolbarItemLink::userEnteredStringAsUri($uri), $uri);
    }

    // The two schemes the converters do know about pass through the reverse
    // direction untouched as well, because they already carry a scheme.
    $this->assertSame('internal:/node/1', TestToolbarItemLink::userEnteredStringAsUri('internal:/node/1'));
    $this->assertSame('entity:node/42', TestToolbarItemLink::userEnteredStringAsUri('entity:node/42'));
  }

  /**
   * An autocomplete string becomes an entity uri.
   *
   * What the editor leaves in the field after picking a suggestion is
   * `label (id)`, and that is what has to become `entity:node/N` before it is
   * stored. The extraction is core's, and it takes the last parenthesised run
   * preceded by whitespace, so a label that itself contains parentheses still
   * resolves to the trailing id.
   *
   * The entity type is hardcoded to `node`, which is a core limitation the
   * trait inherited along with the code — a uri for any other entity type
   * cannot be produced through this field today.
   *
   * Covers: it maps an entity autocomplete string to an entity uri.
   */
  public function testAutocompleteStringMapsToEntityUri(): void {
    $this->assertSame('entity:node/42', TestToolbarItemLink::userEnteredStringAsUri('Test node (42)'));

    // The id is taken from the trailing parentheses, so a label carrying its
    // own still resolves.
    $this->assertSame('entity:node/7', TestToolbarItemLink::userEnteredStringAsUri('Some page (draft) (7)'));

    // Every entity type is written as `node`, whatever the label described.
    $this->assertSame('entity:node/3', TestToolbarItemLink::userEnteredStringAsUri('Some term (3)'));

    // Without the whitespace before the parentheses there is no match at all,
    // and the string falls through to the schemeless branch.
    $this->assertSame('internal:Node(42)', TestToolbarItemLink::userEnteredStringAsUri('Node(42)'));
  }

  /**
   * A schemeless string becomes internal, and the front token becomes a path.
   *
   * Anything the editor typed that carries no scheme is a path on this site,
   * so it is stored as `internal:`. The `<front>` token is translated back to
   * `/` before the scheme is prepended rather than after, which is what makes
   * `<front>#main` come back as `internal:/#main` instead of a uri with the
   * token still embedded in it.
   *
   * An empty string is the one input that reaches neither branch: it is
   * returned as it arrived, so an empty field does not become `internal:`.
   *
   * Covers: it maps a schemeless string to an internal uri, and <front> back
   * to the front page path.
   */
  public function testSchemelessStringMapsToInternalUriAndFrontTokenMapsBack(): void {
    $this->assertSame('internal:/node/1', TestToolbarItemLink::userEnteredStringAsUri('/node/1'));
    $this->assertSame('internal:/admin/config', TestToolbarItemLink::userEnteredStringAsUri('/admin/config'));

    // A path with no leading slash is still schemeless, so it is still made
    // internal here — refusing it is the validator's job, not the converter's.
    $this->assertSame('internal:node/1', TestToolbarItemLink::userEnteredStringAsUri('node/1'));

    // A bare query or fragment is schemeless too.
    $this->assertSame('internal:?page=2', TestToolbarItemLink::userEnteredStringAsUri('?page=2'));
    $this->assertSame('internal:#main', TestToolbarItemLink::userEnteredStringAsUri('#main'));

    // The token becomes the front page path.
    $this->assertSame('internal:/', TestToolbarItemLink::userEnteredStringAsUri('<front>'));

    // The substitution happens before the scheme is prepended, so what hangs
    // off the token hangs off the path.
    $this->assertSame('internal:/#main', TestToolbarItemLink::userEnteredStringAsUri('<front>#main'));
    $this->assertSame('internal:/?page=2', TestToolbarItemLink::userEnteredStringAsUri('<front>?page=2'));

    // An empty string reaches neither branch and is returned as it arrived.
    $this->assertSame('', TestToolbarItemLink::userEnteredStringAsUri(''));
  }

  /**
   * The two converters compose back to what they started with.
   *
   * Each converter's docblock names the other as its inverse, and that is the
   * property the url form depends on: the form displays a stored uri, the
   * editor saves without touching the field, and the value written back has to
   * be the uri that was there before. Asserting each direction alone would not
   * catch a pair that drifted in the same direction together.
   *
   * The composition holds over the internal and entity forms, and over the
   * schemed pass-through. It does not hold in the display direction for a uri
   * whose entity has gone — that displays as the raw uri, and the raw uri
   * carries a scheme, so it returns as itself; that is covered above as the
   * fallback it is, rather than claimed as an inverse here.
   *
   * Covers: the two converters are inverses over the internal and entity
   * forms.
   */
  public function testTheConvertersAreInversesOverInternalAndEntityForms(): void {
    $uris = [
      'internal:/node/1',
      'internal:/admin/config',
      'internal:/node/1?page=2',
      'internal:/',
      'internal:/#main',
      'internal:/?page=2',
      'entity:node/' . self::ENTITY_ID,
      'https://example.com/foo',
    ];
    foreach ($uris as $uri) {
      $displayable = TestToolbarItemLink::uriAsDisplayableString($uri);
      $this->assertSame($uri, TestToolbarItemLink::userEnteredStringAsUri($displayable), $uri);
    }

    // And the other way around, from what an editor may type.
    $strings = [
      '/node/1',
      '<front>',
      '<front>#main',
      'Test node (42)',
      'https://example.com/foo',
    ];
    foreach ($strings as $string) {
      $uri = TestToolbarItemLink::userEnteredStringAsUri($string);
      $this->assertSame($string, TestToolbarItemLink::uriAsDisplayableString($uri), $string);
    }
  }

  /**
   * The validator stores the uri form, and refuses a bare relative path.
   *
   * The element does two things, in this order. It rewrites its own value from
   * what the editor typed to the uri that will be stored, so every consumer of
   * the saved configuration reads a uri and never a display string. Then it
   * refuses one shape: a value that became `internal:` but does not begin with
   * `/`, `?` or `#`, because `node/1` typed by hand is ambiguous in a way
   * `/node/1` is not.
   *
   * `<front>` is exempted by name. It fails the first-character test — it
   * begins with `<` — and is allowed through anyway for backwards
   * compatibility, which is the whole reason the token is still accepted input.
   *
   * The rewrite happens whether or not the error follows, so a rejected form
   * still redisplays with the value the validator computed.
   *
   * Covers: the validator rewrites the element value and errors on a manual
   * path with no leading slash, question mark or hash.
   */
  public function testValidatorRewritesTheValueAndRefusesPathsWithoutLeadingMarker(): void {
    // A path with a leading slash is rewritten and accepted.
    $formState = new FormState();
    $element = ['#parents' => ['url'], '#value' => '/node/1'];
    TestToolbarItemLink::validateUriElement($element, $formState, []);
    $this->assertSame('internal:/node/1', $formState->getValue('url'));
    $this->assertSame([], $formState->getErrors());

    // So are a bare query and a bare fragment.
    foreach (['?page=2' => 'internal:?page=2', '#main' => 'internal:#main'] as $value => $uri) {
      $formState = new FormState();
      $element = ['#parents' => ['url'], '#value' => $value];
      TestToolbarItemLink::validateUriElement($element, $formState, []);
      $this->assertSame($uri, $formState->getValue('url'), $value);
      $this->assertSame([], $formState->getErrors(), $value);
    }

    // `<front>` is exempted by name even though it fails the character test.
    $formState = new FormState();
    $element = ['#parents' => ['url'], '#value' => '<front>'];
    TestToolbarItemLink::validateUriElement($element, $formState, []);
    $this->assertSame('internal:/', $formState->getValue('url'));
    $this->assertSame([], $formState->getErrors());

    // An autocomplete pick is rewritten to the entity uri and accepted, since
    // the uri it produced is not `internal:` at all.
    $formState = new FormState();
    $element = ['#parents' => ['url'], '#value' => 'Test node (42)'];
    TestToolbarItemLink::validateUriElement($element, $formState, []);
    $this->assertSame('entity:node/42', $formState->getValue('url'));
    $this->assertSame([], $formState->getErrors());

    // An external URL keeps its own scheme and is never inspected further.
    $formState = new FormState();
    $element = ['#parents' => ['url'], '#value' => 'https://example.com/foo'];
    TestToolbarItemLink::validateUriElement($element, $formState, []);
    $this->assertSame('https://example.com/foo', $formState->getValue('url'));
    $this->assertSame([], $formState->getErrors());

    // A hand-typed relative path is the shape the validator exists to refuse.
    $formState = new FormState();
    $element = ['#parents' => ['url'], '#value' => 'node/1'];
    TestToolbarItemLink::validateUriElement($element, $formState, []);

    // The rewrite still happened, so the redisplayed form carries the uri.
    $this->assertSame('internal:node/1', $formState->getValue('url'));

    $errors = $formState->getErrors();
    $this->assertArrayHasKey('url', $errors);
    $this->assertInstanceOf(TranslatableMarkup::class, $errors['url']);
    $this->assertSame(
      'Manually entered paths should start with /, ? or #.',
      $errors['url']->getUntranslatedString()
    );
  }

}
