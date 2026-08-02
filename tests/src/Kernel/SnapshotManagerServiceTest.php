<?php

namespace Drupal\Tests\ai_copilot\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ai_copilot\Service\SnapshotManagerService;

/**
 * Kernel test for SnapshotManagerService.
 *
 * @group ai_copilot
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class SnapshotManagerServiceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file', 'ai_copilot'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $privateDir = $this->siteDirectory . '/private';
    @mkdir($privateDir, 0777, TRUE);
    new \Drupal\Core\Site\Settings(array_merge(\Drupal\Core\Site\Settings::getAll(), [
      'file_private_path' => $privateDir,
    ]));
    if (!in_array('private', stream_get_wrappers(), TRUE)) {
      stream_wrapper_register('private', \Drupal\Core\StreamWrapper\PrivateStream::class);
    }
    $this->installSchema('ai_copilot', ['ai_copilot_audit_log']);
  }

  /**
   * Tests snapshot creation and revert functionality.
   */
  public function testCreateSnapshotAndRevert(): void {
    /** @var \Drupal\ai_copilot\Service\SnapshotManagerService $snapshotManager */
    $snapshotManager = \Drupal::service('ai_copilot.snapshot_manager');

    $auditId = 999;
    $snapshotPath = $snapshotManager->createSnapshot($auditId, []);
    $this->assertNotEmpty($snapshotPath);

    // Record audit log entry.
    $db = \Drupal::database();
    $db->insert('ai_copilot_audit_log')
      ->fields([
        'id' => $auditId,
        'uid' => 1,
        'timestamp' => time(),
        'requirement_prompt' => 'Test requirement',
        'chosen_path' => 'config_only',
        'affected_config_or_files' => json_encode(['installed_module' => '']),
        'diff_content' => 'test diff',
        'snapshot_path' => $snapshotPath,
        'status' => 'applied',
      ])
      ->execute();

    $revertResult = $snapshotManager->revertMutation($auditId);
    $this->assertTrue($revertResult['success']);
  }

}
