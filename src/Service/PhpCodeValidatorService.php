<?php

namespace Drupal\ai_copilot\Service;

use Symfony\Component\Process\Process;

/**
 * Service for syntax (php -l) and coding standards (phpcs) validation of custom code proposals.
 */
class PhpCodeValidatorService {

  /**
   * Validates custom PHP code snippet.
   *
   * @param string $phpCode
   *   The PHP code string to validate.
   *
   * @return array
   *   Array with 'valid' (bool), 'status_label' (string), and 'errors' (array of string messages).
   */
  public function validateCustomCode(string $phpCode): array {
    $tempFile = tempnam(sys_get_temp_dir(), 'copilot_custom_') . '.php';
    $fileHeader = "<?php\n\n/**\n * @file\n * AI Copilot generated code snippet.\n */\n\n";
    $body = rtrim(ltrim(ltrim($phpCode, "<?php"), "\n")) . "\n";
    file_put_contents($tempFile, $fileHeader . $body);

    $errors = [];

    // 1. PHP Syntax Lint Check (php -l).
    try {
      $lintProcess = new Process(['php', '-l', $tempFile]);
      $lintProcess->run();
      if (!$lintProcess->isSuccessful()) {
        $errors[] = 'PHP Syntax Error: ' . trim($lintProcess->getErrorOutput() ?: $lintProcess->getOutput());
      }
    }
    catch (\Exception $e) {
      $errors[] = 'Syntax check skipped: ' . $e->getMessage();
    }

    // 2. Drupal Coding Standards Check (phpcs --standard=Drupal,DrupalPractice).
    $appRoot = \Drupal::hasService('kernel') ? \Drupal::root() : DRUPAL_ROOT;
    $phpcsPath = dirname($appRoot) . '/vendor/bin/phpcs';

    if (empty($errors) && file_exists($phpcsPath)) {
      try {
        $phpcsProcess = new Process([
          $phpcsPath,
          '--standard=Drupal,DrupalPractice',
          '--report=json',
          $tempFile,
        ]);
        $phpcsProcess->run();

        if (!$phpcsProcess->isSuccessful()) {
          $phpcsRaw = trim($phpcsProcess->getOutput() ?: $phpcsProcess->getErrorOutput());
          $phpcsData = json_decode($phpcsRaw, TRUE);

          if (isset($phpcsData['files']) && is_array($phpcsData['files'])) {
            foreach ($phpcsData['files'] as $fileReport) {
              foreach ($fileReport['messages'] ?? [] as $msg) {
                if ($msg['type'] === 'ERROR') {
                  $errors[] = sprintf('PHPCS line %d: %s', $msg['line'], $msg['message']);
                }
              }
            }
          }
          else {
            $errors[] = 'PHPCS: ' . $phpcsRaw;
          }
        }
      }
      catch (\Exception $e) {
        $errors[] = 'PHPCS check skipped: ' . $e->getMessage();
      }
    }

    @unlink($tempFile);

    $isValid = empty($errors);

    return [
      'valid' => $isValid,
      'status_label' => $isValid ? 'Ready to Apply' : 'Needs Manual Review',
      'errors' => $errors,
    ];
  }

}
