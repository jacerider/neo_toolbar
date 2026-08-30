# 0005 — The toolbar's four pipeline rules are public methods on a final service

**Status:** accepted · **Date:** 2026-08-24
**Context:** `neo_toolbar` — the **item pipeline**, **pipeline rules** and **toolbar repository**
**Issue:** jacerider/neo_toolbar#4

**Decision.** The **item pipeline** moves from `Toolbar::getItems()` to the **toolbar repository**
as five methods, four of them public — the access filter, **region collapse**, **sibling access**
and the **triggering item** restore — each taking an array of **toolbar items** and returning the
ones that survive it; only their shared region grouping stays private. `ToolbarRepository` is
`final` with no interface, so each public rule is a permanent promise to every installing site. That
is deliberate: a **pipeline rule** is public *because* it is callable on its own, and a fifth rule
joins as a fifth public method plus one line in `getToolbarItems()`, arriving testable.

**Why it needs recording.** The Drupal idiom for a service's internal steps is `protected`, and a
reader will find `getToolbarItems()` beside four methods that read like its private body. Private
would not have moved the problem: the extraction was argued to be "what makes the region-collapse
and sibling-access rules testable at all", which holds only if it changes what a test has to build,
and a private decomposition behind `getToolbarItems()` still needs a loaded config entity with a
populated item table — same test, same fixture, same cost. Public array-in/array-out rules need
three hand-built **toolbar items** and no toolbar; that is the whole difference between worth doing
and cosmetic, and the class alone cannot show it. It also lets `neo_toolbar_block_access()`'s
item-access half take an optional account for free — stateless, no memo to key — which the
access-gate plan deferred, leaving only `getActive()`'s toolbar-level check and unkeyed memo.

**Rejected.**
- Private rules behind `getToolbarItems()` — cheaper, idiomatic, not what the extraction is for.
- A separate `ToolbarItemPipeline` service — splits "which toolbar" from "which of its items", two
  things that always travel together, and adds a service id every site must learn; the repository
  already calls the pipeline from `getToolbarItemsOfType()` and is where a reader looks first.
- An interface on the repository — a bigger promise than justified: nobody substitutes an
  implementation and the kernel tests construct the real class. Stays open as a later additive step.
- Static rule methods — cannot grow the named account asked for next without parameter passing.

**Cost.** Four methods that can never quietly become private: a reshaped pipeline (a fifth rule, a
two-pass rule, a fold into a neighbour) means a deprecation cycle or a break. The names are the
module's four original rules, named for what survives rather than how, so a reshape has room
underneath. A caller can also compose the rules wrongly — **sibling access** before the access
filter answers differently than the toolbar — so `getToolbarItems()` is the documented composition
and the rules answer one question each, not a kit.
