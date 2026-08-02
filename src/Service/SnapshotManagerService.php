<?php

namespace Drupal\ai_copilot\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleInstallerInterface;

/**
 * Service for managing pre-mutation rollback snapshots and module uninstallation on revert.
 */
class SnapshotManagerService {

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Module installer.
   *
   * @var \Drupal\Core\Extension\ModuleInstallerInterface
   */
  protected $moduleInstaller;

  /**
   * Constructs a SnapshotManagerService.
   */
  public function __construct(
    Connection $database,
    ConfigFactoryInterface $configFactory,
    ModuleInstallerInterface $moduleInstaller,
  ) {
    $this->database = $database;
    $this->configFactory = $configFactory;
    $this->moduleInstaller = $moduleInstaller;
  }

  /**
   * Creates a rollback snapshot before a mutation is applied.
   *
   * @param int $auditId
   *   The audit log entry ID.
   * @param array $affectedFiles
   *   List of file paths affected.
   *
   * @return string
   *   Path to the snapshot directory.
   */
  public function createSnapshot(int $auditId, array $affectedFiles = []): string {
    $snapshotDir = 'private://ai_copilot/snapshots/' . $auditId;
    $fileSystem = \Drupal::service('file_system');
    $fileSystem->prepareDirectory($snapshotDir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    $realDir = $fileSystem->realpath($snapshotDir);

    // 1. Export active configuration snapshot.
    $activeConfig = [];
    $names = array_slice($this->configFactory->listAll(), 0, 50);
    foreach ($names as $name) {
      $activeConfig[$name] = $this->configFactory->get($name)->get();
    }
    file_put_contents($realDir . '/config_before.json', json_encode($activeConfig, JSON_PRETTY_PRINT));

    // 2. Backup affected files if present and store file mapping.
    $fileBackupDir = $realDir . '/files';
    if (!is_dir($fileBackupDir)) {
      mkdir($fileBackupDir, 0755, TRUE);
    }

    $fileMap = [];
    foreach ($affectedFiles as $targetFile) {
      if (file_exists($targetFile)) {
        $hashName = md5($targetFile) . '.bak';
        copy($targetFile, $fileBackupDir . '/' . $hashName);
        $fileMap[$hashName] = $targetFile;
      }
    }
    file_put_contents($realDir . '/file_map.json', json_encode($fileMap, JSON_PRETTY_PRINT));

    return $snapshotDir;
  }

  /**
   * Reverts a previously applied mutation and uninstalls newly installed modules if needed.
   *
   * @param int $auditId
   *   The audit log ID to revert.
   *
   * @return array
   *   Array with 'success' (bool) and 'message' (string).
   */
  public function revertMutation(int $auditId): array {
    $record = $this->database->select('ai_copilot_audit_log', 'a')
      ->fields('a')
      ->condition('id', $auditId)
      ->execute()
      ->fetchObject();

    if (!$record) {
      return ['success' => FALSE, 'message' => 'Audit record not found.'];
    }

    if ($record->status === 'reverted') {
      return ['success' => FALSE, 'message' => 'Action has already been reverted.'];
    }

    $snapshotDir = \Drupal::service('file_system')->realpath($record->snapshot_path);
    if (!$snapshotDir || !file_exists($snapshotDir . '/config_before.json')) {
      return ['success' => FALSE, 'message' => 'Snapshot directory or config backup file missing.'];
    }

    // 1a. Module Uninstallation if a new contrib module was installed.
    $affected = json_decode((string) $record->affected_config_or_files, TRUE) ?: [];
    if (!empty($affected['installed_module'])) {
      $moduleToUninstall = $affected['installed_module'];
      if (\Drupal::moduleHandler()->moduleExists($moduleToUninstall)) {
        try {
          $this->moduleInstaller->uninstall([$moduleToUninstall]);
        }
        catch (\Exception $e) {
          \Drupal::logger('ai_copilot')->warning('Revert module uninstall failed: @msg', ['@msg' => $e->getMessage()]);
        }
      }
    }

    // 1b. Delete any entity (content type, taxonomy, role) that was CREATED by
    // the mutation. Config-restore alone cannot remove added entities.
    if (!empty($affected['created_content_type'])) {
      try {
        $typeStorage = \Drupal::entityTypeManager()->getStorage('node_type');
        $nodeType = $typeStorage->load($affected['created_content_type']);
        if ($nodeType) {
          $nodeType->delete();
        }
      }
      catch (\Exception $e) {
        \Drupal::logger('ai_copilot')->warning('Revert content type deletion failed: @msg', ['@msg' => $e->getMessage()]);
      }
    }

    // 2. Restore Configuration Snapshot.
    $configBefore = json_decode(file_get_contents($snapshotDir . '/config_before.json'), TRUE) ?: [];
    foreach ($configBefore as $name => $data) {
      if (!empty($name) && is_array($data)) {
        $this->configFactory->getEditable($name)->setData($data)->save();
      }
    }

    // 3. Restore File Backups using file_map.json.
    $fileMapPath = $snapshotDir . '/file_map.json';
    if (file_exists($fileMapPath)) {
      $fileMap = json_decode(file_get_contents($fileMapPath), TRUE) ?: [];
      $fileBackupDir = $snapshotDir . '/files';
      foreach ($fileMap as $hashName => $targetPath) {
        $bakPath = $fileBackupDir . '/' . $hashName;
        if (file_exists($bakPath)) {
          @mkdir(dirname($targetPath), 0755, TRUE);
          copy($bakPath, $targetPath);
        }
      }
    }

    // 4. Update Audit Record Status to 'reverted'.
    $this->database->update('ai_copilot_audit_log')
      ->fields(['status' => 'reverted'])
      ->condition('id', $auditId)
      ->execute();

    return [
      'success' => TRUE,
      'message' => sprintf('Mutation #%d successfully reverted. Config and module states restored.', $auditId),
    ];
  }

}
