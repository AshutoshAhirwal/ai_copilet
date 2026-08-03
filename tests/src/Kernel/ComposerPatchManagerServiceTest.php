<?php

namespace Drupal\Tests\ai_copilot\Kernel;

use Drupal\Core\StreamWrapper\PrivateStream;
use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;

/**
 * Kernel test for ComposerPatchManagerService.
 *
 * @group ai_copilot
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class ComposerPatchManagerServiceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['ai_copilot', 'system', 'file'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $privateDir = $this->siteDirectory . '/private';
    @mkdir($privateDir, 0777, TRUE);
    new Settings(array_merge(Settings::getAll(), [
      'file_private_path' => $privateDir,
    ]));
    if (!in_array('private', stream_get_wrappers(), TRUE)) {
      stream_wrapper_register('private', PrivateStream::class);
    }
  }

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
