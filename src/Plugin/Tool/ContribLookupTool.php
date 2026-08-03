<?php

namespace Drupal\contribot\Plugin\Tool;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\tool\Plugin\ToolBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\contribot\Service\ContribMatcherService;

/**
 * Tool plugin for looking up candidate contrib modules.
 *
 * @Tool(
 *   id = "contribot_contrib_lookup",
 *   label = @Translation("Contribot Contrib Lookup"),
 *   description = @Translation("Look up candidate drupal.org contrib modules matching a requirement prompt.")
 * )
 */
class ContribLookupTool extends ToolBase implements ContainerFactoryPluginInterface {

  /**
   * Contrib matcher service.
   *
   * @var \Drupal\contribot\Service\ContribMatcherService
   */
  protected $contribMatcher;

  /**
   * Constructs a ContribLookupTool.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ContribMatcherService $contribMatcher) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->contribMatcher = $contribMatcher;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('contribot.contrib_matcher')
    );
  }

  /**
   * Executes the tool lookup.
   *
   * @param array $values
   *   Input values containing 'requirement' (string).
   *
   * @return array
   *   Matched candidates array.
   */
  public function execute(array $values = []): array {
    $requirement = (string) ($values['requirement'] ?? '');
    if (empty($requirement)) {
      return ['candidates' => [], 'error' => 'Requirement is required.'];
    }

    $candidates = $this->contribMatcher->matchCandidates($requirement, \Drupal::VERSION, 5);
    return [
      'success' => TRUE,
      'requirement' => $requirement,
      'candidates' => $candidates,
    ];
  }

}
