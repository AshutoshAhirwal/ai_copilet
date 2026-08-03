<?php

namespace Drupal\contribot\Service;

use Drupal\Core\TempStore\PrivateTempStoreFactory;

/**
 * Manages per-user multi-turn conversation history via PrivateTempStore.
 */
class ConversationSessionService {

  /**
   * TempStore instance keyed to the contribot_chat collection.
   *
   * @var \Drupal\Core\TempStore\PrivateTempStore
   */
  protected $store;

  /**
   * Constructs a ConversationSessionService.
   */
  public function __construct(PrivateTempStoreFactory $tempStoreFactory) {
    $this->store = $tempStoreFactory->get('contribot_chat');
  }

  /**
   * Returns conversation history for a session.
   *
   * @param string $sessionId
   *   Frontend-generated session UUID (sanitized before use).
   *
   * @return array
   *   Array of {role: string, content: string} messages, oldest first.
   */
  public function getHistory(string $sessionId): array {
    return $this->store->get('h_' . $sessionId) ?? [];
  }

  /**
   * Appends a message to conversation history (max 20 messages retained).
   *
   * @param string $sessionId
   *   Frontend-generated session UUID.
   * @param string $role
   *   'User' or 'assistant' role identifier.
   * @param string $content
   *   Message text.
   */
  public function addMessage(string $sessionId, string $role, string $content): void {
    $history = $this->getHistory($sessionId);
    $history[] = ['role' => $role, 'content' => $content];
    if (count($history) > 20) {
      $history = array_slice($history, -20);
    }
    $this->store->set('h_' . $sessionId, $history);
  }

  /**
   * Replaces the full conversation history for a session.
   *
   * Used by the agent loop to persist the updated history after each turn.
   *
   * @param string $sessionId
   *   Session identifier.
   * @param array $history
   *   Full history array to store (Gemini-native format).
   */
  public function saveHistory(string $sessionId, array $history): void {
    if (count($history) > 20) {
      $history = array_slice($history, -20);
    }
    $this->store->set('h_' . $sessionId, $history);
  }

  /**
   * Deletes conversation history for a session.
   *
   * @param string $sessionId
   *   Frontend-generated session UUID.
   */
  public function clearHistory(string $sessionId): void {
    $this->store->delete('h_' . $sessionId);
  }

}
