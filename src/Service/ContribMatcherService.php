<?php

namespace Drupal\ai_copilot\Service;

use Drupal\Core\Database\Connection;
use Composer\Semver\Semver;

/**
 * Service for matching user requirements against indexed contrib modules.
 */
class ContribMatcherService {

  /**
   * Database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Constructs a ContribMatcherService.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
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
    // 1. Fetch raw candidate rows from index.
    $query = $this->database->select('ai_copilot_contrib_index', 'c')
      ->fields('c');
    $records = $query->execute()->fetchAll();

    if (empty($records)) {
      return [];
    }

    // 2. Hard Core Compatibility Filtering using Composer\Semver\Semver.
    $compatibleRecords = [];
    foreach ($records as $record) {
      $constraint = !empty($record->core_compatibility) ? $record->core_compatibility : '^10 || ^11';
      try {
        if (Semver::satisfies($siteCoreVersion, $constraint)) {
          $compatibleRecords[] = $record;
        }
      }
      catch (\UnexpectedValueException $e) {
        // Fallback for non-standard semver strings.
        $compatibleRecords[] = $record;
      }
    }

    if (empty($compatibleRecords)) {
      return [];
    }

    // 3. Compute raw keyword relevance (BM25 surrogate / term frequency).
    $keywords = array_filter(explode(' ', strtolower(preg_replace('/[^\w\s]/', '', $requirement))));
    $scored = [];
    $rawScores = [];

    foreach ($compatibleRecords as $record) {
      $text = strtolower($record->title . ' ' . $record->description . ' ' . $record->readme_summary);
      $rawScore = 0.0;
      foreach ($keywords as $word) {
        if (strlen($word) > 2) {
          $count = substr_count($text, $word);
          $rawScore += ($count * 1.5);
        }
      }
      $rawScores[] = $rawScore;
      $record->_raw_score = $rawScore;
    }

    // 4. Min-Max Normalization for S_BM25_norm in [0.0, 1.0].
    $minScore = !empty($rawScores) ? min($rawScores) : 0.0;
    $maxScore = !empty($rawScores) ? max($rawScores) : 1.0;

    foreach ($compatibleRecords as &$record) {
      if ($maxScore > $minScore) {
        $sBm25Norm = ($record->_raw_score - $minScore) / ($maxScore - $minScore);
      }
      else {
        $sBm25Norm = ($maxScore > 0) ? 1.0 : 0.0;
      }

      // Relevance component (50% weight).
      $relevance = $sBm25Norm;

      // Security coverage (20% weight).
      $security = !empty($record->security_coverage) ? 1.0 : 0.0;

      // Usage log normalized (15% weight). Log scale base 10.
      $usage = !empty($record->usage_count) ? min(1.0, log10($record->usage_count) / 6.0) : 0.1;

      // Recency normalized (15% weight). Within last 2 years = 1.0.
      $twoYearsAgo = time() - (730 * 86400);
      $recency = ($record->last_release >= $twoYearsAgo) ? 1.0 : 0.5;

      // Weighted score formula.
      $finalScore = (0.50 * $relevance) + (0.20 * $security) + (0.15 * $usage) + (0.15 * $recency);

      // Flag abandoned or unsupported modules.
      $isAbandoned = (stristr($record->maintenance_status, 'unsupported') !== FALSE || stristr($record->maintenance_status, 'abandoned') !== FALSE);

      $scored[] = [
        'project_name' => $record->project_name,
        'title' => $record->title,
        'description' => $record->description,
        'match_percentage' => round($finalScore * 100, 1),
        'final_score' => round($finalScore, 4),
        'security_coverage' => (bool) $record->security_coverage,
        'usage_count' => (int) $record->usage_count,
        'maintenance_status' => $record->maintenance_status,
        'is_abandoned' => $isAbandoned,
        'core_compatibility' => $record->core_compatibility,
        'covered_features' => sprintf('Covers basic %s capabilities for requirement', $record->project_name),
        'missing_features' => 'May require custom patch or configuration tweaks for site-specific gaps.',
      ];
    }

    // Sort descending by final_score.
    usort($scored, function ($a, $b) {
      return $b['final_score'] <=> $a['final_score'];
    });

    return array_slice($scored, 0, $topN);
  }

}
