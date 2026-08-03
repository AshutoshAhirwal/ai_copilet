<?php

namespace Drupal\contribot\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Defines the CopilotAuditLog content entity class.
 */
#[ContentEntityType(
  id: 'copilot_audit_log',
  label: new TranslatableMarkup('Contribot Audit Log'),
  label_collection: new TranslatableMarkup('Contribot Audit Logs'),
  base_table: 'contribot_audit_log',
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
    'label' => 'requirement_prompt',
    'owner' => 'uid',
  ],
)]
class CopilotAuditLog extends ContentEntityBase implements CopilotAuditLogInterface {

  /**
   * {@inheritdoc}
   */
  public function getPrompt(): string {
    return (string) $this->get('requirement_prompt')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getChosenPath(): string {
    return (string) $this->get('chosen_path')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getStatus(): string {
    return (string) $this->get('status')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setStatus(string $status): CopilotAuditLogInterface {
    $this->set('status', $status);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('User'))
      ->setDescription(t('The developer user who approved or triggered the action.'))
      ->setSetting('target_type', 'user')
      ->setDefaultValueCallback(static::class . '::getCurrentUserId');

    $fields['requirement_prompt'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Requirement Prompt'))
      ->setDescription(t('The prompt submitted by the developer.'))
      ->setRequired(TRUE);

    $fields['chosen_path'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Chosen Path'))
      ->setDescription(t('Architectural path: config_only, contrib_patch, custom_code.'))
      ->setSetting('max_length', 32)
      ->setRequired(TRUE);

    $fields['affected_config_or_files'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Affected Config / Files'))
      ->setDescription(t('JSON serialized array of affected config keys or files.'));

    $fields['diff_content'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Diff Content'))
      ->setDescription(t('YAML diff, patch content, or custom code diff.'));

    $fields['snapshot_path'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Snapshot Path'))
      ->setSetting('max_length', 255);

    $fields['status'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Status'))
      ->setDescription(t('Action status: applied, rejected, edited, reverted.'))
      ->setSetting('max_length', 32)
      ->setDefaultValue('applied');

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'))
      ->setDescription(t('The time that the audit log entry was created.'));

    return $fields;
  }

  /**
   * Helper callback for default user ID.
   */
  public static function getCurrentUserId() {
    return [\Drupal::currentUser()->id()];
  }

}
