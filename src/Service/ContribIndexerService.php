<?php

namespace Drupal\contribot\Service;

use Drupal\Core\Database\Connection;
use GuzzleHttp\ClientInterface;

/**
 * Service for fetching and indexing drupal.org module metadata.
 */
class ContribIndexerService {

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Constructs a ContribIndexerService.
   */
  public function __construct(Connection $database, ClientInterface $httpClient) {
    $this->database = $database;
    $this->httpClient = $httpClient;
  }

  /**
   * Indexes top contrib modules from drupal.org API.
   *
   * @param int $limit
   *   Number of modules to ingest.
   *
   * @return int
   *   Number of modules indexed.
   */
  public function indexContribModules(int $limit = 50): int {
    $url = 'https://www.drupal.org/api-d7/node.json?type=project_module&sort=field_project_usage&direction=DESC&limit=' . $limit;

    try {
      $response = $this->httpClient->request('GET', $url, [
        'headers' => ['User-Agent' => 'Drupal-Contribot/1.0'],
        'timeout' => 15,
      ]);

      if ($response->getStatusCode() !== 200) {
        return 0;
      }

      $data = json_decode((string) $response->getBody(), TRUE);
      if (empty($data['list'])) {
        return 0;
      }

      $count = 0;
      foreach ($data['list'] as $item) {
        $projectName = strtolower(trim((string) ($item['field_project_machine_name'] ?? $item['title'] ?? '')));
        if (empty($projectName)) {
          continue;
        }

        $this->database->merge('contribot_contrib_index')
          ->key(['project_name' => $projectName])
          ->fields([
            'title' => substr((string) ($item['title'] ?? $projectName), 0, 255),
            'description' => (string) ($item['body']['value'] ?? $item['title'] ?? ''),
            'categories' => implode(',', (array) ($item['taxonomy_vocabulary_3'] ?? [])),
            'maintenance_status' => (string) ($item['field_project_maintenance_status']['name'] ?? 'Actively maintained'),
            'development_status' => (string) ($item['field_project_development_status']['name'] ?? 'Under active development'),
            'security_coverage' => !empty($item['field_security_advisories_covered']) ? 1 : 0,
            'usage_count' => (int) ($item['field_project_usage'] ?? 100),
            'core_compatibility' => '^10.3 || ^11.0',
            'last_release' => (int) ($item['changed'] ?? time()),
            'readme_summary' => substr((string) ($item['body']['value'] ?? ''), 0, 1000),
            'indexed_at' => time(),
          ])
          ->execute();

        $count++;
      }

      return $count;
    }
    catch (\Exception $e) {
      \Drupal::logger('contribot')->error('Contrib indexing failed: @msg', ['@msg' => $e->getMessage()]);
      return 0;
    }
  }

}
