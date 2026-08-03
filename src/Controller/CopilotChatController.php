<?php

namespace Drupal\ai_copilot\Controller;

use Drupal\Core\Config\Schema\SchemaCheckTrait;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Yaml\Yaml;

use Drupal\ai_copilot\Service\ConfigDecisionEngine;
use Drupal\ai_copilot\Service\DataPrivacyManagerService;
use Drupal\ai_copilot\Service\EnvironmentDetectorService;
use Drupal\ai_copilot\Service\MutationLockManagerService;
use Drupal\ai_copilot\Service\SnapshotManagerService;
use Drupal\ai_copilot\Service\ComposerPatchManagerService;
use Drupal\ai_copilot\Service\AgentToolRegistry;
use Drupal\ai_copilot\Service\AuditLoggerService;
use Drupal\ai_copilot\Service\ConversationSessionService;
use Drupal\ai_copilot\Service\CopilotLlmProvider;
use Drupal\ai_copilot\Service\PhpCodeValidatorService;

/**
 * Controller for AI Copilot chat and mutation API endpoints.
 */
class CopilotChatController extends ControllerBase {

  use SchemaCheckTrait;

  /**
   * Config object name prefixes AI Copilot is allowed to create or update.
   *
   * Deliberately narrow: only simple config entities that generateDynamic
   * fallback / LLM proposals are designed to produce. Anything else is
   * refused rather than trusting a client- or LLM-supplied config name.
   */
  protected const ALLOWED_CONFIG_PREFIXES = [
    'node.type.',
    'taxonomy.vocabulary.',
    'user.role.',
  ];

  /**
   * Config decision engine.
   *
   * @var \Drupal\ai_copilot\Service\ConfigDecisionEngine
   */
  protected $decisionEngine;

  /**
   * Data privacy manager.
   *
   * @var \Drupal\ai_copilot\Service\DataPrivacyManagerService
   */
  protected $dataPrivacyManager;

  /**
   * Environment detector.
   *
   * @var \Drupal\ai_copilot\Service\EnvironmentDetectorService
   */
  protected $environmentDetector;

  /**
   * Mutation lock manager.
   *
   * @var \Drupal\ai_copilot\Service\MutationLockManagerService
   */
  protected $mutationLockManager;

  /**
   * Snapshot manager.
   *
   * @var \Drupal\ai_copilot\Service\SnapshotManagerService
   */
  protected $snapshotManager;

  /**
   * Composer patch manager.
   *
   * @var \Drupal\ai_copilot\Service\ComposerPatchManagerService
   */
  protected $composerPatchManager;

  /**
   * Audit logger.
   *
   * @var \Drupal\ai_copilot\Service\AuditLoggerService
   */
  protected $auditLogger;

  /**
   * Conversation session service.
   *
   * @var \Drupal\ai_copilot\Service\ConversationSessionService
   */
  protected $conversationSession;

  /**
   * LLM provider.
   *
   * @var \Drupal\ai_copilot\Service\CopilotLlmProvider
   */
  protected $llmProvider;

  /**
   * Agent tool registry.
   *
   * @var \Drupal\ai_copilot\Service\AgentToolRegistry
   */
  protected $agentToolRegistry;

  /**
   * PHP code validator.
   *
   * Used to re-validate custom code immediately before it is written to disk.
   *
   * @var \Drupal\ai_copilot\Service\PhpCodeValidatorService
   */
  protected $phpCodeValidator;

