<?php

namespace Drupal\contribot\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Process\Process;

/**
 * Processes queued composer update jobs for Contribot patches.
 *
 * Runs `composer update drupal/{package}` via Symfony Process array arguments
 * so cweagans/composer-patches applies the registered patch entry without
 * blocking the HTTP request that triggered the Apply action.
 *
 * @QueueWorker(
 *   id = "contribot_composer_queue",
 *   title = @Translation("Contribot Composer Update"),
 *   cron = {"time" = 60}
 * )
 */
class ComposerUpdateWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Drupal app root.
   *
   * @var string
   */
  protected string $appRoot;

  /**
   * Constructs a ComposerUpdateWorker.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, string $appRoot) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->appRoot = $appRoot;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('app.root')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $package = (string) ($data['package'] ?? '');
    if (empty($package)) {
      return;
    }

    $rootDir = dirname($this->appRoot);

    // Strict array-argument usage — NO shell string concatenation.
    $process = new Process(
      ['composer', 'update', '--no-interaction', '--no-scripts', $package],
      $rootDir
    );
    $process->setTimeout(300);
    $process->run();

    if (!$process->isSuccessful()) {
      $stderr = trim($process->getErrorOutput() ?: $process->getOutput());
      \Drupal::logger('contribot')->error(
        'Composer update failed for @package: @err',
        ['@package' => $package, '@err' => $stderr]
      );

      // Re-throw so the queue system can retry or log the failure.
      throw new \RuntimeException(sprintf('composer update %s failed: %s', $package, $stderr));
    }

    \Drupal::logger('contribot')->info(
      'Composer update succeeded for @package.',
      ['@package' => $package]
    );
  }

}
