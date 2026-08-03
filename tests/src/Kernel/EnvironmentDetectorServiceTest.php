<?php

namespace Drupal\Tests\contribot\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Kernel test for EnvironmentDetectorService.
 *
 * @group contribot
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class EnvironmentDetectorServiceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['contribot'];

  /**
   * Tests production environment detection and mutation checks.
   */
  public function testEnvironmentAndMutationGuardrails(): void {
    /** @var \Drupal\contribot\Service\EnvironmentDetectorService $envDetector */
    $envDetector = \Drupal::service('contribot.environment_detector');

    $isProd = $envDetector->isProduction();
    $this->assertIsBool($isProd);

    $mutationCheck = $envDetector->isMutationAllowed(FALSE);
    $this->assertArrayHasKey('allowed', $mutationCheck);
    $this->assertArrayHasKey('reason', $mutationCheck);
  }

}
