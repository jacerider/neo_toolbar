# 0006 — `neo_toolbar` requires Drupal 11.3, because its hooks live in classes

**Status:** accepted
**Date:** 2026-08-25
**Context:** `neo_toolbar` — the **core floor**, the **hook classes** its hooks move into, and the
Drupal 10 support the module has been declaring
**Plan:** `docs/plans/neo-toolbar-hook-classes/`

## Decision

`neo_toolbar` declares `core_version_requirement: ^10.3 || ^11` today, and the same range in its
package composer metadata. Both narrow to `^11.3`.

The module's six hooks move into **hook classes**, its page-top ordering becomes an attribute on the
page-top implementation, and its two `template_preprocess_*` functions become **initial preprocess**
callbacks named from the theme hook definitions. Attribute-based hook ordering arrived in Drupal
11.2 and **initial preprocess** in Drupal 11.3, both by core's own deprecation notices. After the
move the module genuinely requires 11.3, and the declaration is changed to say so.

No procedural fallback is kept. There is no legacy-hook wrapper beside any class method, and the
`.module` file ends up holding one thing: the **gate forwarder**.

## Why this is surprising

A module that gained no feature stops supporting a core version it has supported since it was
written. Nothing in the diff explains it — the classes read like the functions did, and a reader who
does not know that hook discovery is version-gated will see a compatibility range narrowed for
apparently no reason.

It is more surprising in context than out of it. `neo`, which `neo_toolbar` depends on, already ships
a hook class *and* still declares `^10.3 || ^11`, with no procedural fallback. So the stack's own
flagship has already made the opposite choice about the same mechanism, and this ADR does not follow
it.

The reason it does not is that the two cases are not alike. `neo`'s hook class holds one
`hook_form_alter` that adjusts a Views UI form; inert on Drupal 10, that degrades. `neo_toolbar`'s
`.module` holds the page-top hook that renders the toolbar, the block-access hook that hides the
blocks it replaces, and the theme registration for all eight of its theme hooks. Inert on Drupal 10,
that is not a degraded toolbar — it is no toolbar, and a template-not-found error the first time
anything tries to render one. A silent failure is acceptable when the failure is small. This one is
not small.

## What it costs

**A site on Drupal 10 cannot take the next release.** Composer will not resolve it and Drupal's
extension checks will call the module incompatible. That is the intended behaviour — a loud refusal
instead of a missing toolbar — but it is a refusal, and if such a site exists it finds out at update
time.

**The narrowing is published.** Once a tag carries the constraint it is in the package metadata for
that version permanently. Reversing the decision means more than editing two lines: it means writing
a procedural implementation of every hook back beside its class method, and reinstating two
preprocessor functions core has scheduled for removal.

**It is decided from one site's evidence.** This site runs 11.4.4. What the other roughly thirty
sites run is not visible from here. The decision rests on the composition — `neo_toolbar` depends on
`neo`, `neo`'s own hooks are already partly inert below 11.1, so a Drupal 10 site is already running
a stack that does not fully work — rather than on a survey.

## Alternatives considered

**Keep `^10.3 || ^11` and say nothing.** Rejected. It is what `neo` did, and for `neo` it is
survivable. Here it would mean shipping a module whose declared support produces no toolbar at all,
which is worse than the state before the plan, not better. A declaration a module cannot honour is
the thing this ADR exists to remove.

**Dual support through legacy-hook wrappers.** Core provides an attribute that marks a procedural
function so that it is skipped when the class-based implementation is available, and contrib modules
use it to span 10 and 11. Rejected because it keeps every function in the `.module` beside its class
method — the file stays 245 lines and grows — which is the entire friction this plan exists to
remove. It buys a compatibility no evidence supports, at the cost of the change being pointless.

**Move the hooks but keep the two preprocessors procedural**, holding the floor at 11.2. Rejected:
those two functions are the ones core has actually deprecated with a removal date, so keeping them is
keeping the part of the file with a deadline on it. Half a version of headroom is not worth carrying
a Drupal 12 removal forward.

**Move only the hooks that are safe below 11.1 — none of them.** There is no such subset. Hook
discovery is all or nothing per implementation, and every hook in this file is load-bearing.

## Consequences

The module's declaration and its code agree, which they did not before this plan and did not before
`neo` grew a hook class either.

The next Neo package to convert its `.module` — `neo`'s own 490 lines are the obvious candidate —
inherits an argued position rather than re-deriving one, and inherits the distinction that makes it:
convert freely when a silently inert hook degrades, raise the floor when it does not.

Anything the module adds from here may use Drupal 11.3 mechanisms without a second conversation. In
particular the remaining procedural weight in the package — the **gate forwarder**, and whatever a
future plan does with it — is no longer constrained by a Drupal 10 that the module no longer claims.
