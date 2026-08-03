<?php

namespace Drupal\Tests\contribot\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Kernel test for MutationLockManagerService.
 *
 * @group contribot
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class MutationLockManagerServiceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['contribot'];

  /**
   * Tests lock acquisition and release.
   */
  public function testAcquireAndReleaseLock(): void {
    /** @var \Drupal\contribot\Service\MutationLockManagerService $lockManager */
    $lockManager = \Drupal::service('contribot.mutation_lock_manager');

    $acquired = $lockManager->acquireLock(10.0);
    $this->assertTrue($acquired);

    $lockManager->releaseLock();
    $this->assertFalse($lockManager->isLocked());
  }

}
