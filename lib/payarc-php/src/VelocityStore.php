<?php

namespace Payarc;

/**
 * Where VelocityGuard keeps its counters: a WordPress transient, a CiviCRM
 * cache, or ArrayVelocityStore in tests. Values are small arrays of ints.
 *
 * Reads and writes need not be atomic. Two failures recorded at the same
 * moment may count as one, which only makes the limits slightly looser.
 */
interface VelocityStore {

  public function get(string $key): ?array;

  /**
   * @param int $ttl
   *   Seconds the value must be kept at least; it may be dropped after.
   */
  public function set(string $key, array $value, int $ttl): void;

  public function delete(string $key): void;

}
