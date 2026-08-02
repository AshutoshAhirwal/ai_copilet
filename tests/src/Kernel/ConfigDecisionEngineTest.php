<?php

namespace Drupal\Tests\ai_copilot\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Kernel test for ConfigDecisionEngine 3-path classification.
 *
 * @group ai_copilot
 */
class ConfigDecisionEngineTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'field', 'node', 'ai_copilot'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('ai_copilot', ['ai_copilot_contrib_index']);
  }

  /**
   * Tests 3-path classification output.
   */
  public function testEvaluateRequirementPathClassification(): void {
    /** @var \Drupal\ai_copilot\Service\ConfigDecisionEngine $engine */
    $engine = \Drupal::service('ai_copilot.config_decision_engine');

    // 1. Test focal point query -> contrib_patch.
    $evalContrib = $engine->evaluateRequirement('Add a focal point crop to image fields');
    $this->assertEquals('contrib_patch', $evalContrib['path']);
    $this->assertNotEmpty($evalContrib['patch_content']);
    $this->assertEquals('Ready to Apply', $evalContrib['validation_status']);

    // 2. Test content type query -> config_only.
    $evalConfig = $engine->evaluateRequirement('Add a new content type called Article with a body field');
    $this->assertEquals('config_only', $evalConfig['path']);
    $this->assertNotEmpty($evalConfig['config_yaml']);
  }

}
