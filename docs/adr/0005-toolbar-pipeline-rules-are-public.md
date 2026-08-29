# 0005 — The toolbar's four pipeline rules are public methods on a final service

**Status:** accepted
**Date:** 2026-08-24
**Context:** `neo_toolbar` — the **item pipeline**, the **toolbar repository**, and every rule the
pipeline gains from here on
**Plan:** `docs/plans/neo-toolbar-item-pipeline/`

## Decision

The **item pipeline** moves out of `Toolbar::getItems()` and onto the **toolbar repository** as
five methods, and **four of them are public**: the access filter, **region collapse**, **sibling
access** and the **triggering item** restore. Each takes an array of **toolbar items** and returns
the ones that survive it. Only the region grouping they share stays private.

`ToolbarRepository` is `final`. There is no interface. So a public method here is a permanent
promise: it cannot be reached by subclassing, it cannot be narrowed by a test double, and once a
site calls it, removing it is a breaking change for roughly thirty sites.

That is accepted deliberately. A **pipeline rule** is public *because* it is callable on its own.

## Why this is surprising

The Drupal idiom for a service's internal steps is `protected`, and the reason is good: a service's
public surface is its contract, and four methods that exist only because one public method calls
them in order are not a contract anybody asked for. A reviewer opening `ToolbarRepository` will find
`getToolbarItems()` — the method callers actually want — sitting beside four methods that read like
its private body, and the obvious question is why they are not private.

Because private would not have moved the problem. The candidate this plan came from argued that
extracting the pipeline "is what makes the region-collapse and sibling-access rules testable at
all", and that argument only holds if the extraction changes what a test has to build. It does not
change it if the rules stay reachable only through the entry point: today the four rules need a
loaded config entity with a populated item table to reach, and a private decomposition behind
`getToolbarItems()` needs a loaded config entity with a populated item table to reach. Same test,
same fixture, same cost — a tidier body and nothing else.

Public array-in/array-out rules are reachable with three hand-built **toolbar items** and no
toolbar. That is the whole difference between this refactor being worth doing and being cosmetic,
and no reader can see it from the class alone. That is what this ADR is for.

## What it costs

**Four methods that can never quietly become private.** If the pipeline is ever reshaped — a fifth
rule, a rule that needs two passes, a rule that folds into its neighbour — the old signatures have
to survive a deprecation cycle or break somebody. The mitigation is that the four names are the
four rules the module has had since the pipeline was written, and they are named for behaviour
(what survives the rule) rather than for mechanism (how it decides), so a reshape has room to
happen underneath them.

**A caller can compose the rules wrongly.** Nothing stops a site running **sibling access** before
the access filter and getting a different answer than the toolbar would. `getToolbarItems()` is the
composition that is correct, and it is the method the documentation points at; the rules are
documented as answering one question each, not as a kit.

## Alternatives considered

**Private rules, driven through `getToolbarItems()`.** Rejected above: it is the cheaper, more
idiomatic shape and it does not deliver the thing the extraction is for.

**A separate `ToolbarItemPipeline` service with public rules, leaving the repository alone.**
Rejected: it splits "which toolbar" from "which of its items" across two services that always
travel together, and it adds a service id thirty sites have to learn. The repository already
resolves the **active toolbar** and already calls the pipeline from
`getToolbarItemsOfType()` — it is where a reader looks first.

**An interface for the repository, with the rules on it.** Rejected as a bigger promise than this
plan can justify: an interface is what you publish when someone needs to substitute an
implementation, and nobody does. The kernel tests construct the real class. It stays available as a
later, additive step if a reason appears.

**Static rule methods.** Rejected: they need no state today, but a rule that has to answer for a
named account rather than the current user is the next thing anyone will ask for, and a static
method is the one shape that cannot grow that without becoming a parameter-passing exercise.

## Consequences

The account-threading question the `neo-toolbar-access-gate` plan deferred gets easier rather than
harder. That plan declined to thread an `$account` into the **toolbar access gate** because the
other half of `neo_toolbar_block_access()` runs through a **toolbar repository** with no account
parameter. After this decision the item-access half of that chain is a stateless public method with
no memo to key, so it takes an optional account for free. What remains is `getActive()`'s
toolbar-level access check and its unkeyed memo — one method, in one class, instead of a config
entity and a service.

A future rule joins the pipeline as a fifth public method and a line in `getToolbarItems()`, and it
arrives testable. That is the shape this decision buys.
