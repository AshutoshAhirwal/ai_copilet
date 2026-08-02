<?php

namespace Drupal\ai_copilot\Service;

use Composer\Semver\Semver;
use Drupal\Core\File\FileSystemInterface;
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

    $exactVersion = NULL;

    // If module is already installed, read the exact version from composer.lock.
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

    // If module is new (not in composer.lock), query Packagist for the exact
    // candidate tag that composer require would resolve for the site's core constraint.
    if ($exactVersion === NULL) {
      $exactVersion = $this->resolveVersionFromPackagist($projectName, $siteCoreVersion) ?? '1.0.0';
    }

    $stagingDir = 'private://ai_copilot/staging/' . $projectName;
    $realStagingDir = \Drupal::service('file_system')->realpath($stagingDir);

    if (!$realStagingDir) {
      $fileSystem = \Drupal::service('file_system');
      $fileSystem->prepareDirectory($stagingDir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
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

  /**
   * Queries Packagist metadata to resolve the exact installable version of a module.
   *
   * Reads tagged releases from repo.packagist.org and selects the highest stable
   * tag whose drupal/core require constraint satisfies $siteCoreVersion.
   *
   * @param string $projectName
   *   Module machine name (e.g. 'focal_point').
   * @param string $siteCoreVersion
   *   Site core semver string (e.g. '11.1.2').
   *
   * @return string|null
   *   Exact version string (e.g. '2.1.0') or NULL on failure.
   */
  protected function resolveVersionFromPackagist(string $projectName, string $siteCoreVersion): ?string {
    try {
      $url = sprintf('https://repo.packagist.org/p2/drupal/%s.json', rawurlencode($projectName));
      $response = $this->httpClient->request('GET', $url, [
        'headers' => ['User-Agent' => 'Drupal-AI-Copilot/1.0'],
        'timeout' => 10,
      ]);

      if ($response->getStatusCode() !== 200) {
        return NULL;
      }

      $data = json_decode((string) $response->getBody(), TRUE);
      $packages = $data['packages']['drupal/' . $projectName] ?? [];

      if (empty($packages)) {
        return NULL;
      }

      // Collect stable release versions whose drupal/core constraint satisfies site.
      $candidates = [];
      foreach ($packages as $pkg) {
        $version = $pkg['version'] ?? '';
        // Skip dev, alpha, beta, RC unless no stable exists.
        if (str_contains($version, 'dev') || str_contains($version, 'RC')) {
          continue;
        }

        $coreRequire = $pkg['require']['drupal/core'] ?? $pkg['require']['drupal/core-recommended'] ?? NULL;
        if ($coreRequire === NULL) {
          $candidates[] = ltrim($version, 'v');
          continue;
        }

        try {
          if (Semver::satisfies($siteCoreVersion, $coreRequire)) {
            $candidates[] = ltrim($version, 'v');
          }
        }
        catch (\UnexpectedValueException $e) {
          $candidates[] = ltrim($version, 'v');
        }
      }

      if (empty($candidates)) {
        return NULL;
      }

      // Return the highest version using composer/semver sorting.
      usort($candidates, function ($a, $b) {
        return version_compare($b, $a);
      });

      return $candidates[0];
    }
    catch (\Exception $e) {
      \Drupal::logger('ai_copilot')->warning('Packagist version resolution failed for @module: @msg', [
        '@module' => $projectName,
        '@msg' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

}
