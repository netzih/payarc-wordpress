<?php

namespace Payarc;

/**
 * VelocityStore for one request: tests, and callers with nowhere to persist.
 */
final class ArrayVelocityStore implements VelocityStore {

  private array $values = [];

  public function get(string $key): ?array {
    return $this->values[$key] ?? NULL;
  }

  public function set(string $key, array $value, int $ttl): void {
    $this->values[$key] = $value;
  }

  public function delete(string $key): void {
    unset($this->values[$key]);
  }

}
