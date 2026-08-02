<?php

namespace Drupal\ai_copilot\Entity;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Interface for CopilotAuditLog entity.
 */
interface CopilotAuditLogInterface extends ContentEntityInterface {

  /**
   * Gets the audit log requirement prompt.
   *
   * @return string
   */
  public function getPrompt(): string;

  /**
   * Gets the architectural path chosen.
   *
   * @return string
   */
  public function getChosenPath(): string;

  /**
   * Gets the audit log status.
   *
   * @return string
   */
  public function getStatus(): string;

  /**
   * Sets the audit log status.
   *
   * @param string $status
   *
   * @return \Drupal\ai_copilot\Entity\CopilotAuditLogInterface
   */
  public function setStatus(string $status): CopilotAuditLogInterface;

}
