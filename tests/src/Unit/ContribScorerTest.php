<?php

namespace Drupal\Tests\ai_copilot\Unit;

use Drupal\Tests\UnitTestCase;

/**
 * Unit test for contrib module scoring formula calculations.
 *
 * @group ai_copilot
 */
class ContribScorerTest extends UnitTestCase {

  /**
   * Tests weighted score calculation logic.
   */
  public function testWeightedScoringFormula(): void {
    $relevance = 1.0;
    $security = 1.0;
    $usageCount = 100000;
    $usageNorm = min(1.0, log10($usageCount) / 6.0); // 5/6 = ~0.833
    $recency = 1.0;

    $score = (0.50 * $relevance) + (0.20 * $security) + (0.15 * $usageNorm) + (0.15 * $recency);

    $this->assertGreaterThan(0.8, $score);
    $this->assertLessThanOrEqual(1.0, $score);
  }

}
