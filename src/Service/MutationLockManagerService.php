<?php

namespace Drupal\contribot\Service;

use Drupal\Core\Lock\LockBackendInterface;

/**
 * Service for acquiring mutation locks via Drupal Lock API to prevent race conditions.
 */
class MutationLockManagerService {

  const LOCK_KEY = 'contribot_mutation_lock';

  /**
   * Lock backend service.
   *
   * @var \Drupal\Core\Lock\LockBackendInterface
   */
  protected $lock;

  /**
   * Constructs a MutationLockManagerService.
   */
  public function __construct(LockBackendInterface $lock) {
    $this->lock = $lock;
  }

  /**
   * Acquires the mutation lock.
   *
   * @param float $timeout
   *   Lock timeout in seconds.
   *
   * @return bool
   *   TRUE if acquired, FALSE if another process holds the lock.
   */
  public function acquireLock(float $timeout = 30.0): bool {
    return $this->lock->acquire(self::LOCK_KEY, $timeout);
  }

  /**
   * Releases the mutation lock.
   */
  public function releaseLock(): void {
    $this->lock->release(self::LOCK_KEY);
  }

  /**
   * Checks if the mutation lock is currently locked.
   *
   * @return bool
   *   TRUE if locked, FALSE otherwise.
   */
  public function isLocked(): bool {
    return !$this->lock->lockMayBeAvailable(self::LOCK_KEY);
  }

}
