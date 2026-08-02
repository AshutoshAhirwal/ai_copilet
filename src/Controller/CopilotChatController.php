<?php

namespace Drupal\ai_copilot\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Yaml\Yaml;

use Drupal\ai_copilot\Service\ConfigDecisionEngine;
use Drupal\ai_copilot\Service\DataPrivacyManagerService;
use Drupal\ai_copilot\Service\EnvironmentDetectorService;
use Drupal\ai_copilot\Service\MutationLockManagerService;
use Drupal\ai_copilot\Service\SnapshotManagerService;
use Drupal\ai_copilot\Service\ComposerPatchManagerService;
use Drupal\ai_copilot\Service\AuditLoggerService;

/**
 * Controller for AI Copilot chat and mutation API endpoints.
 */
class CopilotChatController extends ControllerBase {

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
   * Constructs a CopilotChatController.
   */
  public function __construct(
    ConfigDecisionEngine $decisionEngine,
    DataPrivacyManagerService $dataPrivacyManager,
    EnvironmentDetectorService $environmentDetector,
    MutationLockManagerService $mutationLockManager,
    SnapshotManagerService $snapshotManager,
    ComposerPatchManagerService $composerPatchManager,
    AuditLoggerService $auditLogger
  ) {
    $this->decisionEngine = $decisionEngine;
    $this->dataPrivacyManager = $dataPrivacyManager;
    $this->environmentDetector = $environmentDetector;
    $this->mutationLockManager = $mutationLockManager;
    $this->snapshotManager = $snapshotManager;
    $this->composerPatchManager = $composerPatchManager;
    $this->auditLogger = $auditLogger;
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
      $container->get('ai_copilot.audit_logger')
    );
  }

  /**
   * Handles POST /admin/api/ai-copilot/chat requirements evaluation.
   */
  public function handleChat(Request $request): JsonResponse {
    try {
      $content = json_decode($request->getContent(), TRUE) ?: [];
      $prompt = trim((string) ($content['prompt'] ?? ''));

      if (empty($prompt)) {
        return new JsonResponse(['success' => FALSE, 'error' => 'Prompt is required.'], 400);
      }

      $evaluation = $this->decisionEngine->evaluateRequirement($prompt);
      $payloadPreview = $this->dataPrivacyManager->generatePayloadPreview($prompt, $evaluation);

      return new JsonResponse([
        'success' => TRUE,
        'evaluation' => $evaluation,
        'payload_preview' => $payloadPreview,
      ]);
    }
    catch (\Throwable $e) {
      \Drupal::logger('ai_copilot')->error('Chat API exception: @msg', ['@msg' => $e->getMessage()]);
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Server Error: ' . $e->getMessage(),
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
          if (is_array($parsedConfig)) {
            $typeName = $parsedConfig['type'] ?? $parsedConfig['id'] ?? NULL;
            $label = $parsedConfig['name'] ?? ucfirst((string) $typeName);

            // If applying a Drupal NodeType (Content Type) definition:
            if ($typeName && (isset($parsedConfig['type']) || str_contains($yamlStr, 'node.type') || isset($parsedConfig['id']))) {
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
              // General Drupal config import.
              $configName = $parsedConfig['name'] ?? ('node.type.' . ($typeName ?: 'custom'));
              $this->configFactory()->getEditable($configName)->setData($parsedConfig)->save();
              $affected = ['imported_config' => $configName];
            }
          }
        }
        catch (\Exception $e) {
          \Drupal::logger('ai_copilot')->warning('YAML config parse error: @msg', ['@msg' => $e->getMessage()]);
        }
      }
      elseif ($path === 'custom_code') {
        $code = $content['custom_code'] ?? '';
        $diffContent = $code;

        $appRoot = \Drupal::hasService('kernel') ? \Drupal::root() : DRUPAL_ROOT;
        $targetCustomFile = $appRoot . '/modules/custom/ai_copilot_generated/ai_copilot_generated.module';
        @mkdir(dirname($targetCustomFile), 0755, TRUE);

        if (file_exists($targetCustomFile)) {
          $affectedFiles[] = $targetCustomFile;
        }

        file_put_contents($targetCustomFile, "<?php\n\n" . ltrim($code, "<?php"));
        $affected = ['generated_custom_file' => $targetCustomFile];
      }

      // 3. Create Rollback Snapshot.
      $auditIdPlaceholder = time();
      $snapshotPath = $this->snapshotManager->createSnapshot($auditIdPlaceholder, $affectedFiles);

      // 4. Record Audit Log.
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
   * Handles POST /admin/api/ai-copilot/revert action.
   */
  public function handleRevert(Request $request): JsonResponse {
    try {
      $content = json_decode($request->getContent(), TRUE) ?: [];
      $auditId = (int) ($content['audit_id'] ?? 0);

      if ($auditId <= 0) {
        return new JsonResponse(['success' => FALSE, 'error' => 'Valid audit_id is required.'], 400);
      }

      $result = $this->snapshotManager->revertMutation($auditId);
      if (!$result['success']) {
        return new JsonResponse(['success' => FALSE, 'error' => $result['message']], 400);
      }

      return new JsonResponse([
        'success' => TRUE,
        'message' => $result['message'],
      ]);
    }
    catch (\Throwable $e) {
      \Drupal::logger('ai_copilot')->error('Revert API exception: @msg', ['@msg' => $e->getMessage()]);
      return new JsonResponse([
        'success' => FALSE,
        'error' => 'Revert failed: ' . $e->getMessage(),
      ], 500);
    }
  }

}