  /**
   * Constructs a CopilotChatController.
   */
  public function __construct(
    ConfigDecisionEngine $decisionEngine,
    DataPrivacyManagerService $dataPrivacyManager,
    EnvironmentDetectorService $environmentDetector,
    MutationLockManagerService $mutationLockManager,
    SnapshotManagerService $snapshotManager,
    ComposerPatchManagerService $composerPatchManager,
    AuditLoggerService $auditLogger,
    ConversationSessionService $conversationSession,
    CopilotLlmProvider $llmProvider,
    AgentToolRegistry $agentToolRegistry,
    PhpCodeValidatorService $phpCodeValidator,
  ) {
    $this->decisionEngine = $decisionEngine;
    $this->dataPrivacyManager = $dataPrivacyManager;
    $this->environmentDetector = $environmentDetector;
    $this->mutationLockManager = $mutationLockManager;
    $this->snapshotManager = $snapshotManager;
    $this->composerPatchManager = $composerPatchManager;
    $this->auditLogger = $auditLogger;
    $this->conversationSession = $conversationSession;
    $this->llmProvider = $llmProvider;
    $this->agentToolRegistry = $agentToolRegistry;
    $this->phpCodeValidator = $phpCodeValidator;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('ai_copilot.config_decision_engine'),
      $container->get('ai_copilot.data_privacy_manager'),
      $container->get('ai_copilot.environment_detector'),
      $container->get('ai_copilot.mutation_lock_manager'),
      $container->get('ai_copilot.snapshot_manager'),
      $container->get('ai_copilot.composer_patch_manager'),
      $container->get('ai_copilot.audit_logger'),
      $container->get('ai_copilot.conversation_session'),
      $container->get('ai_copilot.llm_provider'),
      $container->get('ai_copilot.agent_tool_registry'),
      $container->get('ai_copilot.php_code_validator')
    );
  }

  /**
   * System prompt sent once at the start of a new conversation.
   */
  protected const SYSTEM_PROMPT = "You are Drupal AI Copilot, an expert assistant for Drupal 11 development. "
    . "You help developers make optimal architectural decisions following priorities: "
    . "config-first, contrib-first, patch-not-rewrite.\n\n"
    . "You have access to these read-only tools:\n"
    . "- get_site_context: Read the site's Drupal version, active modules, and content types.\n"
    . "- search_contrib_modules: Search the indexed contrib module database.\n\n"
    . "When a developer describes a requirement:\n"
    . "1. If the requirement is ambiguous or lacks critical details, ask ONE clarifying question "
    . "   as plain text — no tool call needed.\n"
    . "2. Otherwise, call get_site_context first to understand the site, then search_contrib_modules "
    . "   if a contrib solution may exist, then give a concrete recommendation.\n"
    . "3. Your final recommendation should specify: the architectural path "
    . "   (config_only / contrib_patch / custom_code), the reasoning, and the specific "
    . "   YAML, module name, or code needed.\n"
    . "Keep responses concise and practical.";

  /**
   * Handles POST /admin/api/ai-copilot/chat — multi-turn agent loop.
   *
   * The agent loop:
   * 1. Loads per-user conversation history from PrivateTempStore.
   * 2. Appends the user's message to history.
   * 3. Loops up to 6 iterations calling LLM → tool → LLM → ...
   * 4. Returns the final text reply plus a trace of tool calls made this turn.
   *
   * Response: {error: false, reply: '...', steps: [{tool, status}], conversation_id: '...'}
   */
  public function handleChat(Request $request): JsonResponse {
    try {
      $body = json_decode($request->getContent(), TRUE) ?: [];
      $prompt = trim((string) ($body['prompt'] ?? ''));
      $rawId = (string) ($body['conversation_id'] ?? '');
      $conversationId = preg_replace('/[^a-zA-Z0-9_-]/', '', $rawId);

      if (empty($conversationId)) {
        $conversationId = bin2hex(random_bytes(16));
      }

      if (empty($prompt)) {
        return new JsonResponse(['error' => TRUE, 'message' => 'Prompt is required.'], 400);
      }

      // Load history and append the user's message.
      $history = $this->conversationSession->getHistory($conversationId);
      $isNew = empty($history);

      // Prepend system prompt as the first user turn for a new conversation.
      if ($isNew) {
        $history[] = [
          'role' => 'user',
          'parts' => [['text' => self::SYSTEM_PROMPT . "\n\nDeveloper request: " . $prompt]],
        ];
      }
      else {
        $history[] = ['role' => 'user', 'parts' => [['text' => $prompt]]];
      }

      $tools = $this->agentToolRegistry->getDeclarations();
      $steps = [];
      $maxIterations = 6;

      for ($i = 0; $i < $maxIterations; $i++) {
        $llmResult = $this->llmProvider->sendConversation($history, $tools);

        if ($llmResult['type'] === 'error') {
          return new JsonResponse([
            'error' => TRUE,
            'message' => 'LLM error: ' . $llmResult['message'],
            'steps' => $steps,
            'conversation_id' => $conversationId,
          ], 500);
        }

        if ($llmResult['type'] === 'function_call') {
          $toolName = $llmResult['name'];
          $toolArgs = $llmResult['args'];
          $callId = $llmResult['id'];

          // Append model's function-call turn to history.
          $history[] = [
            'role' => 'model',
            'parts' => [['functionCall' => ['name' => $toolName, 'args' => $toolArgs, 'id' => $callId]]],
          ];

          // Execute the read-only tool.
          try {
            $toolResult = $this->agentToolRegistry->execute($toolName, $toolArgs);
            $steps[] = ['tool' => $toolName, 'status' => 'completed'];
          }
          catch (\Throwable $e) {
            $toolResult = ['error' => $e->getMessage()];
            $steps[] = ['tool' => $toolName, 'status' => 'error'];
          }

          // Append function-response turn to history (role:'user' per Gemini protocol).
          $history[] = [
            'role' => 'user',
            'parts' => [[
              'functionResponse' => [
                'name' => $toolName,
                'id' => $callId,
                'response' => $toolResult,
              ],
            ],
            ],
          ];

          // Continue the loop so the model can process the tool result.
          continue;
        }

        // Text response — this is the final answer for this turn.
        if ($llmResult['type'] === 'text') {
          $reply = $llmResult['text'];
          $history[] = ['role' => 'model', 'parts' => [['text' => $reply]]];
          $this->conversationSession->saveHistory($conversationId, $history);

          return new JsonResponse([
            'error' => FALSE,
            'reply' => $reply,
            'steps' => $steps,
            'conversation_id' => $conversationId,
            'demo_mode' => (bool) ($llmResult['demo_mode'] ?? FALSE),
          ]);
        }
      }

      // Iteration cap reached — stop rather than looping indefinitely.
      $this->conversationSession->saveHistory($conversationId, $history);
      return new JsonResponse([
        'error' => TRUE,
        'message' => sprintf(
          'Agent loop reached the %d-iteration safety cap without producing a final answer. '
          . 'This may indicate the model is repeatedly calling tools. Please try rephrasing your request.',
          $maxIterations
        ),
        'steps' => $steps,
        'conversation_id' => $conversationId,
      ], 500);
    }
    catch (\Throwable $e) {
      \Drupal::logger('ai_copilot')->error('Chat agent loop exception: @msg', ['@msg' => $e->getMessage()]);
      return new JsonResponse([
        'error' => TRUE,
        'message' => 'Server error: ' . $e->getMessage(),
      ], 500);
    }
  }

  /**
   * Handles POST /admin/api/ai-copilot/apply human-approved mutations.
   */
  public function handleApply(Request $request): JsonResponse {
    try {
      $config = $this->config('ai_copilot.settings');
      $allowProd = (bool) $config->get('allow_production_mutation');

      // 1. Environment & Read-Only Check.
      $mutationCheck = $this->environmentDetector->isMutationAllowed($allowProd);
      if (!$mutationCheck['allowed']) {
        return new JsonResponse(['success' => FALSE, 'error' => $mutationCheck['reason']], 403);
      }

      // 2. Concurrency Lock Protection (HTTP 409 Conflict if locked).
      if (!$this->mutationLockManager->acquireLock(30.0)) {
        return new JsonResponse([
          'success' => FALSE,
          'error' => 'Another mutation is currently in progress. Please wait for it to complete.',
        ], 409);
      }

      $content = json_decode($request->getContent(), TRUE) ?: [];
      $prompt = trim((string) ($content['prompt'] ?? 'Requirement mutation'));
      $path = $content['path'] ?? 'config_only';

      $affectedFiles = [];
      $affected = [];
      $diffContent = '';

      // Pre-determine affected file paths so the snapshot captures them before mutation.
      if ($path === 'custom_code') {
        $appRoot = \Drupal::hasService('kernel') ? \Drupal::root() : DRUPAL_ROOT;
        $targetCustomFile = $appRoot . '/modules/custom/ai_copilot_generated/ai_copilot_generated.module';
        if (file_exists($targetCustomFile)) {
          $affectedFiles[] = $targetCustomFile;
        }
      }

      // 3. Create Rollback Snapshot BEFORE applying any mutation.
      $auditIdPlaceholder = time();
      $snapshotPath = $this->snapshotManager->createSnapshot($auditIdPlaceholder, $affectedFiles);

      // 4. Apply mutation after snapshot is safely recorded.
      if ($path === 'contrib_patch') {
        $module = $content['module'] ?? 'focal_point';
        $patch = $content['patch_content'] ?? '';
        $patchResult = $this->composerPatchManager->applyPatchViaComposer($module, 'AI Copilot patch', $patch);

        $affected = ['installed_module' => $module, 'patch_file' => $patchResult['patch_path']];
        $diffContent = $patch;
      }
      elseif ($path === 'config_only') {
        $yamlStr = $content['config_yaml'] ?? '';
        $diffContent = $yamlStr;

        try {
          $parsedConfig = Yaml::parse($yamlStr);
        }
        catch (\Exception $e) {
          $this->mutationLockManager->releaseLock();
          return new JsonResponse([
            'success' => FALSE,
            'error' => 'Invalid configuration YAML: ' . $e->getMessage(),
          ], 400);
        }

        if (!is_array($parsedConfig)) {
          $this->mutationLockManager->releaseLock();
          return new JsonResponse([
            'success' => FALSE,
            'error' => 'Configuration YAML did not produce a valid structure.',
          ], 400);
        }

        // Never trust a client- or LLM-supplied config object name. Derive
        // it ourselves from the data shape, then check it against the
        // explicit allowlist below.
        $configName = $this->deriveAllowlistedConfigName($parsedConfig);
        if ($configName === NULL) {
          $this->mutationLockManager->releaseLock();
          return new JsonResponse([
            'success' => FALSE,
            'error' => sprintf(
              'Refusing to apply: this configuration object is not on the allowed list (%s).',
              implode(', ', array_map(fn($p) => $p . '*', self::ALLOWED_CONFIG_PREFIXES))
            ),
          ], 403);
        }

        $schemaResult = $this->checkConfigSchema(\Drupal::service('config.typed'), $configName, $parsedConfig);
        if ($schemaResult !== TRUE) {
          $this->mutationLockManager->releaseLock();
          $errorMessages = is_array($schemaResult) ? implode('; ', $schemaResult) : 'No schema found for this configuration object.';
          return new JsonResponse([
            'success' => FALSE,
            'error' => 'Configuration failed schema validation: ' . $errorMessages,
          ], 400);
        }

        if (str_starts_with($configName, 'node.type.')) {
          $typeName = substr($configName, strlen('node.type.'));
          $label = $parsedConfig['name'] ?? ucfirst($typeName);

          $storage = \Drupal::entityTypeManager()->getStorage('node_type');
          /** @var \Drupal\node\NodeTypeInterface|null $existing */
          $existing = $storage->load($typeName);

          if (!$existing) {
            /** @var \Drupal\node\NodeTypeInterface $nodeType */
            $nodeType = $storage->create([
              'type' => $typeName,
              'name' => $label,
              'description' => $parsedConfig['description'] ?? '',
              'new_revision' => TRUE,
              'preview_mode' => 1,
              'display_submitted' => TRUE,
            ]);
            $nodeType->save();
            if (function_exists('node_add_body_field')) {
              node_add_body_field($nodeType);
            }
          }
          else {
            $existing->set('name', $label)->save();
          }

          \Drupal::service('router.builder')->rebuild();
          $affected = ['created_content_type' => $typeName];
        }
        else {
          // Vocabulary / role, or any other allowlisted simple config entity:
          // schema-validated above, safe to write directly.
          $this->configFactory()->getEditable($configName)->setData($parsedConfig)->save();
          $affected = ['imported_config' => $configName];
        }
      }
      elseif ($path === 'custom_code') {
        $code = $content['custom_code'] ?? '';
        $diffContent = $code;

        // Re-validate the EXACT code about to be written, immediately before
        // writing it - never trust validation performed at an earlier step
        // (e.g. preview time), since the client controls this request body.
        $validation = $this->phpCodeValidator->validateCustomCode($code);
        if (!$validation['valid']) {
          $this->mutationLockManager->releaseLock();
          return new JsonResponse([
            'success' => FALSE,
            'error' => 'Custom code failed validation and was not written: ' . implode('; ', $validation['errors']),
          ], 400);
        }

        $appRoot = \Drupal::hasService('kernel') ? \Drupal::root() : DRUPAL_ROOT;
        $targetCustomFile = $appRoot . '/modules/custom/ai_copilot_generated/ai_copilot_generated.module';
        @mkdir(dirname($targetCustomFile), 0755, TRUE);

        $body = preg_replace('/^<\?php\s*/', '', $code);
        file_put_contents($targetCustomFile, "<?php\n\n" . $body);
        $affected = ['generated_custom_file' => $targetCustomFile];
      }

      // 5. Record Audit Log.
      $auditId = $this->auditLogger->logAction($prompt, $path, $affected, $diffContent, $snapshotPath, 'applied');

      $this->mutationLockManager->releaseLock();

      return new JsonResponse([
        'success' => TRUE,
        'audit_id' => $auditId,
        'message' => 'Mutation applied cleanly. Pre-mutation snapshot recorded.',
      ]);
    }
    catch (\Throwable $e) {
      $this->mutationLockManager->releaseLock();
      \Drupal::logger('ai_copilot')->error('Apply API exception: @msg', ['@msg' => $e->getMessage()]);
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Mutation execution failed: ' . $e->getMessage(),
      ], 500);
    }
  }

  /**
   * Derives an allowlisted config object name from parsed config data.
   *
   * The config object name is never taken from client- or LLM-supplied
   * strings (e.g. a 'name' key in the payload); it is always reconstructed
   * from a recognised, narrow set of data shapes and checked against
   * self::ALLOWED_CONFIG_PREFIXES.
   *
   * @param array $parsedConfig
   *   Parsed configuration YAML data.
   *
   * @return string|null
   *   The allowlisted config object name, or NULL if the data does not
   *   match a recognised, allowed shape.
   */
  protected function deriveAllowlistedConfigName(array $parsedConfig): ?string {
    if (isset($parsedConfig['vid'])) {
      $vid = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $parsedConfig['vid']));
      return $vid !== '' ? 'taxonomy.vocabulary.' . $vid : NULL;
    }
    if (isset($parsedConfig['permissions']) && isset($parsedConfig['id'])) {
      $rid = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $parsedConfig['id']));
      return $rid !== '' ? 'user.role.' . $rid : NULL;
    }
    if (isset($parsedConfig['type'])) {
      $type = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $parsedConfig['type']));
      return $type !== '' ? 'node.type.' . $type : NULL;
    }
    return NULL;
  }

  /**
   * Handles POST /admin/api/ai-copilot/revert action.
   */
  public function handleRevert(Request $request): JsonResponse {
    $lockAcquired = FALSE;
    try {
      $content = json_decode($request->getContent(), TRUE) ?: [];
      $auditId = (int) ($content['audit_id'] ?? 0);

      if ($auditId <= 0) {
        return new JsonResponse(['success' => FALSE, 'error' => 'Valid audit_id is required.'], 400);
      }

      // Concurrency Lock Protection, matching handleApply()'s pattern — a
      // revert mutates config/files just like an apply does, and must not
      // race with a concurrent apply or another revert.
      if (!$this->mutationLockManager->acquireLock(30.0)) {
        return new JsonResponse([
          'success' => FALSE,
          'error' => 'Another mutation is currently in progress. Please wait for it to complete.',
        ], 409);
      }
      $lockAcquired = TRUE;

      $result = $this->snapshotManager->revertMutation($auditId);

      $this->mutationLockManager->releaseLock();
      $lockAcquired = FALSE;

      if (!$result['success']) {
        return new JsonResponse(['success' => FALSE, 'error' => $result['message']], 400);
      }

      return new JsonResponse([
        'success' => TRUE,
        'message' => $result['message'],
      ]);
    }
    catch (\Throwable $e) {
      if ($lockAcquired) {
        $this->mutationLockManager->releaseLock();
      }
      \Drupal::logger('ai_copilot')->error('Revert API exception: @msg', ['@msg' => $e->getMessage()]);
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Revert failed: ' . $e->getMessage(),
      ], 500);
    }
  }

  /**
   * Handles POST /admin/api/ai-copilot/stream — SSE streaming evaluation.
   *
   * Emits Server-Sent Events as the AI pipeline executes so the frontend can
   * show live progress. Event types: progress, question, result, error, done.
   */
  public function handleStream(Request $request): Response {
    $content = json_decode($request->getContent(), TRUE) ?? [];
    $prompt = trim((string) ($content['prompt'] ?? ''));
    $rawId = (string) ($content['session_id'] ?? '');
    $sessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', $rawId);

    if (empty($sessionId)) {
      $sessionId = bin2hex(random_bytes(16));
    }

    $conversationSession = $this->conversationSession;
    $decisionEngine = $this->decisionEngine;

    $callback = function () use ($prompt, $sessionId, $conversationSession, $decisionEngine): void {
      set_time_limit(120);

      $emit = static function (string $event, array $data): void {
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data) . "\n\n";
        if (ob_get_level() > 0) {
          ob_flush();
        }
        flush();
      };

      if (empty($prompt)) {
        $emit('error', ['message' => 'Prompt is required.']);
        $emit('done', []);
        return;
      }

      $conversationSession->addMessage($sessionId, 'user', $prompt);
      $history = $conversationSession->getHistory($sessionId);

      $emit('progress', ['message' => '🔍 Analyzing your requirement...', 'step' => 1]);

      $decisionEngine->setProgressCallback(function (string $message, int $step) use ($emit): void {
        $emit('progress', ['message' => $message, 'step' => $step]);
      });

      try {
        $result = $decisionEngine->evaluateWithHistory($prompt, $history);

        if (isset($result['type']) && $result['type'] === 'question') {
          $conversationSession->addMessage($sessionId, 'assistant', $result['question']);
          $emit('question', ['message' => $result['question'], 'session_id' => $sessionId]);
        }
        else {
          $summary = 'Evaluation path: ' . ($result['path'] ?? 'unknown');
          $conversationSession->addMessage($sessionId, 'assistant', $summary);
          $emit('result', ['evaluation' => $result, 'session_id' => $sessionId]);
        }
      }
      catch (\Throwable $e) {
        \Drupal::logger('ai_copilot')->error('Stream API exception: @msg', ['@msg' => $e->getMessage()]);
        $emit('error', ['message' => $e->getMessage()]);
      }

      $emit('done', []);
    };

    return new StreamedResponse($callback, 200, [
      'Content-Type' => 'text/event-stream',
      'Cache-Control' => 'no-cache, no-store',
      'X-Accel-Buffering' => 'no',
      'Connection' => 'keep-alive',
    ]);
  }

  /**
   * Handles POST /admin/api/ai-copilot/clear-session — resets conversation history.
   */
  public function handleClearSession(Request $request): JsonResponse {
    $body = json_decode($request->getContent(), TRUE) ?? [];
    $rawId = (string) ($body['conversation_id'] ?? $body['session_id'] ?? '');
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $rawId);

    if (!empty($id)) {
      $this->conversationSession->clearHistory($id);
    }

    return new JsonResponse(['success' => TRUE]);
  }

}
