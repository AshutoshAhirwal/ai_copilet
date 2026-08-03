<?php

namespace Drupal\Tests\ai_copilot\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Kernel test for EnvironmentDetectorService.
 *
 * @group ai_copilot
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class EnvironmentDetectorServiceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['ai_copilot'];

  /**
   * Tests production environment detection and mutation checks.
   */
  public function testEnvironmentAndMutationGuardrails(): void {
    /** @var \Drupal\ai_copilot\Service\EnvironmentDetectorService $envDetector */
    $envDetector = \Drupal::service('ai_copilot.environment_detector');

    $isProd = $envDetector->isProduction();
    $this->assertIsBool($isProd);

    $mutationCheck = $envDetector->isMutationAllowed(FALSE);
    $this->assertArrayHasKey('allowed', $mutationCheck);
    $this->assertArrayHasKey('reason', $mutationCheck);
  }

}
