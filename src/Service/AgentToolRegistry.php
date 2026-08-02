<?php

namespace Drupal\ai_copilot\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Registry of read-only agent tools available during the conversation loop.
 *
 * Only strictly read-only tools belong here. Any mutation (install, apply,
 * config import) must go through the separate human-approval UI flow.
 */
class AgentToolRegistry {

  /**
   * Site context assembler.
   *
   * @var \Drupal\ai_copilot\Service\SiteContextAssembler
   */
  protected $siteContextAssembler;

  /**
   * Contrib matcher service.
   *
   * @var \Drupal\ai_copilot\Service\ContribMatcherService
   */
  protected $contribMatcher;

  /**
   * Config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs an AgentToolRegistry.
   */
  public function __construct(
    SiteContextAssembler $siteContextAssembler,
    ContribMatcherService $contribMatcher,
    ConfigFactoryInterface $configFactory,
  ) {
    $this->siteContextAssembler = $siteContextAssembler;
    $this->contribMatcher = $contribMatcher;
    $this->configFactory = $configFactory;
  }

  /**
   * Returns tool declarations in Gemini functionDeclarations format.
   *
   * Compatible with sendConversation()'s $tools parameter.
   *
   * @return array
   *   Array of tool declaration objects.
   */
  public function getDeclarations(): array {
    return [
      [
        'name' => 'get_site_context',
        'description' => 'Reads the current Drupal site configuration, active modules, content types,'
        . ' and fields. Call this first to understand what already exists on the site.',
        'parameters' => [
          'type' => 'object',
          'properties' => new \stdClass(),
          'required' => [],
        ],
      ],
      [
        'name' => 'search_contrib_modules',
        'description' => 'Searches the indexed Drupal contrib module database for modules matching a query.'
        . ' Returns ranked candidates with relevance scores, security coverage, and usage counts.',
        'parameters' => [
          'type' => 'object',
          'properties' => [
            'query' => [
              'type' => 'string',
              'description' => 'Natural language description of the functionality you need (e.g. "image crop focal point").',
            ],
          ],
          'required' => ['query'],
        ],
      ],
    ];
  }

  /**
   * Executes a named tool with the provided arguments.
   *
   * @param string $name
   *   Tool name (must match a declaration from getDeclarations()).
   * @param array $args
   *   Arguments as provided by the LLM.
   *
   * @return array
   *   Tool result suitable for embedding in a functionResponse.
   *
   * @throws \InvalidArgumentException
   *   When the tool name is unknown.
   */
  public function execute(string $name, array $args): array {
    switch ($name) {
      case 'get_site_context':
        return $this->runGetSiteContext();

      case 'search_contrib_modules':
        $query = (string) ($args['query'] ?? '');
        if (empty($query)) {
          return ['error' => 'query parameter is required for search_contrib_modules'];
        }
        return $this->runSearchContribModules($query);

      default:
        throw new \InvalidArgumentException(sprintf('Unknown agent tool: %s', $name));
    }
  }

  /**
   * Runs the get_site_context tool.
   *
   * @return array
   *   Subset of site context safe to pass to the LLM.
   */
  protected function runGetSiteContext(): array {
    $privacyLevel = $this->configFactory->get('ai_copilot.settings')->get('data_privacy_level') ?: 'structure_only';
    $context = $this->siteContextAssembler->assembleContext($privacyLevel);

    return [
      'drupal_core_version' => \Drupal::VERSION,
      'active_modules' => $context['active_modules'] ?? [],
      'content_types' => $context['scoped_schema']['node_types'] ?? [],
      'fields_summary' => $context['scoped_schema']['field_storages'] ?? [],
    ];
  }

  /**
   * Runs the search_contrib_modules tool.
   *
   * @param string $query
   *   Natural language search query.
   *
   * @return array
   *   Ranked candidate list.
   */
  protected function runSearchContribModules(string $query): array {
    $candidates = $this->contribMatcher->matchCandidates($query, \Drupal::VERSION, 5);
    return ['candidates' => $candidates, 'query' => $query];
  }

}
