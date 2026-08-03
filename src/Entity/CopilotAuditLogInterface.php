<?php

namespace Drupal\contribot\Entity;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Interface for CopilotAuditLog entity.
 */
interface CopilotAuditLogInterface extends ContentEntityInterface {

  /**
   * Gets the audit log requirement prompt.
   *
   * @return string
   *   The developer's original requirement prompt.
   */
  public function getPrompt(): string;

  /**
   * Gets the architectural path chosen.
   *
   * @return string
   *   One of: config_only, contrib_patch, custom_code.
   */
  public function getChosenPath(): string;

  /**
   * Gets the audit log status.
   *
   * @return string
   *   One of: applied, rejected, edited, reverted.
   */
  public function getStatus(): string;

  /**
   * Sets the audit log status.
   *
   * @param string $status
   *   One of: applied, rejected, edited, reverted.
   *
   * @return \Drupal\contribot\Entity\CopilotAuditLogInterface
   *   The current entity.
   */
  public function setStatus(string $status): CopilotAuditLogInterface;

}
