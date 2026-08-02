<?php

namespace Drupal\Tests\ai_copilot\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ai_copilot\Service\ContribSourceFetcherService;
use Drupal\ai_copilot\Service\CopilotLlmProvider;
use Drupal\ai_copilot\Service\PatchValidatorService;

/**
 * Kernel test for ConfigDecisionEngine 3-path classification.
 *
 * All external services (LLM API, drupal.org source fetcher, git patch
 * validator) are replaced with deterministic stubs so the test is
 * hermetic, fast, and makes zero live API calls.
 *
 * @group ai_copilot
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
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

    // Seed a focal_point candidate so ContribMatcherService has data to rank.
    \Drupal::database()->insert('ai_copilot_contrib_index')
      ->fields([
        'project_name' => 'focal_point',
        'title' => 'Focal Point Crop',
        'description' => 'Provides a focal point for use when cropping images.',
        'maintenance_status' => 'Actively maintained',
        'security_coverage' => 1,
        'usage_count' => 50000,
        'core_compatibility' => '^10.3 || ^11.0',
        'last_release' => time(),
        'readme_summary' => 'Focal point crop documentation.',
        'indexed_at' => time(),
      ])
      ->execute();

    // Stub 1: LLM provider — deterministic output, zero live API calls.
    // generateCompletion() is called twice per evaluateRequirement():
    //   a) By ContribMatcherService::getLlmRelevanceScores() — systemPrompt
    //      contains "Score each module from 0.0 to 1.0".
    //   b) By ConfigDecisionEngine itself for the path decision.
    $llmStub = $this->createMock(CopilotLlmProvider::class);
    $llmStub->method('generateCompletion')
      ->willReturnCallback(
        function (string $systemPrompt, string $userPrompt): string {
          // ContribMatcherService Stage-2 re-ranking call.
          if (str_contains($systemPrompt, 'Score each module')) {
            return json_encode(['focal_point' => 0.95]);
          }
          // ConfigDecisionEngine main path-decision call.
          if (str_contains(strtolower($userPrompt), 'focal point')) {
            return json_encode([
              'path' => 'contrib_patch',
              'reasoning' => 'Focal Point module satisfies this requirement (stub).',
              'module' => 'focal_point',
              'patch_content' => "--- a/focal_point.module\n+++ b/focal_point.module\n@@ -1 +1,2 @@\n+// AI Copilot patch\n",
            ]);
          }
          // Content type query → config_only.
          return json_encode([
            'path' => 'config_only',
            'reasoning' => 'Content type creation is Drupal configuration (stub).',
            'config_yaml' => "langcode: en\nstatus: true\nname: Article\ntype: article\n",
          ]);
        }
      );
    $this->container->set('ai_copilot.llm_provider', $llmStub);

    // Stub 2: Source fetcher — no HTTP calls to drupal.org or Packagist.
    $fetcherStub = $this->createMock(ContribSourceFetcherService::class);
    $fetcherStub->method('fetchSourceToStaging')->willReturn([
      'staging_dir' => sys_get_temp_dir(),
      'version' => '1.0.0',
    ]);
    $this->container->set('ai_copilot.source_fetcher', $fetcherStub);

    // Stub 3: Patch validator — no git dependency in the test environment.
    $patchValidatorStub = $this->createMock(PatchValidatorService::class);
    $patchValidatorStub->method('validatePatch')->willReturn([
      'valid' => TRUE,
      'status_label' => 'Ready to Apply',
      'output' => '',
    ]);
    $this->container->set('ai_copilot.patch_validator', $patchValidatorStub);
  }

  /**
   * Tests 3-path classification with strict path assertions.
   *
   * Stubs guarantee deterministic outcomes; real paths asserted exactly.
   */
  public function testEvaluateRequirementPathClassification(): void {
    /** @var \Drupal\ai_copilot\Service\ConfigDecisionEngine $engine */
    $engine = \Drupal::service('ai_copilot.config_decision_engine');

    // 1. Focal point → contrib_patch (strict assertion).
    $evalContrib = $engine->evaluateRequirement('Add a focal point crop to image fields');
    $this->assertEquals('contrib_patch', $evalContrib['path']);
    $this->assertNotEmpty($evalContrib['patch_content']);
    $this->assertEquals('Ready to Apply', $evalContrib['validation_status']);

    // 2. Content type → config_only (strict assertion).
    $evalConfig = $engine->evaluateRequirement('Add a new content type called Article with a body field');
    $this->assertEquals('config_only', $evalConfig['path']);
    $this->assertNotEmpty($evalConfig['config_yaml']);
  }

}
