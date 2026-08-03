<?php

namespace Drupal\contribot\Service;

use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Service for assembling structured site context snapshots for LLM prompts.
 */
class SiteContextAssembler {

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The data privacy manager service.
   *
   * @var \Drupal\contribot\Service\DataPrivacyManagerService
   */
  protected $dataPrivacyManager;

  /**
   * Constructs a SiteContextAssembler.
   */
  public function __construct(
    ModuleHandlerInterface $moduleHandler,
    ConfigFactoryInterface $configFactory,
    DataPrivacyManagerService $dataPrivacyManager,
  ) {
    $this->moduleHandler = $moduleHandler;
    $this->configFactory = $configFactory;
    $this->dataPrivacyManager = $dataPrivacyManager;
  }

  /**
   * Assembles a structured site context array for prompt injection.
   *
   * @param string $privacyLevel
   *   One of 'structure_only' or 'full_context'.
   * @param int $maxTokenCap
   *   Maximum token cap for config context (default 8000).
   *
   * @return array
   *   Structured site context metadata.
   */
  public function assembleContext(string $privacyLevel = 'structure_only', int $maxTokenCap = 8000): array {
    $appRoot = \Drupal::hasService('kernel') ? \Drupal::root() : DRUPAL_ROOT;

    $context = [
      'system' => [
        'drupal_version' => \Drupal::VERSION,
        'php_version' => PHP_VERSION,
        'db_driver' => \Drupal::database()->driver(),
        'db_version' => \Drupal::database()->version(),
      ],
      'active_modules' => $this->getActiveModules(),
      'custom_modules' => $this->getCustomModules($appRoot),
      'composer' => $this->getComposerContext($appRoot),
      'scoped_schema' => $this->getScopedSchemaConfig(),
    ];

    $sanitized = $this->dataPrivacyManager->sanitizeContext($context, $privacyLevel);

    // Truncate if token size exceeds max cap (approx 4 chars per token).
    $charLimit = $maxTokenCap * 4;
    $json = json_encode($sanitized);
    if (strlen($json) > $charLimit) {
      $sanitized['scoped_schema'] = [
        '_truncated' => TRUE,
        'notice' => 'Schema truncated to fit token budget.',
        'content_types' => array_keys($sanitized['scoped_schema']['node_types'] ?? []),
      ];
    }

    return $sanitized;
  }

  /**
   * Gets list of active modules and their versions.
   */
  protected function getActiveModules(): array {
    $modules = [];
    $extensions = \Drupal::service('extension.list.module')->getList();
    foreach ($extensions as $name => $extension) {
      if (!empty($extension->status)) {
        $modules[$name] = [
          'type' => $extension->info['package'] ?? 'Other',
          'version' => $extension->info['version'] ?? \Drupal::VERSION,
        ];
      }
    }
    return $modules;
  }

  /**
   * Discovers custom modules inside modules/custom.
   */
  protected function getCustomModules(string $appRoot): array {
    $customDir = $appRoot . '/modules/custom';
    if (!is_dir($customDir)) {
      return [];
    }

    $custom = [];
    $scanned = scandir($customDir);
    foreach ($scanned as $item) {
      if ($item !== '.' && $item !== '..' && is_dir($customDir . '/' . $item)) {
        $custom[] = $item;
      }
    }
    return $custom;
  }

  /**
   * Reads composer.json requirements and composer.lock versions.
   */
  protected function getComposerContext(string $appRoot): array {
    $composerJsonPath = dirname($appRoot) . '/composer.json';
    $composerLockPath = dirname($appRoot) . '/composer.lock';

    $context = [
      'require' => [],
      'locked' => [],
    ];

    if (file_exists($composerJsonPath)) {
      $json = json_decode(file_get_contents($composerJsonPath), TRUE);
      $context['require'] = $json['require'] ?? [];
    }

    if (file_exists($composerLockPath)) {
      $lock = json_decode(file_get_contents($composerLockPath), TRUE);
      if (isset($lock['packages'])) {
        foreach ($lock['packages'] as $pkg) {
          $context['locked'][$pkg['name']] = $pkg['version'];
        }
      }
    }

    return $context;
  }

  /**
   * Gets active content types, field storage, and view mode config schema.
   */
  protected function getScopedSchemaConfig(): array {
    $schema = [
      'node_types' => [],
      'field_storages' => [],
    ];

    $names = $this->configFactory->listAll('node.type.');
    foreach ($names as $name) {
      $config = $this->configFactory->get($name)->get();
      unset($config['uuid'], $config['_core']);
      $schema['node_types'][$config['type'] ?? $name] = $config['name'] ?? $name;
    }

    $fieldStorageNames = array_slice($this->configFactory->listAll('field.storage.'), 0, 30);
    foreach ($fieldStorageNames as $name) {
      $config = $this->configFactory->get($name)->get();
      unset($config['uuid'], $config['_core']);
      $schema['field_storages'][$config['id'] ?? $name] = [
        'type' => $config['type'] ?? '',
        'entity_type' => $config['entity_type'] ?? '',
      ];
    }

    return $schema;
  }

}
