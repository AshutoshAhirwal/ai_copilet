<?php

namespace Drupal\ai_copilot\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure AI Copilot settings for this site.
 */
class CopilotSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'ai_copilot_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['ai_copilot.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('ai_copilot.settings');

    /** @var \Drupal\ai_copilot\Service\EnvironmentDetectorService $envDetector */
    $envDetector = \Drupal::service('ai_copilot.environment_detector');
    $isProduction = $envDetector->isProduction();

    if ($isProduction) {
      $this->messenger()->addWarning($this->t('Production environment detected! Site mutations are hard-disabled by default for safety.'));
    }

    $form['developer_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Developer Mode'),
      '#description' => $this->t('When enabled, the AI Copilot chat drawer will appear in the admin UI for users with the "Use AI Copilot" permission.'),
      '#default_value' => $config->get('developer_mode') ?? FALSE,
    ];

    $form['security_preset'] = [
      '#type' => 'select',
      '#title' => $this->t('Security Preset'),
      '#description' => $this->t('Controls what actions AI Copilot can perform.'),
      '#options' => [
        'read_only' => $this->t('Read-Only (Site introspection & advice only)'),
        'config_only' => $this->t('Config-Only (Allow applying configuration YAML changes only)'),
        'full_mutation' => $this->t('Full Mutation (Allow config, patches via Composer, and custom module generation)'),
      ],
      '#default_value' => $config->get('security_preset') ?: 'config_only',
    ];

    $form['provider_key_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Key Module Credential ID'),
      '#description' => $this->t('Machine name of the key stored in Key module (e.g., anthropic_api_key or openai_api_key).'),
      '#default_value' => $config->get('provider_key_id') ?: '',
    ];

    $form['data_privacy_level'] = [
      '#type' => 'radios',
      '#title' => $this->t('Data Privacy & Outbound Payload Filtering'),
      '#description' => $this->t('Controls what site context data is transmitted to external LLMs.'),
      '#options' => [
        'structure_only' => $this->t('Structure Only (Recommended): Sends entity definitions, field machine names, module lists, and schema metadata. All node content, user records, and field values are stripped.'),
        'full_context' => $this->t('Full Context: Includes content entity structure and sampled data.'),
      ],
      '#default_value' => $config->get('data_privacy_level') ?: 'structure_only',
    ];

    $form['allow_production_mutation'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Allow Mutations in Production Environment'),
      '#description' => $this->t('WARNING: Enabling this allows human-approved site mutations on production environments.'),
      '#default_value' => $config->get('allow_production_mutation') ?? FALSE,
      '#disabled' => !$isProduction,
    ];

    $form['max_token_context'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum Token Context Cap'),
      '#description' => $this->t('Maximum token context size for site context prompts (default: 32000).'),
      '#default_value' => $config->get('max_token_context') ?: 32000,
      '#min' => 4000,
      '#max' => 128000,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('ai_copilot.settings')
      ->set('developer_mode', (bool) $form_state->getValue('developer_mode'))
      ->set('security_preset', $form_state->getValue('security_preset'))
      ->set('provider_key_id', trim((string) $form_state->getValue('provider_key_id')))
      ->set('data_privacy_level', $form_state->getValue('data_privacy_level'))
      ->set('allow_production_mutation', (bool) $form_state->getValue('allow_production_mutation'))
      ->set('max_token_context', (int) $form_state->getValue('max_token_context'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
