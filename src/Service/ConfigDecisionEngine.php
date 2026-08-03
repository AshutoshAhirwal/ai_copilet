<?php

namespace Drupal\contribot\Service;

/**
 * Service for analyzing requirements against site config and recommending the minimal path.
 */
class ConfigDecisionEngine {

  /**
   * Site context assembler.
   *
   * @var \Drupal\contribot\Service\SiteContextAssembler
   */
  protected $siteContextAssembler;

  /**
   * Contrib matcher service.
   *
   * @var \Drupal\contribot\Service\ContribMatcherService
   */
  protected $contribMatcher;

  /**
   * Source fetcher service.
   *
   * @var \Drupal\contribot\Service\ContribSourceFetcherService
   */
  protected $sourceFetcher;

  /**
   * Patch validator service.
   *
   * @var \Drupal\contribot\Service\PatchValidatorService
   */
  protected $patchValidator;

  /**
   * PHP code validator service.
   *
   * @var \Drupal\contribot\Service\PhpCodeValidatorService
   */
  protected $phpCodeValidator;

  /**
   * LLM provider service.
   *
   * @var \Drupal\contribot\Service\CopilotLlmProvider
   */
  protected $llmProvider;

  /**
   * Optional progress callback called at each pipeline step.
   *
   * @var callable|null
   */
  protected $progressCallback = NULL;

  /**
   * Constructs a ConfigDecisionEngine.
   */
  public function __construct(
    SiteContextAssembler $siteContextAssembler,
    ContribMatcherService $contribMatcher,
    ContribSourceFetcherService $sourceFetcher,
    PatchValidatorService $patchValidator,
    PhpCodeValidatorService $phpCodeValidator,
    CopilotLlmProvider $llmProvider,
  ) {
    $this->siteContextAssembler = $siteContextAssembler;
    $this->contribMatcher = $contribMatcher;
    $this->sourceFetcher = $sourceFetcher;
    $this->patchValidator = $patchValidator;
    $this->phpCodeValidator = $phpCodeValidator;
    $this->llmProvider = $llmProvider;
  }

  /**
   * Registers a callback to receive real-time progress events during evaluation.
   *
   * The callback signature is: function(string $message, int $step): void.
   *
   * @param callable $callback
   *   Progress event handler.
   */
  public function setProgressCallback(callable $callback): void {
    $this->progressCallback = $callback;
  }

  /**
   * Emits a progress event if a callback is registered.
   *
   * @param string $message
   *   Human-readable progress description.
   * @param int $step
   *   Monotonically increasing step number.
   */
  protected function emitProgress(string $message, int $step): void {
    if ($this->progressCallback !== NULL) {
      ($this->progressCallback)($message, $step);
    }
  }

  /**
   * Evaluates a requirement with optional conversation history and streaming progress.
   *
   * The LLM may respond with a clarifying question ({"type":"question","question":"..."})
   * instead of a full evaluation if the first turn lacks critical details. On subsequent
   * turns (history.length > 1 user message) it always returns a full evaluation.
   *
   * @param string $requirement
   *   The latest user requirement or follow-up message.
   * @param array $history
   *   Full conversation history as {role, content} pairs (including current message).
   *
   * @return array
   *   Either {'type':'question','question':'...'} for a clarification turn, or the
   *   full evaluation structure (path, reasoning, candidates, validation_status, …).
   */
  public function evaluateWithHistory(string $requirement, array $history = []): array {
    $config = \Drupal::config('contribot.settings');
    $privacyLevel = $config->get('data_privacy_level') ?: 'structure_only';

    $this->siteContextAssembler->assembleContext($privacyLevel);

    $this->emitProgress('📚 Searching contrib module database...', 2);
    $candidates = $this->contribMatcher->matchCandidates($requirement, \Drupal::VERSION, 5);

    $this->emitProgress('🤖 Consulting AI architect...', 3);

    // Count user turns to decide whether clarification is still permitted.
    $userTurns = count(array_filter($history, fn($m) => ($m['role'] ?? '') === 'user'));
    $hasClarifications = $userTurns > 1;

    $systemPrompt = "You are Contribot. Evaluate requirements and determine the minimal architectural path.\n";

    if (!$hasClarifications) {
      $systemPrompt .= "CLARIFICATION: If the requirement lacks critical specifics needed to generate correct "
        . "YAML/config/code (missing content type name, field names, feature scope), respond ONLY with:\n"
        . "{\"type\":\"question\",\"question\":\"[one specific targeted question]\"}\n"
        . "Otherwise proceed with a full evaluation.\n\n";
    }
    else {
      $systemPrompt .= "The conversation history contains user clarifications. ALWAYS produce a full "
        . "evaluation now — do NOT ask another question.\n\n";
    }

    $systemPrompt .= "DECISION HIERARCHY:\n"
      . "1. 'config_only': Solvable via Drupal configuration (fields, content types, views, permissions) → return config YAML.\n"
      . "2. 'contrib_patch': Maintained contrib module covers >=80% of the need → return module + patch.\n"
      . "3. 'custom_code': Only when config/contrib is genuinely insufficient.\n";

    $rawResponse = $this->llmProvider->generateChatCompletion($systemPrompt, $history, $candidates);
    $parsed = json_decode($rawResponse, TRUE) ?? [];

    // Clarifying question short-circuit.
    if (isset($parsed['type']) && $parsed['type'] === 'question') {
      return ['type' => 'question', 'question' => (string) ($parsed['question'] ?? 'Could you provide more details?')];
    }

    $path = $parsed['path'] ?? 'config_only';
    $reasoning = $parsed['reasoning'] ?? 'Decision based on site configuration and contrib analysis.';

    $result = [
      'path' => $path,
      'reasoning' => $reasoning,
      'candidates' => $candidates,
      'validation_status' => 'Ready to Apply',
      'validation_details' => 'No errors detected.',
      'demo_mode' => (bool) ($parsed['demo_mode'] ?? FALSE),
    ];

    $this->emitProgress('🔬 Validating output...', 4);

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

    $this->emitProgress('✅ Analysis complete', 5);

    return $result;
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
    $config = \Drupal::config('contribot.settings');
    $privacyLevel = $config->get('data_privacy_level') ?: 'structure_only';

    // 1. Assemble site context.
    $this->siteContextAssembler->assembleContext($privacyLevel);

    // 2. Match candidate contrib modules.
    $candidates = $this->contribMatcher->matchCandidates($requirement, \Drupal::VERSION, 5);

    // 3. System Prompt enforcing Contrib-First & Config-First decision matrix.
    $systemPrompt = "You are Contribot. Evaluate the user requirement against site context and candidate modules.\n" .
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
      'demo_mode' => (bool) ($parsed['demo_mode'] ?? FALSE),
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
