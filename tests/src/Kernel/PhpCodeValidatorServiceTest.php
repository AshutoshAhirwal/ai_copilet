<?php

namespace Drupal\Tests\contribot\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Kernel test for PhpCodeValidatorService.
 *
 * @group contribot
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class PhpCodeValidatorServiceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['contribot'];

  /**
   * Tests PHP syntax validation.
   */
  public function testValidateCustomCodeSyntax(): void {
    /** @var \Drupal\contribot\Service\PhpCodeValidatorService $validator */
    $validator = \Drupal::service('contribot.php_code_validator');

    // 1. Valid PHP code: module-prefixed name + full Drupal docblock.
    $validCode = "/**\n * Helper function for copilot output.\n *\n * @return bool\n *   Always returns TRUE.\n */\nfunction contribot_test_helper(): bool {\n  return TRUE;\n}";
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
