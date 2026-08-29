# CONTEXT — neo_toolbar

Terms specific to the Neo toolbar: its items, regions and badges, and the pipeline that
assembles them. One entry per term: what it IS, then the names not to use for it.

## Toolbar (`neo_toolbar`)

**Toolbar** — the `neo_toolbar` config entity: a named, weighted, visibility-conditioned bar, with
a set of **toolbar regions** and the **toolbar items** placed in them. Several may exist; one is
the **active toolbar** for a given request. _Avoid:_ "the admin bar", and using it for core's own
`toolbar` module, which is a different thing this module replaces.

**Active toolbar** — the one **toolbar** a request renders: the route's `neo_toolbar` parameter
when there is one, otherwise the first enabled toolbar the account may view, in **toolbar** sort
order. Resolved once per request by the **toolbar repository** and memoized. _Avoid:_ "current
toolbar", "default toolbar".

**Toolbar item** — the `neo_toolbar_item` config entity: one entry on a **toolbar**, bound to one
**toolbar region**, wrapping a **toolbar item plugin** and the settings it was configured with.
_Avoid:_ "toolbar link", "button", and using it for the **element** the item builds.

**Toolbar item plugin** — the plugin a **toolbar item** wraps: it supplies the item's **elements**,
its access answer and its **sibling access** rule. Its icon is either a **declared icon** or, for
the three plugins whose icon is a setting, a **configured icon**. Its base class, its **toolbar
item attribute** and the **element** builder are public API across packages — `neo_favicon` ships
one. _Avoid:_ "item type", "widget".

**Toolbar item attribute** — the `#[ToolbarItem]` PHP attribute that declares a **toolbar item
plugin**: its id, label, description, **region-creation flag**, context definitions and **declared
icon**. It is what plugin discovery reads, it is public API across packages, and it is not
inherited — a subclass plugin declares its own or is not discovered at all. _Avoid:_ "the plugin
annotation", "the plugin definition", which is what discovery produces from it.

**Declared icon** — the icon a **toolbar item plugin** names in its **toolbar item attribute**,
which the plugin base class answers by default. A plugin that declares none answers null. _Avoid:_
"static icon", "hardcoded icon", and using it for a **configured icon**.

**Configured icon** — the icon a **toolbar item**'s own settings carry, answered by the `link`,
`create` and `region` plugins in place of a **declared icon** because a site administrator chose
it. There is no declared fallback behind it. _Avoid:_ "the icon field", "the item icon".

**Region-creation flag** — the **toolbar item attribute**'s `region_create` declaration: a nullable
boolean, false by default, that the deriver tests strictly for true before building a **derived
region** from a **toolbar item**. Exactly two of the module's plugins set it. _Avoid:_ "region
support", "creates region".

**Toolbar region** — a YAML-declared plugin naming one slot on a **toolbar**, carrying an alignment
(horizontal or vertical), a position (start or end) and a weight. Some are **derived regions**.
_Avoid:_ "area", "section", and Drupal's own theme regions, which are unrelated.

**Derived region** — a **toolbar region** the deriver creates from a **toolbar item** whose plugin
sets the **region-creation flag**, so the item's children have somewhere to live. Its id is the item's id
under an `item:` prefix, and the item it hangs off is its **triggering item**. _Avoid:_ "dynamic
region", "child region".

**Triggering item** — the **toolbar item** a **derived region** was derived from. It is dropped
when its region ends up empty and restored when the region still has children, which is what makes
a dropdown disappear with its contents rather than open onto nothing. _Avoid:_ "parent item",
"region item".

**Item pipeline** — the pass the **toolbar repository** runs over a toolbar's items before
anything renders, as four **pipeline rules**: filter by view access while collecting cacheability,
apply **region collapse**, apply **sibling access**, restore **triggering items** whose region still
has children. Skipped entirely in **edit mode**. Reached through the toolbar's own `getItems()`
accessor, which memoizes the result per toolbar. _Avoid:_ "the access check", "getItems", "the item
query".

**Toolbar repository** — the `neo_toolbar.repository` service: it resolves the **active toolbar**
for a request and runs the **item pipeline** over a toolbar's items. It answers for the current
user — none of its methods takes an account. _Avoid:_ "the toolbar manager", "the toolbar service".

**Pipeline rule** — one of the **item pipeline**'s four steps, each a method on the **toolbar
repository** that takes an array of **toolbar items** and returns the ones that survive it. Each is
callable on its own, which is what makes a rule assertable without a loaded **toolbar** and a
populated item table. _Avoid:_ "pipeline step", "filter", "the access check".

**Region collapse** — the **pipeline rule** that removes a **derived region** whose items all lost
view access, together with its **triggering item**, so a dropdown disappears with its contents
rather than opening onto nothing. It is the mirror of the **triggering item** restore. _Avoid:_
"empty region removal", "region pruning".

**Sibling access** — the second access answer a **toolbar item** gives, computed from its immediate
neighbours in the same **toolbar region** rather than from the account. A divider with nothing on
one side of it, or another divider beside it, forbids itself. _Avoid:_ "neighbour access",
"adjacency rule".

