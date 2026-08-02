<?php

namespace Drupal\ai_copilot\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Service for recording AI Copilot audit logs.
 */
class AuditLoggerService {

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Current user account.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructs an AuditLoggerService.
   */
  public function __construct(Connection $database, AccountProxyInterface $currentUser) {
    $this->database = $database;
    $this->currentUser = $currentUser;
  }

  /**
   * Records a mutation action in the audit log.
   *
   * @param string $prompt
   *   The developer requirement prompt.
   * @param string $path
   *   Architectural path (config_only, contrib_patch, custom_code).
   * @param array $affected
   *   Affected files/config array.
   * @param string $diff
   *   Diff or YAML content.
   * @param string $snapshotPath
   *   Path to rollback snapshot.
   * @param string $status
   *   Status: applied, rejected, edited, reverted.
   *
   * @return int
   *   The audit log record ID.
   */
  public function logAction(
    string $prompt,
    string $path,
    array $affected = [],
    string $diff = '',
    string $snapshotPath = '',
    string $status = 'applied',
  ): int {
    return (int) $this->database->insert('ai_copilot_audit_log')
      ->fields([
        'uid' => (int) $this->currentUser->id(),
        'timestamp' => time(),
        'requirement_prompt' => $prompt,
        'chosen_path' => $path,
        'affected_config_or_files' => json_encode($affected),
        'diff_content' => $diff,
        'snapshot_path' => $snapshotPath,
        'status' => $status,
      ])
      ->execute();
  }

  /**
   * Retrieves audit log records.
   *
   * @param int $limit
   *   Number of records to fetch.
   *
   * @return array
   *   List of audit log records.
   */
  public function getAuditLogs(int $limit = 20): array {
    return $this->database->select('ai_copilot_audit_log', 'a')
      ->fields('a')
      ->orderBy('id', 'DESC')
      ->range(0, $limit)
      ->execute()
      ->fetchAll();
  }

}
