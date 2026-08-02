<?php

namespace Drupal\ai_copilot\Service;

use Drupal\Core\Queue\QueueFactory;

/**
 * Service for registering patches in root composer.json and queuing background composer runs.
 */
class ComposerPatchManagerService {

  /**
   * Queue factory service.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * Constructs a ComposerPatchManagerService.
   */
  public function __construct(QueueFactory $queueFactory) {
    $this->queueFactory = $queueFactory;
  }

  /**
   * Registers a patch in root composer.json and enqueues background composer execution.
   *
   * @param string $projectName
   *   Machine name of the module.
   * @param string $patchDescription
   *   Short description of the patch.
   * @param string $patchContent
   *   The patch unified diff content.
   *
   * @return array
   *   Array with 'success' (bool), 'patch_path' (string), and 'message' (string).
   */
  public function applyPatchViaComposer(string $projectName, string $patchDescription, string $patchContent): array {
    $appRoot = \Drupal::hasService('kernel') ? \Drupal::root() : DRUPAL_ROOT;
    $rootDir = dirname($appRoot);
    $rootComposerJson = $rootDir . '/composer.json';

    if (!is_writable($rootDir) || (file_exists($rootComposerJson) && !is_writable($rootComposerJson))) {
      return [
        'success' => FALSE,
        'patch_path' => '',
        'message' => 'Codebase root directory or composer.json is read-only. Cannot register patch.',
      ];
    }

    $patchDir = 'private://ai_copilot/patches';
    $fileSystem = \Drupal::service('file_system');
    $fileSystem->prepareDirectory($patchDir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS);

    $fileName = sprintf('%s-%s.patch', $projectName, substr(md5($patchContent), 0, 8));
    $patchUri = $patchDir . '/' . $fileName;
    file_put_contents(\Drupal::service('file_system')->realpath($patchUri) ?: $patchUri, $patchContent);

    // Update root composer.json extra.patches section.
    if (file_exists($rootComposerJson)) {
      $composerData = json_decode(file_get_contents($rootComposerJson), TRUE) ?: [];
      $packageKey = 'drupal/' . $projectName;
      $relativePatchPath = 'private/ai_copilot/patches/' . $fileName;

      if (!isset($composerData['extra'])) {
        $composerData['extra'] = [];
      }
      if (!isset($composerData['extra']['patches'])) {
        $composerData['extra']['patches'] = [];
      }
      if (!isset($composerData['extra']['patches'][$packageKey])) {
        $composerData['extra']['patches'][$packageKey] = [];
      }

      $composerData['extra']['patches'][$packageKey][$patchDescription] = $relativePatchPath;

      file_put_contents($rootComposerJson, json_encode($composerData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    // Enqueue background composer update job via Queue API to avoid HTTP request timeouts.
    $queue = $this->queueFactory->get('ai_copilot_composer_queue');
    $queue->createItem([
      'action' => 'composer_update',
      'package' => 'drupal/' . $projectName,
      'patch_uri' => $patchUri,
      'timestamp' => time(),
    ]);

    return [
      'success' => TRUE,
      'patch_path' => $patchUri,
      'message' => 'Patch saved and registered in root composer.json extra.patches. Background composer update queued.',
    ];
  }

}
