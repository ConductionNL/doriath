<?php

/**
 * Keepiq Prune Secret Versions Job
 *
 * Nightly retention pruning of secret version history
 * (secret-version-history §4.2): per-secret count-based pruning plus a
 * bounded age-based batch. The live head is a `keepiq_secrets` row and
 * is structurally untouchable here.
 *
 * @category BackgroundJob
 * @package  OCA\Keepiq\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\BackgroundJob;

use DateTime;
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Db\SecretVersionMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Nightly prune of secret versions beyond the retention count/age.
 */
class PruneSecretVersionsJob extends TimedJob {
	/**
	 * Constructor for PruneSecretVersionsJob.
	 *
	 * @param ITimeFactory $time The time factory
	 * @param SecretVersionMapper $mapper The version mapper
	 * @param IAppConfig $appConfig The app config
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private SecretVersionMapper $mapper,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
		$this->setInterval(seconds: 86400);
	}//end __construct()

	/**
	 * Run the retention prune (fail-soft).
	 *
	 * @param mixed $argument Unused job argument
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $argument is mandated by
	 *   OCP\BackgroundJob\TimedJob::run(); this job carries no cron payload.
	 *
	 * @spec openspec/changes/secret-version-history/specs/secret-version-history/spec.md
	 */
	protected function run($argument): void {
		try {
			$keep = max(1, $this->appConfig->getValueInt(Application::APP_ID, 'version_retention_count', 20));
			$pruned = 0;
			foreach ($this->mapper->findSecretIdsWithVersions() as $secretId) {
				$pruned += $this->mapper->pruneByCount(secretId: (string)$secretId, keep: $keep);
			}

			$days = $this->appConfig->getValueInt(Application::APP_ID, 'version_retention_days', 365);
			if ($days > 0) {
				$cutoff = new DateTime('-' . $days . ' days');
				$pruned += $this->mapper->pruneOlderThan(cutoff: $cutoff);
			}

			if ($pruned > 0) {
				$this->logger->info(
					'Keepiq: pruned ' . $pruned . ' secret versions beyond retention',
					['app' => Application::APP_ID]
				);
			}
		} catch (Throwable $exception) {
			$this->logger->warning(
				'Keepiq: version prune failed: ' . $exception->getMessage(),
				['app' => Application::APP_ID]
			);
		}//end try
	}//end run()
}//end class
