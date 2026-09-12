<?php

/**
 * Keepiq Migrate App Config Keys Repair Step
 *
 * Repair step that carries this app's stored `IAppConfig` values across the
 * `doriath` -> `keepiq` app-id rename.
 *
 * Nextcloud namespaces `IAppConfig` by app id at the storage layer
 * (`oc_appconfig.appid`), so renaming `<id>` does not rename the rows — it
 * makes every previously stored value unreachable, because the app now asks
 * for them under a different app id. There is no in-place app-id upgrade in
 * Nextcloud: the new id is simply a different app. This step therefore copies
 * each value from the old namespace to the new one.
 *
 * WHY THIS MATTERS PARTICULARLY HERE. This app is a secrets vault, and several
 * of the copied keys are security floors rather than conveniences:
 * `master_password_min_length`, `master_password_min_score`,
 * `min_zxcvbn_score`, `block_on_hibp_hit` and `policy_exempt_types` are the
 * org password policy; `ca_status` is the certificate authority's health
 * marker; `audit_retention_days` decides how long the audit trail is kept.
 * Losing them does not error — every reader carries a default, so the instance
 * silently reverts to the shipped floor. An admin who RAISED a floor would
 * have it quietly lowered again.
 *
 * WHAT THIS STEP DOES NOT TOUCH. No secret, key or certificate is moved here:
 * the vault lives in this app's own tables (`keepiq_secrets`,
 * `keepiq_enc_suites`, `keepiq_ca_certs`, …), whose names deliberately keep
 * the old prefix precisely so that no rename can orphan them. See the comment
 * in `appinfo/info.xml`.
 *
 * WHY EVERY KEY RATHER THAN A FIXED LIST. `AdminSettingsService` writes its
 * admin keys from a defaults map, but that is not the whole stored set —
 * `ComplianceReportService` caches metrics under `compliance_metrics_*`,
 * `CertificateAuthorityService` writes `ca_status`, and past releases have
 * written keys this app no longer reads. Enumerating `IAppConfig::getKeys()`
 * is exhaustive by construction and cannot drift out of date the way a
 * hardcoded list does.
 *
 * SAFETY. Idempotent and non-destructive:
 *   - a key is copied only when the old value is non-empty AND the new
 *     namespace does not already hold a value, so an admin edit made after the
 *     rename is never clobbered and a second run is a no-op;
 *   - the old `doriath` rows are never deleted, so a rollback to the previous
 *     app id still finds its configuration intact;
 *   - values round-trip as raw strings. `IAppConfig` stores every value as a
 *     string and the typed accessors only coerce on read, so a string
 *     round-trip cannot lose or corrupt a value written by a typed setter;
 *   - every failure is logged and the loop continues. A repair step that
 *     throws aborts the install, and a config value that could not be copied
 *     is not worth failing an install over — the app falls back to its
 *     defaults and the admin can re-enter the setting.
 *
 * Registered under BOTH `<install>` and `<post-migration>` in
 * `appinfo/info.xml`, and before `BootstrapCertificateAuthority` and
 * `InitializeSettings` in both — see the ordering comment there.
 *
 * @category Repair
 * @package  OCA\Keepiq\Repair
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Repair;

use OCA\Keepiq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copy every stored IAppConfig value from the doriath namespace to keepiq.
 */
class MigrateAppConfigKeys implements IRepairStep {
	/**
	 * The app-config namespace this app used before the rename.
	 *
	 * Deliberately the OLD app id. This constant is one of the few places in
	 * the app that is supposed to still say `doriath`.
	 *
	 * @var string
	 */
	private const OLD_APP_ID = 'doriath';

	/**
	 * Config keys Nextcloud owns for every app. These MUST NOT be copied.
	 *
	 * `AppManager::enableApp()` writes `enabled` through the deprecated
	 * `IAppConfig::setValue()`, which stores type MIXED. Copying it here with
	 * `setValueString()` stores type STRING, and the next `app:enable` then
	 * fails with an `AppConfigTypeConflictException` — permanently, because the
	 * conflict is hit before the app can run anything that would repair it.
	 * `installed_version` and `types` are Nextcloud's own bookkeeping for the
	 * app and copying the old app's values would misreport the new one.
	 *
	 * @var string[]
	 */
	private const RESERVED_KEYS = [
		'enabled',
		'installed_version',
		'types',
	];

	/**
	 * Constructor for MigrateAppConfigKeys.
	 *
	 * @param IAppConfig $appConfig The app config interface
	 * @param LoggerInterface $logger The logger interface
	 *
	 * @return void
	 */
	public function __construct(
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Copy Keepiq app configuration from the doriath namespace to keepiq';
	}//end getName()

	/**
	 * Run the repair step to migrate the stored app configuration.
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 *
	 * @spec exclude One-off doriath->keepiq app-id rename plumbing: it moves
	 *       IAppConfig rows between namespaces and adds no behaviour of its
	 *       own. The settings it preserves are specified where they are read —
	 *       the password floors in
	 *       openspec/specs/org-password-policies/spec.md#requirement-configurable-org-password-policy,
	 *       ca_status in
	 *       openspec/specs/encryption-suites/spec.md#requirement-ca-health-check
	 *       and audit_retention_days in
	 *       openspec/specs/secret-audit-trail/spec.md#requirement-retention-and-automated-purge.
	 */
	public function run(IOutput $output): void {
		$keys = $this->oldKeys();
		if ($keys === []) {
			$output->info(
				'MigrateAppConfigKeys: no stored doriath configuration on this install; nothing to do.'
			);
			return;
		}

		$migrated = 0;
		$alreadyPresent = 0;
		$emptySource = 0;
		$skippedReserved = 0;

		foreach ($keys as $key) {
			if (in_array($key, self::RESERVED_KEYS, strict: true) === true) {
				$skippedReserved++;
				continue;
			}

			/* The two READS belong inside the try as much as the write does.
			   They used to sit outside it, so a read that threw propagated
			   out of run() and aborted `occ upgrade` — and because this step
			   also runs under <install>, an app that cannot finish its
			   repair steps does not enable at all, taking every route with
			   it. That is the opposite of what this class's docblock
			   promises ("every failure is logged and the loop continues").
			   One unreadable key is not worth an install. */
			try {
				$old = $this->appConfig->getValueString(self::OLD_APP_ID, $key, '');
				if ($old === '') {
					$emptySource++;
					continue;
				}

				$existing = $this->appConfig->getValueString(Application::APP_ID, $key, '');
				if ($existing !== '') {
					$alreadyPresent++;
					continue;
				}

				$this->appConfig->setValueString(Application::APP_ID, $key, $old);
				$migrated++;
			} catch (Throwable $e) {
				$this->logger->warning(
					'Keepiq: could not migrate one app config key; leaving it under the old namespace',
					['key' => $key, 'exception' => $e->getMessage()]
				);
			}//end try
		}//end foreach

		$output->info(
			'MigrateAppConfigKeys: ' . $migrated . ' key(s) migrated, ' . $alreadyPresent
			. ' already present, ' . $emptySource . ' had no value to migrate, '
			. $skippedReserved . ' skipped as Nextcloud-reserved.'
		);
	}//end run()

	/**
	 * Every key currently stored under the old app-config namespace.
	 *
	 * @return array<int, string> The stored key names, empty when unreadable
	 */
	private function oldKeys(): array {
		try {
			return $this->appConfig->getKeys(self::OLD_APP_ID);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Keepiq: could not enumerate doriath app config keys; skipping the migration',
				['exception' => $e->getMessage()]
			);
			return [];
		}//end try
	}//end oldKeys()
}//end class
