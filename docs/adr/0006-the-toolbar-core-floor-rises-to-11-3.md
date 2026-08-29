# 0006 — `neo_toolbar` requires Drupal 11.3, because its hooks live in classes

**Status:** accepted · **Date:** 2026-08-25
**Context:** `neo_toolbar` — the **core floor** and the **hook classes** its hooks move into
**Issue:** jacerider/neo_toolbar#8

**Decision.** `neo_toolbar` declares `core_version_requirement: ^10.3 || ^11` (its composer
metadata agrees); both narrow to `^11.3`. Its six hooks move into **hook classes**, page-top
ordering becomes an attribute on it, and its two `template_preprocess_*` functions become **initial
preprocess** callbacks named from the theme hook definitions; attribute ordering arrived in 11.2
and initial preprocess in 11.3, per core's deprecation notices. No procedural fallback, no
legacy-hook wrapper beside any class method; the `.module` holds one thing: the **gate forwarder**.

**Why it needs recording.** A module that gained no feature drops a core version it always
supported, and the diff does not explain it — the classes read like the functions did. And `neo`,
a dependency of `neo_toolbar`, ships a hook class yet still declares `^10.3 || ^11` with no
fallback; this ADR diverges: the cases differ. `neo`'s hook class holds one `hook_form_alter` on a
Views UI form: inert on Drupal 10, it degrades. `neo_toolbar`'s `.module` holds the page-top hook
that renders the toolbar, the block-access hook hiding blocks it replaces, and the theme
registration for all eight theme hooks: inert, that is no toolbar and a template-not-found error on
render. A silent failure is fine when small, not here. `neo`'s 490-line conversion inherits the
rule: convert freely where an inert hook degrades, raise the floor where it does not.

**Rejected.**
- Keep `^10.3 || ^11` and say nothing — what `neo` did and survivable there; here declared support
  would produce no toolbar at all, worse than the state before the plan.
- Dual support via legacy-hook wrappers — core's attribute skips a procedural function when the
  class one exists, so every function stays in the `.module` beside its class method: the file
  stays 245 lines and grows, the friction this plan removes, for a compatibility no evidence backs.
- Move the hooks but keep the two preprocessors procedural, floor at 11.2 — those two are the
  functions core actually deprecated with a removal date; half a version of headroom is not worth
  carrying a Drupal 12 removal forward.
- Move only hooks safe below 11.1 — no such subset: discovery is all-or-nothing, all load-bearing.

**Cost.** A Drupal 10 site cannot take the next release — Composer refuses it — a loud refusal
instead of a missing toolbar, met at update time. The narrowing is published: a tag carries it
permanently, and reversing it means every hook written procedurally again beside its class method
and two preprocessors core scheduled for removal reinstated. It rests on one site's evidence (this
one runs 11.4.4; the other roughly thirty are unseen) and on composition, not a survey: `neo`'s
hooks are already partly inert below 11.1, so a Drupal 10 site's stack already does not fully work.