**Edit mode** — the flag that turns the **item pipeline** off, so the admin UI sees every item a
toolbar has including the ones no visitor would. Set on the **active toolbar** when a route carries
it as a parameter. _Avoid:_ "admin mode", "preview mode".

**Toolbar access gate** — the single answer to whether an account may see a Neo toolbar at all,
consulted before the toolbar renders and before any block it would replace is hidden. It reads the
toolbar permission, applies the **core toolbar deferral** and the **user-1 exemption**, treats an
active masquerade as access, and remembers its answer in the **gate memo**. It is the
`neo_toolbar.access_gate` service — final, one public method, no interface — reached from the module's
own hooks by injection and from anywhere else through the **gate forwarder**. _Avoid:_ "toolbar
permission", "view access", and confusing it with a **toolbar**'s own entity access.

**Core toolbar deferral** — the **toolbar access gate**'s rule that a Neo toolbar stands down when
core's own `toolbar` module is installed and the account may use it, so a site that deliberately kept
core's toolbar keeps seeing it. _Avoid:_ "the toolbar conflict", "core override".

**User-1 exemption** — the **toolbar access gate**'s rule that user 1 is never subject to the **core
toolbar deferral**, because user 1 holds every permission by short-circuit and would otherwise be
pushed onto core's toolbar without anyone having granted anything. _Avoid:_ "the admin exception",
"superuser bypass".

**Gate memo** — the per-account result the **toolbar access gate** remembers for the length of a
request, so the gate answers once rather than once per consulting block. It is a property of the gate
service, so it is per-request by construction and a separately constructed gate starts empty.
_Avoid:_ "the toolbar cache", "the static", and confusing it with render caching or with a
**toolbar**'s cache tags.

**Gate forwarder** — `neo_toolbar_toolbar_view_access()`, the one function left in the module's
`.module` file: a global that asks the **toolbar access gate** service and returns its `bool`. It
exists so that custom code on any site calling the module's historic global answer keeps working, and
it carries no logic of its own. _Avoid:_ "the access function", "the gate" unqualified.

**Element** — `ToolbarItemElement`, the fluent render-time value object a **toolbar item plugin**
builds: a tag, a title, an icon, an image, a **badge**, five **attribute bags**, children, and an
access answer. It is the thing every toolbar template renders. _Avoid:_ "render array", "item", and
"element" in the Drupal render-element sense.

**Attribute bag** — one of the five `Attribute` objects an **element** carries — element, title,
icon, image and badge — each named by an `ElementAttributeBag` case whose backed value is the
render-array key the bag is emitted under. _Avoid:_ "attributes array", and naming one bag where
the family is meant.

**Bag accessor** — the **element**'s one internal method answering the `Attribute` object for a
given **attribute bag**, creating it on first use. Every read and write of a bag goes through it,
and it is not public: a caller already reaches all five through the **bag forwarders**. _Avoid:_
"getBag", "the attribute getter".

**Bag forwarder** — one of the fifteen public **element** methods that names one **attribute bag**
and forwards to the **bag accessor** — `addIconClass()`, `setBadgeAttribute()`,
`mergeTitleAttributes()` and their siblings. Their signatures are public API across packages and do
not change. _Avoid:_ "the wrapper", "the alias", "the setter trio".

**Element collection** — `ToolbarItemCollection`, the ordered set of **elements** one **toolbar
item** contributes, together with their combined cacheability and the answer to whether any of them
is accessible. An item wrapper renders only when the collection is not empty. _Avoid:_ "item
elements", "element list".

**Badge** — the second plugin type `neo_toolbar` defines: a small count or marker an **element**
carries, supplied by a plugin rather than by the item's own settings. _Avoid:_ "counter", "pill".

**Toolbar test fixtures** — the hidden test module the characterisation suite installs:
settings-driven **toolbar item plugins** whose access answer each item declares for itself, one
that declares `region_create`, its own **toolbar regions**, a stand-in for the optional masquerade
service, and the concrete class that exposes the link trait's protected members. _Avoid:_ "the test
module", "fixtures" unqualified.

**Operations cacheability parameter** — the second parameter Drupal 11.3 added to a list builder's
`getDefaultOperations()` and `getOperations()`, `?CacheableMetadata $cacheability = NULL`, carried
through `func_get_args()` until Drupal 12 makes it formal. A list builder that omits it silently
drops the cacheability of the access checks its operation links depend on, and stops being
signature-compatible at Drupal 12. Both of this module's list builders omit it today. _Avoid:_
"the cacheability arg", "the 11.3 change".

**Storage at the call site** — the rule the module's two entity-storage findings ask for: a class
holds the entity type manager and asks it for a storage handler where the storage is used, rather
than injecting a storage handler or parking one on a property. The one exception is a list builder,
whose storage parameter is core's own constructor contract — there the fix is to stop overriding
the constructor at all. _Avoid:_ "storage injection", "lazy storage".

