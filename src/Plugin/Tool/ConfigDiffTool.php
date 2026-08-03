<?php

namespace Drupal\contribot\Plugin\Tool;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\tool\Plugin\ToolBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\contribot\Service\SiteContextAssembler;

/**
 * Tool plugin for evaluating site context & config diffs.
 *
 * @Tool(
 *   id = "contribot_config_diff",
 *   label = @Translation("Contribot Config Diff"),
 *   description = @Translation("Inspect site configuration schema and return relevant config diff context.")
 * )
 */
class ConfigDiffTool extends ToolBase implements ContainerFactoryPluginInterface {

  /**
   * Site context assembler.
   *
   * @var \Drupal\contribot\Service\SiteContextAssembler
   */
  protected $siteContextAssembler;

  /**
   * Constructs a ConfigDiffTool.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, SiteContextAssembler $siteContextAssembler) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->siteContextAssembler = $siteContextAssembler;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('contribot.site_context_assembler')
    );
  }

  /**
   * Executes the config diff tool.
   *
   * @param array $values
   *   Input options.
   *
   * @return array
   *   Site schema and active config context.
   */
  public function execute(array $values = []): array {
    $context = $this->siteContextAssembler->assembleContext('structure_only');
    return [
      'success' => TRUE,
      'schema_summary' => $context['scoped_schema'] ?? [],
      'active_modules' => array_keys($context['active_modules'] ?? []),
    ];
  }

}
