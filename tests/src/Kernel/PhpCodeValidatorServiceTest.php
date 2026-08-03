<?php

namespace Drupal\Tests\ai_copilot\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Kernel test for PhpCodeValidatorService.
 *
 * @group ai_copilot
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
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

    // 1. Valid PHP code: module-prefixed name + full Drupal docblock.
    $validCode = "/**\n * Helper function for copilot output.\n *\n * @return bool\n *   Always returns TRUE.\n */\nfunction ai_copilot_test_helper(): bool {\n  return TRUE;\n}";
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
