<?php

/**
 * Keepiq Migrate User Preferences Repair Step
 *
 * Repair step that carries this app's per-user preferences across the
 * `doriath` -> `keepiq` app-id rename.
 *
 * WHY THIS EXISTS SEPARATELY FROM MigrateAppConfigKeys. `IAppConfig` and
 * `IConfig`'s user values are different stores: the former is `oc_appconfig`,
 * the latter `oc_preferences`. Both are namespaced by app id, so both are cut
 * off by the rename, but copying one does nothing for the other.
 *
 * WHY IT MATTERS MORE THAN IT LOOKS. Every reader of these preferences carries
 * a DEFAULT, so a lost value does not error — it reverts, silently, with no
 * log line to notice. The consequences here are user-visible and, in two
 * cases, privacy-relevant:
 *   - `NotificationService::isOptedOut()` reads `notify_shares`,
 *     `notify_requests`, `notify_group_shares` and `notify_security` with a
 *     default of `'1'`. A user who explicitly turned a category OFF has `'0'`
 *     stored under the OLD app id; after the rename the lookup finds nothing
 *     and that user starts receiving the notifications they opted out of.
 *   - `breach_check_opt_in` defaults to `'0'`, so it fails safe — but
 *     `offline_cache_optin` defaults to `'1'`, which means a user who opted
 *     OUT of caching vault contents on a shared device silently gets the
 *     offline cache back.
 *   - `session_timeout` reverts to the instance default, which can LENGTHEN
 *     the unlocked-vault window for a user who deliberately shortened it.
 *
 * WHY IT ENUMERATES BY USER RATHER THAN BY VALUE. The planninq pilot walked
 * `IConfig::getUsersForUserValue(app, key, value)` for each of a boolean key's
 * two possible values. That is exhaustive only for closed value sets, and this
 * app does not have one: `session_timeout` and `expiry_max_age_days` hold
 * arbitrary numeric strings and `default_secret_type` holds a secret-type slug
 * that admins can add to at runtime, so no finite value list can be written
 * down. Enumerating users instead and asking `IConfig::getUserKeys()` what
 * that user actually stored is exhaustive by construction, for open and closed
 * value sets alike, and — like MigrateAppConfigKeys' use of `getKeys()` —
 * cannot drift when a future release adds a preference.
 *
 * `callForSeenUsers()` rather than `callForAllUsers()`: a stored preference is
 * written from the app's own settings UI, which requires a login, so a user
 * with something in `oc_preferences` for this app has necessarily been seen.
 * The seen-user walk reads the same table and avoids a full backend
 * enumeration (LDAP included) on every install.
 *
 * SAFETY. Idempotent and non-destructive, matching MigrateAppConfigKeys:
 *   - a value is copied only when the user has nothing stored under the new
 *     app id, so a preference changed after the rename is never clobbered and
 *     a second run is a no-op;
 *   - the old `doriath` rows are never deleted, so a rollback still finds them;
 *   - every failure is logged and the loop continues, because one unreadable
 *     preference is not worth aborting an install over.
 *
 * NO KEY MATERIAL PASSES THROUGH HERE. `oc_preferences` holds this app's
 * user-facing toggles only. Master passwords are never stored at all
 * (ADR-003), and the per-user RSA key pairs live wrapped in the
 * `keepiq_enc_suites` table, which the rename does not touch.
 *
 * Registered under BOTH `<install>` and `<post-migration>` in
 * `appinfo/info.xml` alongside MigrateAppConfigKeys — see the ordering comment
 * there. No other repair step reads or writes user values, so this one has no
 * ordering constraint of its own beyond running before anything that might.
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
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copy per-user preferences from the doriath app id to keepiq.
 */
class MigrateUserPreferences implements IRepairStep {
	/**
	 * The preferences namespace this app used before the rename.
	 *
	 * Deliberately the OLD app id — see MigrateAppConfigKeys::OLD_APP_ID.
	 *
	 * @var string
	 */
	private const OLD_APP_ID = 'doriath';

	/**
	 * Number of preferences copied during this run.
	 *
	 * Held as state rather than passed around because the walk happens inside
	 * a closure handed to IUserManager::callForSeenUsers(), which returns
	 * nothing and cannot thread a running total back out.
	 *
	 * @var int
	 */
	private int $migrated = 0;

