<?php

namespace Drupal\contribot\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Contribot settings for this site.
 */
class CopilotSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'contribot_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['contribot.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('contribot.settings');

    /** @var \Drupal\contribot\Service\EnvironmentDetectorService $envDetector */
    $envDetector = \Drupal::service('contribot.environment_detector');
    $isProduction = $envDetector->isProduction();

    if ($isProduction) {
      $this->messenger()->addWarning($this->t('Production environment detected! Site mutations are hard-disabled by default for safety.'));
    }

    $form['developer_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Developer Mode'),
      '#description' => $this->t('When enabled, the Contribot chat drawer will appear in the admin UI for users with the "Use Contribot" permission.'),
      '#default_value' => $config->get('developer_mode') ?? FALSE,
    ];

    $form['security_preset'] = [
      '#type' => 'select',
      '#title' => $this->t('Security Preset'),
      '#description' => $this->t('Controls what actions Contribot can perform.'),
      '#options' => [
        'read_only' => $this->t('Read-Only (Site introspection & advice only)'),
        'config_only' => $this->t('Config-Only (Allow applying configuration YAML changes only)'),
        'full_mutation' => $this->t('Full Mutation (Allow config, patches via Composer, and custom module generation)'),
      ],
      '#default_value' => $config->get('security_preset') ?: 'config_only',
    ];

    $form['llm_provider'] = [
      '#type' => 'select',
      '#title' => $this->t('LLM Provider'),
      '#description' => $this->t('Explicitly select which provider the API key below belongs to. Contribot never guesses this from the key format.'),
      '#options' => [
        '' => $this->t('- Select a provider -'),
        'gemini' => $this->t('Google Gemini'),
        'anthropic' => $this->t('Anthropic Claude'),
        'openai' => $this->t('OpenAI'),
      ],
      '#default_value' => $config->get('llm_provider') ?: '',
    ];

    $form['provider_key_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Key Module Credential ID'),
      '#description' => $this->t('Machine name of the key stored in Key module (e.g., anthropic_api_key or openai_api_key). Must match the provider selected above.'),
      '#default_value' => $config->get('provider_key_id') ?: '',
    ];

    $form['model_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Model Name Override'),
      '#description' => $this->t('Optional. Leave blank to use the default model for the selected provider (Gemini: gemini-2.0-flash, Anthropic: claude-3-5-sonnet-20241022, OpenAI: gpt-4o).'),
      '#default_value' => $config->get('model_name') ?: '',
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
    $this->config('contribot.settings')
      ->set('developer_mode', (bool) $form_state->getValue('developer_mode'))
      ->set('security_preset', $form_state->getValue('security_preset'))
      ->set('llm_provider', $form_state->getValue('llm_provider'))
      ->set('provider_key_id', trim((string) $form_state->getValue('provider_key_id')))
      ->set('model_name', trim((string) $form_state->getValue('model_name')))
      ->set('data_privacy_level', $form_state->getValue('data_privacy_level'))
      ->set('allow_production_mutation', (bool) $form_state->getValue('allow_production_mutation'))
      ->set('max_token_context', (int) $form_state->getValue('max_token_context'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
