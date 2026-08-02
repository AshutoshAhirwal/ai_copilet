<?php

namespace Drupal\ai_copilot\Service;

/**
 * Service for data privacy filtering and outbound LLM payload inspection.
 */
class DataPrivacyManagerService {

  /**
   * Filters site context according to the privacy level.
   *
   * @param array $rawContext
   *   The raw site context collected from site inspection tools.
   * @param string $privacyLevel
   *   One of 'structure_only' or 'full_context'.
   *
   * @return array
   *   The privacy-sanitized context array.
   */
  public function sanitizeContext(array $rawContext, string $privacyLevel = 'structure_only'): array {
    if ($privacyLevel === 'full_context') {
      return $rawContext;
    }

    // Default: 'structure_only'
    // Remove all node content, field values, user records, and sensitive passwords/tokens.
    $sanitized = $rawContext;

    if (isset($sanitized['config'])) {
      foreach ($sanitized['config'] as $key => &$configData) {
        if (is_array($configData)) {
          unset($configData['uuid'], $configData['_core'], $configData['secret'], $configData['key']);
        }
      }
    }

    // Strip content entity values if present in context.
    unset($sanitized['nodes'], $sanitized['users'], $sanitized['taxonomy_terms'], $sanitized['content_samples']);

    return $sanitized;
  }

  /**
   * Generates a human-readable JSON preview of the exact outbound LLM payload.
   *
   * @param string $userPrompt
   *   The developer's prompt.
   * @param array $context
   *   The sanitized context array.
   *
   * @return string
   *   Pretty-printed JSON payload.
   */
  public function generatePayloadPreview(string $userPrompt, array $context): string {
    $payload = [
      'security_wrapper' => '<untrusted_external_contrib_data> system directive active',
      'user_prompt' => $userPrompt,
      'site_context' => $context,
    ];

    return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  }

}
