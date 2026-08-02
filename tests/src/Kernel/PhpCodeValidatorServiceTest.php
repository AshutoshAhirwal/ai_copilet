<?php

namespace Drupal\Tests\ai_copilot\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ai_copilot\Service\PhpCodeValidatorService;

/**
 * Kernel test for PhpCodeValidatorService.
 *
 * @group ai_copilot
 */
class PhpCodeValidatorServiceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['ai_copilot'];

  /**
   * Tests PHP syntax validation.
   */
  public function testValidateCustomCodeSyntax(): void {
    /** @var \Drupal\ai_copilot\Service\PhpCodeValidatorService $validator */
    $validator = \Drupal::service('ai_copilot.php_code_validator');

    // 1. Valid PHP code.
    $validCode = "function test_valid_helper() { return TRUE; }";
    $resultValid = $validator->validateCustomCode($validCode);

    $this->assertTrue($resultValid['valid']);
    $this->assertEquals('Ready to Apply', $resultValid['status_label']);

    // 2. Invalid PHP code with syntax error.
    $invalidCode = "function test_invalid_helper() { return ;";
    $resultInvalid = $validator->validateCustomCode($invalidCode);

    $this->assertFalse($resultInvalid['valid']);
    $this->assertEquals('Needs Manual Review', $resultInvalid['status_label']);
    $this->assertNotEmpty($resultInvalid['errors']);
  }

}
