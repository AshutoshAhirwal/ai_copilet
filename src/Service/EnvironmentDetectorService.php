<?php

namespace Drupal\contribot\Service;

use Drupal\Core\Site\Settings;

/**
 * Service for environment detection, filesystem writeability, and safety guardrails.
 */
class EnvironmentDetectorService {

  /**
   * Checks whether the current site environment is flagged as production.
   *
   * @return bool
   *   TRUE if production, FALSE otherwise.
   */
  public function isProduction(): bool {
    // Explicit setting override takes precedence.
    if (Settings::get('contribot_production_mode') === TRUE) {
      return TRUE;
    }

    $request = \Drupal::hasRequest() ? \Drupal::request() : NULL;
    $host = $request ? $request->getHost() : '';

    // Local / DDEV / Staging host patterns are NEVER production.
    if (preg_match('/(\.ddev\.site|localhost|127\.0\.0\.1|\.local|\.test|\.dev)$/i', $host)) {
      return FALSE;
    }

    $env = Settings::get('environment', '');
    if (empty($env)) {
      $env = getenv('DRUPAL_ENVIRONMENT') ?: getenv('APP_ENV') ?: '';
    }

    $env = strtolower(trim((string) $env));
    return in_array($env, ['production', 'prod', 'live'], TRUE);
  }

  /**
   * Checks if the Drupal site filesystem (root and private stream) is writable.
   *
   * @return array
   *   Array with 'writable' (bool) and 'errors' (array of string messages).
   */
  public function checkFilesystemWritable(): array {
    $errors = [];
    $appRoot = \Drupal::hasService('kernel') ? \Drupal::root() : DRUPAL_ROOT;

    if (!is_writable($appRoot)) {
      $errors[] = sprintf('The codebase root directory (%s) is read-only.', $appRoot);
    }

    $customModuleDir = $appRoot . '/modules/custom';
    if (file_exists($customModuleDir) && !is_writable($customModuleDir)) {
      $errors[] = sprintf('The custom modules directory (%s) is read-only.', $customModuleDir);
    }

    return [
      'writable' => empty($errors),
      'errors' => $errors,
    ];
  }

  /**
   * Determines if mutations (applying patches, config changes, or code) are allowed.
   *
   * @param bool $allowProductionMutationSetting
   *   The module config setting for allowing production mutations.
   *
   * @return array
   *   Array with 'allowed' (bool) and 'reason' (string message if disallowed).
   */
  public function isMutationAllowed(bool $allowProductionMutationSetting = FALSE): array {
    $fsCheck = $this->checkFilesystemWritable();
    if (!$fsCheck['writable']) {
      return [
        'allowed' => FALSE,
        'reason' => 'Codebase filesystem is read-only: ' . implode(' ', $fsCheck['errors']),
      ];
    }

    if ($this->isProduction() && !$allowProductionMutationSetting) {
      return [
        'allowed' => FALSE,
        'reason' => 'Site mutations are hard-disabled in Production environments. Override requires explicit setting + admin confirmation.',
      ];
    }

    return [
      'allowed' => TRUE,
      'reason' => 'Mutations allowed.',
    ];
  }

}
