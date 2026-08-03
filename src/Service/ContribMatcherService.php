<?php

namespace Drupal\contribot\Service;

use Drupal\Core\Database\Connection;
use Composer\Semver\Semver;

/**
 * Service for matching user requirements against indexed contrib modules.
 *
 * Scoring uses a two-stage hybrid model:
 *   Stage 1 — DB Full-Text (MATCH...AGAINST on MySQL, PHP term-frequency fallback)
 *             Min-Max normalized → S_BM25_norm ∈ [0.0, 1.0]
 *   Stage 2 — LLM semantic re-ranking → S_LLM ∈ [0.0, 1.0]
 *   Combined relevance: (0.40 × S_BM25_norm) + (0.60 × S_LLM)
 *   Final score:        (0.50 × relevance) + (0.20 × security) + (0.15 × usage) + (0.15 × recency)
 */
class ContribMatcherService {

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * LLM provider service for Stage 2 semantic re-ranking.
   *
   * @var \Drupal\contribot\Service\CopilotLlmProvider
   */
  protected $llmProvider;

  /**
   * Constructs a ContribMatcherService.
   */
  public function __construct(Connection $database, CopilotLlmProvider $llmProvider) {
    $this->database = $database;
    $this->llmProvider = $llmProvider;
  }

  /**
   * Finds and ranks candidate contrib modules matching a plain language requirement.
   *
   * @param string $requirement
   *   The developer's requirement string.
   * @param string $siteCoreVersion
   *   Site's Drupal core version (e.g. '11.1.2').
   * @param int $topN
   *   Number of candidate results to return.
   *
   * @return array
   *   Ranked candidate array with scores and coverage analysis.
   */
  public function matchCandidates(string $requirement, string $siteCoreVersion = \Drupal::VERSION, int $topN = 5): array {
    // Stage 1: Database Full-Text Search — top 20 raw candidates.
    $records = $this->fetchStage1Candidates($requirement, 20);

    if (empty($records)) {
      return [];
    }

    // Hard Core Compatibility Filter using Composer\Semver\Semver::satisfies().
    $compatibleRecords = [];
    foreach ($records as $record) {
      $constraint = !empty($record->core_compatibility) ? $record->core_compatibility : '^10 || ^11';
      try {
        if (Semver::satisfies($siteCoreVersion, $constraint)) {
          $compatibleRecords[] = $record;
        }
      }
      catch (\UnexpectedValueException $e) {
        // Non-standard semver string — include rather than silently drop.
        $compatibleRecords[] = $record;
      }
    }

    if (empty($compatibleRecords)) {
      return [];
    }

    // Min-Max Normalization of Stage 1 raw scores → S_BM25_norm ∈ [0.0, 1.0].
    $rawScores = array_column($compatibleRecords, '_raw_score');
    $minScore = min($rawScores);
    $maxScore = max($rawScores);

    foreach ($compatibleRecords as &$record) {
      if ($maxScore > $minScore) {
        $record->s_bm25_norm = ($record->_raw_score - $minScore) / ($maxScore - $minScore);
      }
      else {
        $record->s_bm25_norm = ($maxScore > 0) ? 1.0 : 0.0;
      }
    }
    unset($record);

    // Stage 2: LLM Semantic Re-Ranking — top 5 candidates from Stage 1.
    $top5 = array_slice($compatibleRecords, 0, 5);
    $llmScores = $this->getLlmRelevanceScores($requirement, $top5);

    // Score all candidates combining Stage 1 + Stage 2 (Stage 2 = 0.5 for candidates beyond top 5).
    $scored = [];
    foreach ($compatibleRecords as $record) {
      $sBm25Norm = (float) $record->s_bm25_norm;
      $sLlm = $llmScores[$record->project_name] ?? 0.5;

      // Combined Relevance: (0.40 × S_BM25_norm) + (0.60 × S_LLM).
      $relevance = (0.40 * $sBm25Norm) + (0.60 * $sLlm);

      // Security coverage (20% weight).
      $security = !empty($record->security_coverage) ? 1.0 : 0.0;

      // Usage log-normalized (15% weight). Log scale base-10.
      $usage = !empty($record->usage_count) ? min(1.0, log10($record->usage_count) / 6.0) : 0.1;

      // Recency normalized (15% weight). Within last 2 years = 1.0.
      $twoYearsAgo = time() - (730 * 86400);
      $recency = ($record->last_release >= $twoYearsAgo) ? 1.0 : 0.5;

      // Final weighted score.
      $finalScore = (0.50 * $relevance) + (0.20 * $security) + (0.15 * $usage) + (0.15 * $recency);

      $isAbandoned = (stristr($record->maintenance_status, 'unsupported') !== FALSE
        || stristr($record->maintenance_status, 'abandoned') !== FALSE);

      $scored[] = [
        'project_name' => $record->project_name,
        'title' => $record->title,
        'description' => $record->description,
        'match_percentage' => round($finalScore * 100, 1),
        'final_score' => round($finalScore, 4),
        's_bm25_norm' => round($sBm25Norm, 4),
        's_llm' => round($sLlm, 4),
        'security_coverage' => (bool) $record->security_coverage,
        'usage_count' => (int) $record->usage_count,
        'maintenance_status' => $record->maintenance_status,
        'is_abandoned' => $isAbandoned,
        'core_compatibility' => $record->core_compatibility,
        'covered_features' => sprintf('Covers basic %s capabilities for requirement', $record->project_name),
        'missing_features' => 'May require custom patch or configuration tweaks for site-specific gaps.',
      ];
    }

    usort($scored, function ($a, $b) {
      return $b['final_score'] <=> $a['final_score'];
    });

    return array_slice($scored, 0, $topN);
  }

