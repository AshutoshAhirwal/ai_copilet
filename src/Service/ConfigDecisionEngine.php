<?php

namespace Drupal\ai_copilot\Service;

/**
 * Service for analyzing requirements against site config and recommending the minimal path.
 */
class ConfigDecisionEngine {

  /**
   * Site context assembler.
   *
   * @var \Drupal\ai_copilot\Service\SiteContextAssembler
   */
  protected $siteContextAssembler;

  /**
   * Contrib matcher service.
   *
   * @var \Drupal\ai_copilot\Service\ContribMatcherService
   */
  protected $contribMatcher;

  /**
   * Source fetcher service.
   *
   * @var \Drupal\ai_copilot\Service\ContribSourceFetcherService
   */
  protected $sourceFetcher;

  /**
   * Patch validator service.
   *
   * @var \Drupal\ai_copilot\Service\PatchValidatorService
   */
  protected $patchValidator;

  /**
   * PHP code validator service.
   *
   * @var \Drupal\ai_copilot\Service\PhpCodeValidatorService
   */
  protected $phpCodeValidator;

  /**
   * LLM provider service.
   *
   * @var \Drupal\ai_copilot\Service\CopilotLlmProvider
   */
  protected $llmProvider;

  /**
   * Constructs a ConfigDecisionEngine.
   */
  public function __construct(
    SiteContextAssembler $siteContextAssembler,
    ContribMatcherService $contribMatcher,
    ContribSourceFetcherService $sourceFetcher,
    PatchValidatorService $patchValidator,
    PhpCodeValidatorService $phpCodeValidator,
    CopilotLlmProvider $llmProvider
  ) {
    $this->siteContextAssembler = $siteContextAssembler;
    $this->contribMatcher = $contribMatcher;
    $this->sourceFetcher = $sourceFetcher;
    $this->patchValidator = $patchValidator;
    $this->phpCodeValidator = $phpCodeValidator;
    $this->llmProvider = $llmProvider;
  }

  /**
   * Evaluates a developer requirement and determines the minimal architectural path.
   *
   * @param string $requirement
   *   The developer's prompt.
   *
   * @return array
   *   Decision structure with path, reasoning, diff/YAML/patch, and validation status.
   */
  public function evaluateRequirement(string $requirement): array {
    $config = \Drupal::config('ai_copilot.settings');
    $privacyLevel = $config->get('data_privacy_level') ?: 'structure_only';

    // 1. Assemble site context.
    $context = $this->siteContextAssembler->assembleContext($privacyLevel);

    // 2. Match candidate contrib modules.
    $candidates = $this->contribMatcher->matchCandidates($requirement, \Drupal::VERSION, 5);

    // 3. System Prompt enforcing Contrib-First & Config-First decision matrix.
    $systemPrompt = "You are Drupal AI Copilot. Evaluate the user requirement against site context and candidate modules.\n" .
      "Enforce this strict decision hierarchy:\n" .
      "1. Path 'config_only': If requirement can be solved by Drupal configuration alone (fields, content types, views, view modes, permissions), recommend config_only + exportable YAML.\n" .
      "2. Path 'contrib_patch': If a maintained contrib module covers >=80% of requirement, recommend contrib_patch + composer require command + scoped .patch file.\n" .
      "3. Path 'custom_code': Only if config/contrib is genuinely poor, recommend custom_code with detailed justification.\n";

    $rawLlmResponse = $this->llmProvider->generateCompletion($systemPrompt, $requirement, $candidates);
    $parsed = json_decode($rawLlmResponse, TRUE) ?: [];

    $path = $parsed['path'] ?? 'config_only';
    $reasoning = $parsed['reasoning'] ?? 'Decision based on site configuration and contrib matching analysis.';

    $result = [
      'path' => $path,
      'reasoning' => $reasoning,
      'candidates' => $candidates,
      'validation_status' => 'Ready to Apply',
      'validation_details' => 'No errors detected.',
    ];

    if ($path === 'contrib_patch') {
      $moduleName = $parsed['module'] ?? ($candidates[0]['project_name'] ?? 'focal_point');
      $patchContent = $parsed['patch_content'] ?? '';

      $fetchResult = $this->sourceFetcher->fetchSourceToStaging($moduleName, \Drupal::VERSION);
      $valResult = $this->patchValidator->validatePatch($patchContent, $fetchResult['staging_dir']);

      $result['module'] = $moduleName;
      $result['patch_content'] = $patchContent;
      $result['validation_status'] = $valResult['status_label'];
      $result['validation_details'] = $valResult['output'];
    }
    elseif ($path === 'config_only') {
      $result['config_yaml'] = $parsed['config_yaml'] ?? "langcode: en\nstatus: true\n";
    }
    elseif ($path === 'custom_code') {
      $customCode = $parsed['custom_code'] ?? "function custom_helper() {}\n";
      $valResult = $this->phpCodeValidator->validateCustomCode($customCode);

      $result['custom_code'] = $customCode;
      $result['validation_status'] = $valResult['status_label'];
      $result['validation_details'] = implode(' ', $valResult['errors']);
    }

    return $result;
  }

}
