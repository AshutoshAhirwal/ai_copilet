<?php

namespace Drupal\Tests\ai_copilot\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ai_copilot\Service\ComposerPatchManagerService;

/**
 * Kernel test for ComposerPatchManagerService.
 *
 * @group ai_copilot
 */
class ComposerPatchManagerServiceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['ai_copilot'];

  /**
   * Tests patch queuing and registration.
   */
  public function testApplyPatchViaComposer(): void {
    /** @var \Drupal\ai_copilot\Service\ComposerPatchManagerService $manager */
    $manager = \Drupal::service('ai_copilot.composer_patch_manager');

    $patchContent = "diff --git a/test.module b/test.module\n--- a/test.module\n+++ b/test.module\n@@ -1,2 +1,3 @@\n+// Test patch\n";

    $result = $manager->applyPatchViaComposer('focal_point', 'Test patch description', $patchContent);

    $this->assertArrayHasKey('success', $result);
    $this->assertArrayHasKey('patch_path', $result);
  }

}
