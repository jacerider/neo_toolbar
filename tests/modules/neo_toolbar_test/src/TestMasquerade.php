<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar_test;

/**
 * A stand-in for the `masquerade` service.
 *
 * `neo_toolbar_toolbar_view_access()`'s last exit asks
 * `\Drupal::hasService('masquerade')` and then calls `isMasquerading()` on it.
 * The `masquerade` module is not installed on this site — that is also where
 * the module's two `class.notFound` phpstan findings come from — so the real
 * class does not exist to mock. A test registers this into the container under
 * the `masquerade` id instead, which is the only way to reach the gate's one
 * non-deterministic exit.
 */
final class TestMasquerade {

  /**
   * Constructs a TestMasquerade.
   *
   * @param bool $masquerading
   *   The answer this stand-in gives.
   */
  public function __construct(
    protected bool $masquerading = FALSE,
  ) {}

  /**
   * Whether the current session is masquerading.
   *
   * @return bool
   *   TRUE if masquerading.
   */
  public function isMasquerading(): bool {
    return $this->masquerading;
  }

}
