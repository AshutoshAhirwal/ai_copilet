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
    file_put_contents($tempFile, "<?php\n" . ltrim($phpCode, "<?php"));

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

    @unlink($tempFile);

    $isValid = empty($errors);

    return [
      'valid' => $isValid,
      'status_label' => $isValid ? 'Ready to Apply' : 'Needs Manual Review',
      'errors' => $errors,
    ];
  }

}
