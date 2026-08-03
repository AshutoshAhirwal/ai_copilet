<?php

namespace Drupal\Tests\contribot\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Kernel test for SnapshotManagerService.
 *
 * @group contribot
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class SnapshotManagerServiceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'file', 'contribot'];

  /**
   * Registers the 'private' stream wrapper at container-build time.
   *
   * Matches core's own FileTestBase pattern. Setting file_private_path via
   * Settings after the container is built is timing-sensitive: whether the
   * compiler pass that tags stream wrappers already ran determines whether
   * private:// resolves at all, which caused an intermittent test failure.
   *
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    $container->register('stream_wrapper.private', 'Drupal\Core\StreamWrapper\PrivateStream')
      ->addTag('stream_wrapper', ['scheme' => 'private']);
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $privateDir = $this->siteDirectory . '/private';
    @mkdir($privateDir, 0775, TRUE);
    $this->setSetting('file_private_path', $privateDir);
    $this->installSchema('contribot', ['contribot_audit_log']);
  }

  /**
   * Tests snapshot creation and revert functionality.
   */
  public function testCreateSnapshotAndRevert(): void {
    /** @var \Drupal\contribot\Service\SnapshotManagerService $snapshotManager */
    $snapshotManager = \Drupal::service('contribot.snapshot_manager');

    $auditId = 999;
    $snapshotPath = $snapshotManager->createSnapshot($auditId, []);
    $this->assertNotEmpty($snapshotPath);

    // Record audit log entry.
    $db = \Drupal::database();
    $db->insert('contribot_audit_log')
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

  /**
   * Tests that snapshot capture and restore work with 100+ config objects.
   *
   * Regression test for a previous array_slice(..., 0, 50) cap in
   * createSnapshot() that silently dropped config objects beyond the 50th.
   * Uses real, schema-covered user.role.* config entities (rather than
   * disabling strict config schema checking) so the test validates against
   * an actual Drupal config schema throughout.
   */
  public function testSnapshotCapturesAndRestoresMoreThanFiftyConfigObjects(): void {
    $configFactory = \Drupal::configFactory();

    // Create 120 dummy role config objects - well beyond the old 50-item cap.
    $total = 120;
    for ($i = 0; $i < $total; $i++) {
      $configFactory->getEditable('user.role.dummy_' . $i)
        ->set('id', 'dummy_' . $i)
        ->set('label', 'Original Label ' . $i)
        ->set('weight', $i)
        ->set('is_admin', FALSE)
        ->set('permissions', [])
        ->set('langcode', 'en')
        ->set('status', TRUE)
        ->set('dependencies', [])
        ->save();
    }

    $namesBefore = $configFactory->listAll('user.role.dummy_');
    $this->assertCount($total, $namesBefore, 'All dummy role config objects exist before snapshot.');

    /** @var \Drupal\contribot\Service\SnapshotManagerService $snapshotManager */
    $snapshotManager = \Drupal::service('contribot.snapshot_manager');

    $auditId = 998;
    $snapshotPath = $snapshotManager->createSnapshot($auditId, []);
    $this->assertNotEmpty($snapshotPath);

    $db = \Drupal::database();
    $db->insert('contribot_audit_log')
      ->fields([
        'id' => $auditId,
        'uid' => 1,
        'timestamp' => time(),
        'requirement_prompt' => 'Test 100+ config snapshot',
        'chosen_path' => 'config_only',
        'affected_config_or_files' => json_encode(['installed_module' => '']),
        'diff_content' => 'test diff',
        'snapshot_path' => $snapshotPath,
        'status' => 'applied',
      ])
      ->execute();

    // Mutate every dummy config object after the snapshot was taken.
    for ($i = 0; $i < $total; $i++) {
      $configFactory->getEditable('user.role.dummy_' . $i)
        ->set('label', 'Mutated Label ' . $i)
        ->save();
    }

    $revertResult = $snapshotManager->revertMutation($auditId);
    $this->assertTrue($revertResult['success']);

    // Every single one of the 120 objects must be restored to its original
    // value - none silently skipped.
    for ($i = 0; $i < $total; $i++) {
      $restored = $configFactory->get('user.role.dummy_' . $i)->get('label');
      $this->assertSame('Original Label ' . $i, $restored, 'Role dummy_' . $i . ' was fully restored.');
    }
  }

}
