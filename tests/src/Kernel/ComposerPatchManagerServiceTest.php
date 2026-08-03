<?php

namespace Drupal\Tests\contribot\Kernel;

use Drupal\Core\StreamWrapper\PrivateStream;
use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;

/**
 * Kernel test for ComposerPatchManagerService.
 *
 * @group contribot
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class ComposerPatchManagerServiceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['contribot', 'system', 'file'];

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
    /** @var \Drupal\contribot\Service\ComposerPatchManagerService $manager */
    $manager = \Drupal::service('contribot.composer_patch_manager');

    $patchContent = "diff --git a/test.module b/test.module\n--- a/test.module\n+++ b/test.module\n@@ -1,2 +1,3 @@\n+// Test patch\n";

    $result = $manager->applyPatchViaComposer('focal_point', 'Test patch description', $patchContent);

    $this->assertArrayHasKey('success', $result);
    $this->assertArrayHasKey('patch_path', $result);
  }

}