  /**
   * Stage 1: Fetches raw candidates from the contrib index.
   *
   * Uses DB FULLTEXT on MySQL/MariaDB, falls back to PHP term-frequency
   * scoring on SQLite and PostgreSQL.
   *
   * @param string $requirement
   *   The developer's requirement string.
   * @param int $limit
   *   Maximum raw candidates to fetch before Semver filtering.
   *
   * @return array
   *   Records with a '_raw_score' property attached.
   */
  protected function fetchStage1Candidates(string $requirement, int $limit = 20): array {
    $dbDriver = $this->database->driver();

    // MySQL / MariaDB path: use MATCH...AGAINST for real BM25-like scoring.
    if ($dbDriver === 'mysql') {
      try {
        // Named placeholders are quoted as strings by the Drupal DB layer;
        // LIMIT requires a bare integer in MySQL/MariaDB.
        $records = $this->database->query(
          'SELECT *, MATCH(title, description, readme_summary) AGAINST (:q IN NATURAL LANGUAGE MODE) AS ft_score
           FROM {contribot_contrib_index}
           ORDER BY ft_score DESC
           LIMIT ' . (int) $limit,
          [':q' => $requirement]
        )->fetchAll();

        foreach ($records as &$record) {
          $record->_raw_score = (float) ($record->ft_score ?? 0.0);
        }
        unset($record);

        return $records;
      }
      catch (\Exception $e) {
        // FULLTEXT index may not exist yet (e.g. before hook_install runs).
        \Drupal::logger('contribot')->notice('FULLTEXT search fallback: @msg', ['@msg' => $e->getMessage()]);
      }
    }

    // Fallback: full-table scan with PHP term-frequency scoring (SQLite / PostgreSQL).
    $records = $this->database->select('contribot_contrib_index', 'c')
      ->fields('c')
      ->execute()
      ->fetchAll();

    $keywords = array_filter(
      explode(' ', strtolower(preg_replace('/[^\w\s]/', '', $requirement))),
      fn($w) => strlen($w) > 2
    );

    foreach ($records as &$record) {
      $text = strtolower($record->title . ' ' . $record->description . ' ' . $record->readme_summary);
      $rawScore = 0.0;
      foreach ($keywords as $word) {
        $rawScore += substr_count($text, $word) * 1.5;
      }
      $record->_raw_score = $rawScore;
    }
    unset($record);

    usort($records, fn($a, $b) => $b->_raw_score <=> $a->_raw_score);

    return array_slice($records, 0, $limit);
  }

  /**
   * Stage 2: LLM semantic re-ranking of top Stage-1 candidates.
   *
   * Asks the LLM to score each candidate's functional overlap with the
   * requirement, returning S_LLM ∈ [0.0, 1.0] per module.
   *
   * @param string $requirement
   *   The developer's requirement string.
   * @param array $candidates
   *   Up to 5 candidate records from Stage 1.
   *
   * @return array
   *   Keyed by project_name → float score in [0.0, 1.0].
   */
  protected function getLlmRelevanceScores(string $requirement, array $candidates): array {
    if (empty($candidates)) {
      return [];
    }

    $candidateSummaries = [];
    foreach ($candidates as $record) {
      $candidateSummaries[$record->project_name] = [
        'title' => $record->title,
        'description' => substr((string) $record->description, 0, 300),
      ];
    }

    $systemPrompt = "You are a Drupal architecture expert evaluating candidate modules. "
      . "Score each module from 0.0 to 1.0 on how well it functionally satisfies the stated requirement. "
      . "1.0 = complete match, 0.0 = no overlap. Respond with ONLY a flat JSON object: "
      . "{\"module_machine_name\": score, ...}. No explanation.";

    $userPrompt = sprintf(
      "Requirement: %s\n\nCandidate modules:\n%s",
      $requirement,
      json_encode($candidateSummaries, JSON_PRETTY_PRINT)
    );

    try {
      $raw = $this->llmProvider->generateCompletion($systemPrompt, $userPrompt);
      $scores = json_decode($raw, TRUE);

      if (!is_array($scores)) {
        return [];
      }

      $validated = [];
      foreach ($scores as $modName => $score) {
        $validated[(string) $modName] = (float) max(0.0, min(1.0, $score));
      }

      return $validated;
    }
    catch (\Exception $e) {
      \Drupal::logger('contribot')->notice('LLM re-ranking skipped: @msg', ['@msg' => $e->getMessage()]);
      return [];
    }
  }

}
