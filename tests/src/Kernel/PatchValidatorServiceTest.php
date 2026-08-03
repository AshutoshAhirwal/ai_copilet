<?php

namespace Drupal\Tests\ai_copilot\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Kernel test for PatchValidatorService.
 *
 * @group ai_copilot
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class PatchValidatorServiceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['ai_copilot'];

  /**
   * Tests patch validation status results.
   */
  public function testValidatePatchDryRun(): void {
    /** @var \Drupal\ai_copilot\Service\PatchValidatorService $validator */
    $validator = \Drupal::service('ai_copilot.patch_validator');

    $tempDir = sys_get_temp_dir();
    $patchContent = "diff --git a/test.txt b/test.txt\n--- a/test.txt\n+++ b/test.txt\n@@ -0,0 +1 @@\n+Hello World\n";

    $result = $validator->validatePatch($patchContent, $tempDir);

    $this->assertArrayHasKey('valid', $result);
    $this->assertArrayHasKey('status_label', $result);
    $this->assertNotEmpty($result['status_label']);
  }

}
