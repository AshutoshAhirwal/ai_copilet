<?php

namespace Drupal\Tests\ai_copilot\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ai_copilot\Service\MutationLockManagerService;

/**
 * Kernel test for MutationLockManagerService.
 *
 * @group ai_copilot
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class MutationLockManagerServiceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['ai_copilot'];

  /**
   * Tests lock acquisition and release.
   */
  public function testAcquireAndReleaseLock(): void {
    /** @var \Drupal\ai_copilot\Service\MutationLockManagerService $lockManager */
    $lockManager = \Drupal::service('ai_copilot.mutation_lock_manager');

    $acquired = $lockManager->acquireLock(10.0);
    $this->assertTrue($acquired);

    $lockManager->releaseLock();
    $this->assertFalse($lockManager->isLocked());
  }

}