	/**
	 * Number of preferences already present under the new app id.
	 *
	 * @var int
	 */
	private int $alreadyPresent = 0;

	/**
	 * Constructor for MigrateUserPreferences.
	 *
	 * @param IConfig $config The user-value store to read and write
	 * @param IUserManager $userManager The user enumeration backend
	 * @param LoggerInterface $logger Logger for preferences that fail to copy
	 *
	 * @return void
	 */
	public function __construct(
		private IConfig $config,
		private IUserManager $userManager,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Copy Keepiq per-user preferences from the doriath app id';
	}//end getName()

	/**
	 * Copy every stored per-user preference from the old app id to the new one.
	 *
	 * @param IOutput $output The output interface for progress reporting
	 *
	 * @return void
	 *
	 * @spec exclude One-off doriath->keepiq app-id rename plumbing: it moves
	 *       oc_preferences rows between namespaces and adds no behaviour of its
	 *       own. The preferences it preserves are specified where they are
	 *       read — session_timeout and the notification toggles in
	 *       openspec/specs/user-settings/spec.md#requirement-session-timeout-preference-mvp
	 *       and
	 *       openspec/specs/user-settings/spec.md#requirement-notification-toggles-mvp,
	 *       offline_cache_optin in
	 *       openspec/specs/offline-readonly-cache/spec.md#requirement-an-admin-can-disable-offline-caching-org-wide
	 *       and breach_check_opt_in in
	 *       openspec/specs/password-health/spec.md#requirement-opt-in-breach-checking-via-k-anonymity.
	 */
	public function run(IOutput $output): void {
		$this->migrated = 0;
		$this->alreadyPresent = 0;

		try {
			// The callback returns null rather than void: IUserManager treats a
			// `false` return as "stop iterating", so the contract is
			// Closure(IUser): (bool|null) and null means "keep going".
			$this->userManager->callForSeenUsers(
				function (IUser $user): ?bool {
					$this->migrateUser(userId: $user->getUID());
					return null;
				}
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Keepiq: could not enumerate users; per-user preferences were not migrated',
				['exception' => $e->getMessage()]
			);
			$output->warning(
				'MigrateUserPreferences: user enumeration failed; preferences left under the doriath app id.'
			);
			return;
		}//end try

		if ($this->migrated === 0 && $this->alreadyPresent === 0) {
			$output->info(
				'MigrateUserPreferences: no stored doriath user preferences on this install; nothing to do.'
			);
			return;
		}

		$output->info(
			'MigrateUserPreferences: migrated ' . $this->migrated . ' preference(s); '
			. $this->alreadyPresent . ' already set under keepiq.'
		);
	}//end run()

	/**
	 * Copy one user's stored preferences from the old app id to the new one.
	 *
	 * @param string $userId The Nextcloud user ID
	 *
	 * @return void
	 */
	private function migrateUser(string $userId): void {
		foreach ($this->oldKeysFor(userId: $userId) as $key) {
			try {
				$old = $this->config->getUserValue($userId, self::OLD_APP_ID, $key, '');
				if ($old === '') {
					continue;
				}

				$existing = $this->config->getUserValue($userId, Application::APP_ID, $key, '');
				if ($existing !== '') {
					$this->alreadyPresent++;
					continue;
				}

				$this->config->setUserValue($userId, Application::APP_ID, $key, $old);
				$this->migrated++;
			} catch (Throwable $e) {
				$this->logger->warning(
					'Keepiq: could not migrate one user preference; leaving it under the old app id',
					['key' => $key, 'exception' => $e->getMessage()]
				);
			}//end try
		}//end foreach
	}//end migrateUser()

	/**
	 * Every preference key this user has stored under the old app id.
	 *
	 * @param string $userId The Nextcloud user ID
	 *
	 * @return array<int, string> The stored key names, empty when unreadable
	 */
	private function oldKeysFor(string $userId): array {
		try {
			return $this->config->getUserKeys($userId, self::OLD_APP_ID);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Keepiq: could not enumerate doriath preference keys for a user; skipping that user',
				['exception' => $e->getMessage()]
			);
			return [];
		}//end try
	}//end oldKeysFor()
}//end class
