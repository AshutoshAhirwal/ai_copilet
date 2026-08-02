<?php

namespace Drupal\ai_copilot\Service;

use Symfony\Component\Process\Process;

/**
 * Service for dry-run patch validation using Symfony Process with array arguments.
 */
class PatchValidatorService {

  /**
   * Validates a generated .patch file against staging source code via git apply --check.
   *
   * @param string $patchContent
   *   The patch unified diff string.
   * @param string $stagingDir
   *   Path to target source code directory.
   *
   * @return array
   *   Array with 'valid' (bool), 'status_label' (string), and 'output' (string).
   */
  public function validatePatch(string $patchContent, string $stagingDir): array {
    $tempPatchFile = tempnam(sys_get_temp_dir(), 'copilot_patch_') . '.patch';
    file_put_contents($tempPatchFile, $patchContent);

    try {
      // Type-safe array arguments — NO shell string concatenation.
      $process = new Process(['git', 'apply', '--check', $tempPatchFile], $stagingDir);
      $process->run();

      @unlink($tempPatchFile);

      if ($process->isSuccessful()) {
        return [
          'valid' => TRUE,
          'status_label' => 'Ready to Apply',
          'output' => 'Patch validation successful. Hunks apply cleanly.',
        ];
      }
      else {
        return [
          'valid' => FALSE,
          'status_label' => 'Needs Manual Review',
          'output' => $process->getErrorOutput() ?: $process->getOutput(),
        ];
      }
    }
    catch (\Exception $e) {
      @unlink($tempPatchFile);
      return [
        'valid' => FALSE,
        'status_label' => 'Needs Manual Review',
        'output' => 'Validation error: ' . $e->getMessage(),
      ];
    }
  }

}
