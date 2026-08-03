<?php

namespace Drupal\Tests\contribot\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Kernel test for ContribMatcherService Semver hard filtering and scoring.
 *
 * @group contribot
 */
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
class ContribMatcherServiceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'contribot'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('contribot', ['contribot_contrib_index']);
  }

  /**
   * Tests matching candidates with Semver hard core filtering.
   */
  public function testMatchCandidatesWithSemverFilter(): void {
    $db = \Drupal::database();

    // 1. Seed compatible candidate (Drupal 11 compatible).
    $db->insert('contribot_contrib_index')
      ->fields([
        'project_name' => 'focal_point',
        'title' => 'Focal Point Crop',
        'description' => 'Crop images with focal point alignment.',
        'maintenance_status' => 'Actively maintained',
        'security_coverage' => 1,
        'usage_count' => 50000,
        'core_compatibility' => '^10.3 || ^11.0',
        'last_release' => time(),
        'readme_summary' => 'Focal point documentation',
        'indexed_at' => time(),
      ])
      ->execute();

    // 2. Seed incompatible candidate (Drupal 9 only).
    $db->insert('contribot_contrib_index')
      ->fields([
        'project_name' => 'old_legacy_crop',
        'title' => 'Old Legacy Crop',
        'description' => 'Crop images for Drupal 8 and 9.',
        'maintenance_status' => 'Unsupported',
        'security_coverage' => 0,
        'usage_count' => 10,
        'core_compatibility' => '^8 || ^9',
        'last_release' => time() - (5 * 365 * 86400),
        'readme_summary' => 'Legacy doc',
        'indexed_at' => time(),
      ])
      ->execute();

    /** @var \Drupal\contribot\Service\ContribMatcherService $matcher */
    $matcher = \Drupal::service('contribot.contrib_matcher');
    $results = $matcher->matchCandidates('focal point crop', '11.1.2', 5);

    $this->assertNotEmpty($results);
    $this->assertEquals('focal_point', $results[0]['project_name']);
    $this->assertGreaterThan(0.0, $results[0]['final_score']);

    // Verify incompatible legacy module was dropped by Semver filter.
    $projectNames = array_column($results, 'project_name');
    $this->assertNotContains('old_legacy_crop', $projectNames);
  }

}
