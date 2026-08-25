<?php

declare(strict_types=1);

namespace Drupal\neo_toolbar_test;

/**
 * A stand-in for the `masquerade` service.
 *
 * The gate's last exit calls `isMasquerading()` on whatever was injected as its
 * optional collaborator, which is typed as a plain nullable object because the
 * `masquerade` module is not installed on this site — that is also where the
 * module's `class.notFound` phpstan findings come from — so the real class does
 * not exist to mock. Having the one method the gate calls is the whole of what
 * makes this a complete collaborator, and it is the only way to reach the
 * gate's one non-deterministic exit.
 *
 * Its answer is settable after construction so that a test can prove the gate
 * memo answered rather than the collaborator: the gate holds one instance for
 * its lifetime, so replacing the object would prove nothing about the memo.
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

  /**
   * Changes the answer this stand-in gives.
   *
   * @param bool $masquerading
   *   The answer to give from now on.
   */
  public function setMasquerading(bool $masquerading): void {
    $this->masquerading = $masquerading;
  }

}
