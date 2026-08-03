<?php

namespace Drupal\contribot\Exception;

/**
 * Thrown when a configured LLM provider call fails or is misconfigured.
 *
 * Callers should surface the message directly to the user rather than
 * silently substituting a fabricated response.
 */
class LlmProviderException extends \RuntimeException {

}
