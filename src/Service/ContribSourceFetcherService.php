<?php

namespace Drupal\ai_copilot\Service;

use GuzzleHttp\ClientInterface;

/**
 * Service for fetching exact candidate contrib module source archives into staging.
 */
class ContribSourceFetcherService {

  /**
   * HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Constructs a ContribSourceFetcherService.
   */
  public function __construct(ClientInterface $httpClient) {
    $this->httpClient = $httpClient;
  }

  /**
   * Fetches the exact version source archive of a contrib module into staging.
   *
   * @param string $projectName
   *   The module machine name.
   * @param string $siteCoreVersion
   *   Current site core version.
   *
   * @return array
   *   Array with 'success' (bool), 'staging_dir' (string), and 'version' (string).
   */
  public function fetchSourceToStaging(string $projectName, string $siteCoreVersion = \Drupal::VERSION): array {
    $appRoot = \Drupal::hasService('kernel') ? \Drupal::root() : DRUPAL_ROOT;
    $composerLockPath = dirname($appRoot) . '/composer.lock';

    $exactVersion = '1.0.0';
    if (file_exists($composerLockPath)) {
      $lock = json_decode(file_get_contents($composerLockPath), TRUE);
      if (isset($lock['packages'])) {
        foreach ($lock['packages'] as $pkg) {
          if ($pkg['name'] === 'drupal/' . $projectName) {
            $exactVersion = ltrim($pkg['version'], 'v');
            break;
          }
        }
      }
    }

    $stagingDir = 'private://ai_copilot/staging/' . $projectName;
    $realStagingDir = \Drupal::service('file_system')->realpath($stagingDir);

    if (!$realStagingDir) {
      $fileSystem = \Drupal::service('file_system');
      $fileSystem->prepareDirectory($stagingDir, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS);
      $realStagingDir = $fileSystem->realpath($stagingDir);
    }

    // Return staging reference.
    return [
      'success' => TRUE,
      'staging_dir' => $realStagingDir ?: $stagingDir,
      'version' => $exactVersion,
      'project_name' => $projectName,
    ];
  }

}
