<?php

/**
 * Keepiq Migration Work Service
 *
 * The per-record half of compromise-recovery migration: what is still bound
 * to the old suite, and the guarded commit of one re-encrypted record.
 *
 * The server never decrypts and never encrypts here (ADR-003). Every method
 * takes ciphertext the browser produced and verified, and its whole job is to
 * refuse the write unless the row is one this migration is entitled to touch.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

use DateTime;
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Db\AttachmentGrant;
use OCA\Keepiq\Db\AttachmentGrantMapper;
use OCA\Keepiq\Db\MigrationFailureMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretVersion;
use OCA\Keepiq\Db\SecretVersionMapper;
use OCA\Keepiq\Db\SuiteMigration;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Outstanding-work discovery and guarded per-record commits for a migration.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   The service exists to hold
 *   one discipline across all three ciphertext-bearing stores, so it
 *   necessarily reaches their three mappers, their three entities and the two
 *   guard exceptions. Splitting it per store would give each store its own copy
 *   of the two-part authorization guard — which is exactly the drift this class
 *   prevents.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) Eleven, and the shape is
 *   forced: three stores x (list + commit) plus the migration-wide
 *   accounting (count, list, clear) and the version window. Splitting per
 *   store is the option this class exists to reject, because each copy would
 *   carry its own two-part authorization guard and drift.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) The complexity is three
 *   near-parallel guard/commit pairs, not tangled logic. Collapsing them behind
 *   one generic entry point would mean passing the store as a parameter on a
 *   per-object write path, which the change's design rejected as an IDOR
 *   footgun (hydra-gate-no-admin-idor).
 * @SuppressWarnings(PHPMD.ExcessiveClassLength) The length is the same three
 *   near-parallel per-store pairs (count / list / commit / drop), each with the
 *   per-store owner-scoping guard that must not be shared. The class sat just
 *   under the threshold; countCommitted — the mirror of countOutstanding needed
 *   by the abort gate, and dependent on the same three mappers only this class
 *   holds — tipped it over. Splitting the suite-bound stores into their own
 *   services is a separate refactor, not part of the abort change.
 *
 * @spec openspec/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
 */
class MigrationWorkService {
	/**
	 * How many snapshot versions per secret are re-encrypted alongside the
	 * head. Older versions are dropped when the migration terminates, per
	 * openspec/specs/secret-version-history/spec.md.
	 *
	 * @var integer
	 */
	public const DEFAULT_VERSION_WINDOW = 5;

	/**
	 * Store keys. These are the values persisted in
	 * `keepiq_migration_failures.store` and the keys the client sends, so
	 * they are declared once rather than spelled inline.
	 *
	 * @var string
	 */
	public const STORE_SECRETS = 'secrets';

	/**
	 * @var string
	 */
	public const STORE_VERSIONS = 'versions';

	/**
	 * @var string
	 */
	public const STORE_ATTACHMENT_GRANTS = 'attachmentGrants';

	/**
	 * Constructor for MigrationWorkService.
	 *
	 * @param SecretMapper $secretMapper The secret mapper
	 * @param SecretVersionMapper $versionMapper The secret version mapper
	 * @param AttachmentGrantMapper $grantMapper The attachment grant mapper
	 * @param MigrationFailureMapper $failureMapper Per-record failure accounting
	 * @param IDBConnection $db The database connection (per-record transactions)
	 * @param IAppConfig $appConfig The app config (version window override)
	 * @param LoggerInterface $logger The logger interface
	 *
	 * @return void
	 */
	public function __construct(
		private SecretMapper $secretMapper,
		private SecretVersionMapper $versionMapper,
		private AttachmentGrantMapper $grantMapper,
		private MigrationFailureMapper $failureMapper,
		private IDBConnection $db,
		private IAppConfig $appConfig,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The configured per-secret version re-encryption window.
	 *
	 * @return int
	 *
	 * @spec openspec/specs/secret-version-history/spec.md#requirement-compromise-recovery-migration-re-encrypts-a-bounded-window
	 */
	public function getVersionWindow(): int {
		// Gate exclusion: unclosable-gate exclude 'migration_version_window'
		//   is an operator tunable with a working default, not a "has the setup
		//   already run?" flag. ADR-076 rule 3 exists because such a flag, left
		//   unwritten, makes the work it guards repeat on every call. Nothing is
		//   guarded here: an unset key simply means DEFAULT_VERSION_WINDOW, so
		//   there is no work to skip and nothing for a write to close.
		$window = $this->appConfig->getValueInt(
			Application::APP_ID,
			'migration_version_window',
			self::DEFAULT_VERSION_WINDOW
		);

		return max(0, $window);
	}//end getVersionWindow()

	/**
	 * Count the rows still bound to the migration's old suite, per store.
	 *
	 * This is the completion gate's evidence and the progress denominator. It
	 * is derived entirely from the rows themselves: there is no counter column
	 * to drift, so a resumed migration and a fresh one see the same truth.
	 *
	 * `secret_requests` carries no ciphertext of its own (only a suite pointer,
	 * re-pointed on completion), `link_shares` are revoked and
	 * `emergency_contacts` invalidated by existing listeners — so none of the
	 * three is outstanding *re-encryption* work and none is counted here.
	 *
	 * Two numbers matter and they are not the same. `total` is everything still
	 * on the old suite. `unaccountedTotal` is the subset nobody has attempted —
	 * rows whose owning secret carries no `migration_error`. Only the second one
	 * means "this migration is unfinished"; the first also counts rows that were
	 * tried and reported unrecoverable, which are a decision for the user rather
	 * than a reason to hold the migration open and the vault write-locked
	 * forever.
	 *
	 * @param SuiteMigration $migration The migration
	 * @param string $ownerId The migration owner's user ID
	 *
	 * @return array<string,int>
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	public function countOutstanding(SuiteMigration $migration, string $ownerId): array {
		$oldSuiteId = $migration->getOldSuiteId();

		$secrets = $this->secretMapper->countBySuiteForOwner(
			encryptionSuiteId: $oldSuiteId,
			ownerType: 'user',
			ownerId: $ownerId
		);

		$versions = $this->versionMapper->countBySuiteForOwner(
			encryptionSuiteId: $oldSuiteId,
			ownerType: 'user',
			ownerId: $ownerId
		);

		$grants = $this->grantMapper->countBySuiteForRecipient(
			encryptionSuiteId: $oldSuiteId,
			recipientType: 'user',
			recipientId: $ownerId
		);

		// "Unaccounted" is what is left on the old suite MINUS what has been
		// attempted and recorded as failed, computed per store from the
		// failure table.
		//
		// It used to be computed by joining each store to its owning secret and
		// treating `secrets.migration_error IS NOT NULL` as "accounted for".
		// That join is the first blocker: a version whose secret head had
		// already migrated was excluded from the version count (its secret
		// carried an error) while the secret itself was not returned as failed
		// (it now sat on the NEW suite), so the row was invisible to both
		// gates and the migration terminated over it.
		//
		// Success clears a record's failure row, so every surviving failure row
		// belongs to a record still bound to the old suite — which is what
		// makes the subtraction sound. Clamped at zero so a record deleted
		// after failing (a version dropped beyond the retention window) cannot
		// drive the count negative.
		$migrationId = $migration->getId();

		$failedSecrets = $this->failureMapper->countByMigrationAndStore(
			migrationId: $migrationId,
			store: self::STORE_SECRETS
		);
		$failedVersions = $this->failureMapper->countByMigrationAndStore(
			migrationId: $migrationId,
			store: self::STORE_VERSIONS
		);
		$failedGrants = $this->failureMapper->countByMigrationAndStore(
			migrationId: $migrationId,
			store: self::STORE_ATTACHMENT_GRANTS
		);

		$unaccountedSecrets = max(0, ($secrets - $failedSecrets));
		$unaccountedVersions = max(0, ($versions - $failedVersions));
		$unaccountedGrants = max(0, ($grants - $failedGrants));

		$unaccountedTotal = ($unaccountedSecrets + $unaccountedVersions + $unaccountedGrants);
		$total = ($secrets + $versions + $grants);

		return [
			'secrets' => $secrets,
			'versions' => $versions,
			'attachmentGrants' => $grants,
			'total' => $total,
			'unaccountedSecrets' => $unaccountedSecrets,
			'unaccountedVersions' => $unaccountedVersions,
			'unaccountedGrants' => $unaccountedGrants,
			'unaccountedTotal' => $unaccountedTotal,
			// Attempted and reported unrecoverable. Taken from the failure
			// table rather than derived as (total - unaccounted), so it stays
			// correct even when a failed record has since been deleted.
			'failedTotal' => ($failedSecrets + $failedVersions + $failedGrants),
		];
	}//end countOutstanding()

	/**
	 * The owner's secrets left on the old suite with a recorded failure.
	 *
	 * Exactly the rows that lose access when the migration is finalised, so the
	 * client can name them in the acknowledgement prompt instead of asking the
	 * user to accept an abstract count.
	 *
	 * Failures are already scoped to the migration, and the caller has proven
	 * ownership of it before getting here, so no owner argument is needed.
	 *
	 * @param SuiteMigration $migration The migration
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/secrets/spec.md#requirement-possibly-compromised-flag-lifecycle
	 */
	public function listUnrecoverable(SuiteMigration $migration): array {
		// Enumerates the failing RECORDS, not their owning secrets. Listing
		// owners collapsed a secret failing alongside two of its versions into
		// one entry, which is how the client's per-record count and the
		// server's per-secret count came to disagree — and it filtered on
		// `encryption_suite_id = old`, so a failed version whose secret head
		// had migrated was not listed at all.
		$failures = $this->failureMapper->findByMigration(migrationId: $migration->getId());

		// Names are resolved per owning secret, cached because several failing
		// records commonly share one.
		$names = [];
		$rows = [];

		foreach ($failures as $failure) {
			$secretId = $failure->getSecretId();

			if (array_key_exists($secretId, $names) === false) {
				try {
					$names[$secretId] = $this->secretMapper->findById($secretId)->getName();
				} catch (DoesNotExistException) {
					$names[$secretId] = null;
				}
			}

			$rows[] = [
				// The RECORD's id, so the client can retry exactly this one.
				'id' => $failure->getRecordId(),
				'store' => $failure->getStore(),
				'secretId' => $secretId,
				'name' => $names[$secretId],
				'error' => $failure->getMessage(),
			];
		}

		return $rows;
	}//end listUnrecoverable()

	/**
	 * How many records this migration has recorded as unrecoverable.
	 *
	 * The acknowledgement threshold, and deliberately a COUNT rather than
	 * `count(listUnrecoverable(...))`: the display list is capped, and deriving
	 * the threshold from a capped list would both under-report the loss and
	 * make the acknowledgement unsatisfiable once the real number exceeded the
	 * cap (the client's own count is uncapped).
	 *
	 * @param SuiteMigration $migration The migration
	 *
	 * @return integer
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-a-migration-always-has-a-way-to-terminate
	 */
	public function countUnrecoverable(SuiteMigration $migration): int {
		return $this->failureMapper->countByMigration(migrationId: $migration->getId());
	}//end countUnrecoverable()

	/**
	 * How many of the owner's records have been committed to the NEW suite.
	 *
	 * The successor suite is created empty at the start of a migration, so any
	 * of the owner's suite-bound rows now pointing at it is a record the
	 * migration has moved. This is the mirror of countOutstanding(), which
	 * counts what still sits on the OLD suite. It is what decides whether a
	 * migration may still be aborted: abort is only safe while nothing has
	 * moved, because once a record is on the new suite, discarding that suite
	 * would strand it and keeping it would strand everything still on the old
	 * one.
	 *
	 * @param SuiteMigration $migration The migration
	 * @param string $ownerId The owner's user id
	 *
	 * @return integer
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-migration-can-be-aborted-before-any-record-moves
	 */
	public function countCommitted(SuiteMigration $migration, string $ownerId): int {
		$newSuiteId = $migration->getNewSuiteId();

		$secrets = $this->secretMapper->countBySuiteForOwner(
			encryptionSuiteId: $newSuiteId,
			ownerType: 'user',
			ownerId: $ownerId
		);
		$versions = $this->versionMapper->countBySuiteForOwner(
			encryptionSuiteId: $newSuiteId,
			ownerType: 'user',
			ownerId: $ownerId
		);
		$grants = $this->grantMapper->countBySuiteForRecipient(
			encryptionSuiteId: $newSuiteId,
			recipientType: 'user',
			recipientId: $ownerId
		);

		return ($secrets + $versions + $grants);
	}//end countCommitted()

	/**
	 * Drop this migration's failure accounting.
	 *
	 * The table holds in-flight state, not history: once the migration has
	 * terminated the rows have no consumer, and leaving them would make a
	 * later migration for the same owner inherit a stale threshold.
	 *
	 * @param SuiteMigration $migration The migration
	 *
	 * @return void
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-a-migration-always-has-a-way-to-terminate
	 */
	public function clearFailureAccounting(SuiteMigration $migration): void {
		$this->failureMapper->deleteByMigration(migrationId: $migration->getId());
	}//end clearFailureAccounting()

	/**
	 * List the outstanding work for a migration, paged per store.
	 *
	 * Each record carries the ciphertext the browser needs to re-encrypt it, so
	 * one request yields a directly workable batch instead of a page of ids
	 * followed by a fetch per id. Every field here is ciphertext the caller
	 * already owns and is about to decrypt in their own browser — the server
	 * still decrypts nothing (ADR-003).
	 *
	 * Read-only: this mutates nothing, so the version rows that will be
	 * dropped are reported as a count only. The drop happens when the
	 * migration terminates, which is what the version-history spec ties it to.
	 *
	 * @param SuiteMigration $migration The migration
	 * @param string $ownerId The migration owner's user ID
	 * @param int $limit Page size per store
	 * @param int $offset Page offset per store
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	public function listWork(
		SuiteMigration $migration,
		string $ownerId,
		int $limit = 25,
		int $offset = 0,
	): array {
		$oldSuiteId = $migration->getOldSuiteId();
		// Capped low: every record in the page carries its RSA ciphertext, so a
		// large page is a large response for no gain — the client's in-flight
		// window is 4.
		$limit = max(1, min(100, $limit));
		$offset = max(0, $offset);

		$secrets = $this->secretMapper->findBySuiteForOwner(
			encryptionSuiteId: $oldSuiteId,
			ownerType: 'user',
			ownerId: $ownerId,
			limit: $limit,
			offset: $offset
		);

		$grants = $this->grantMapper->findBySuiteForRecipient(
			encryptionSuiteId: $oldSuiteId,
			recipientType: 'user',
			recipientId: $ownerId,
			limit: $limit,
			offset: $offset
		);

		$versionWork = $this->collectVersionWork(
			oldSuiteId: $oldSuiteId,
			ownerId: $ownerId,
			limit: $limit,
			offset: $offset
		);

		$counts = $this->countOutstanding(migration: $migration, ownerId: $ownerId);

		$secretRecords = [];
		foreach ($secrets as $secret) {
			$secretRecords[] = [
				'id' => $secret->getId(),
				'name' => $secret->getName(),
				'key' => $secret->getKey(),
				'login' => $secret->getLogin(),
				'additionalFields' => $secret->getAdditionalFields(),
			];
		}

		$grantRecords = [];
		foreach ($grants as $grant) {
			$grantRecords[] = [
				'id' => $grant->getId(),
				'secretId' => $grant->getSecretId(),
				'wrappedFileKey' => $grant->getWrappedFileKey(),
			];
		}

		return [
			'migrationId' => $migration->getId(),
			'oldSuiteId' => $oldSuiteId,
			'newSuiteId' => $migration->getNewSuiteId(),
			'limit' => $limit,
			'offset' => $offset,
			'versionWindow' => $this->getVersionWindow(),
			'secrets' => [
				'records' => $secretRecords,
				'remaining' => $counts['secrets'],
			],
			'versions' => [
				'records' => $versionWork['records'],
				'remaining' => $counts['versions'],
				'dropCandidates' => $versionWork['dropCount'],
			],
			'attachmentGrants' => [
				'records' => $grantRecords,
				'remaining' => $counts['attachmentGrants'],
			],
			'totalRemaining' => $counts['total'],
		];
	}//end listWork()

	/**
	 * Split one page of the owner's outstanding versions into the ids inside
	 * the window and a count of those beyond it.
	 *
	 * Paged by SECRET so the per-secret window is always computed against that
	 * secret's complete outstanding history — see
	 * SecretVersionMapper::findSecretIdsWithSuiteVersionsForOwner.
	 *
	 * @param string $oldSuiteId The old suite ID
	 * @param string $ownerId The migration owner's user ID
	 * @param int $limit Secrets per page
	 * @param int $offset Secret offset
	 *
	 * @return array{records:array<int,array<string,mixed>>,dropCount:int}
	 *
	 * @spec openspec/specs/secret-version-history/spec.md#requirement-compromise-recovery-migration-re-encrypts-a-bounded-window
	 */
	private function collectVersionWork(
		string $oldSuiteId,
		string $ownerId,
		int $limit,
		int $offset,
	): array {
		$window = $this->getVersionWindow();

		$secretIds = $this->versionMapper->findSecretIdsWithSuiteVersionsForOwner(
			encryptionSuiteId: $oldSuiteId,
			ownerType: 'user',
			ownerId: $ownerId,
			limit: $limit,
			offset: $offset
		);

		$records = [];
		$dropCount = 0;

		foreach ($secretIds as $secretId) {
			// Newest first, so the first $window rows are the ones that stay.
			$versions = $this->versionMapper->findBySecretAndSuite(
				secretId: $secretId,
				encryptionSuiteId: $oldSuiteId
			);

			foreach ($versions as $index => $version) {
				if ($index < $window) {
					$records[] = [
						'id' => $version->getId(),
						'secretId' => $version->getSecretId(),
						'versionNumber' => $version->getVersionNumber(),
						'key' => $version->getKey(),
						'login' => $version->getLogin(),
						'additionalFields' => $version->getAdditionalFields(),
					];
					continue;
				}

				++$dropCount;
			}
		}//end foreach

		return [
			'records' => $records,
			'dropCount' => $dropCount,
		];
	}//end collectVersionWork()

	/**
	 * Commit a re-encrypted secret.
	 *
	 * @param SuiteMigration $migration The migration
	 * @param string $ownerId The acting user's ID
	 * @param string $secretId The secret to re-point
	 * @param string $key The new key ciphertext
	 * @param string|null $login The new login ciphertext
	 * @param string|null $additionalFields The new additional-fields ciphertext
	 *
	 * @return Secret
	 *
	 * @throws NotFoundException When the secret does not exist
	 * @throws ForbiddenException When the row is not this migration's to touch
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	public function commitSecret(
		SuiteMigration $migration,
		string $ownerId,
		string $secretId,
		string $key,
		?string $login,
		?string $additionalFields,
	): Secret {
		$secret = $this->requireOwnedSecretOnOldSuite(
			migration: $migration,
			ownerId: $ownerId,
			secretId: $secretId
		);

		$this->db->beginTransaction();
		try {
			$secret->setKey($key);
			$secret->setLogin($login);
			$secret->setAdditionalFields($additionalFields);
			$secret->setEncryptionSuiteId($migration->getNewSuiteId());

			// The migration re-seals the SAME value under a new key; it does
			// not replace the value, so keyUpdatedAt is deliberately NOT
			// touched. Bumping it would reset the staleness clock and tell the
			// health surface these passwords were just rotated, which is the
			// opposite of what possiblyCompromisedAt is about to say.
			$this->raisePossiblyCompromised(secret: $secret);

			$updated = $this->secretMapper->update($secret);

			// Clears THIS head's failure only. A blanket null here would also
			// have retired the recorded failures of every version and grant the
			// secret owns.
			$this->clearRecordFailure(
				migration: $migration,
				store: self::STORE_SECRETS,
				recordId: $secret->getId()
			);

			$this->db->commit();
		} catch (Throwable $exception) {
			$this->db->rollBack();
			throw $exception;
		}//end try

		return $updated;
	}//end commitSecret()

	/**
	 * Commit a re-encrypted secret version.
	 *
	 * @param SuiteMigration $migration The migration
	 * @param string $ownerId The acting user's ID
	 * @param string $versionId The version to re-point
	 * @param string $key The new key ciphertext
	 * @param string|null $login The new login ciphertext
	 * @param string|null $additionalFields The new additional-fields ciphertext
	 *
	 * @return SecretVersion
	 *
	 * @throws NotFoundException When the version or its secret does not exist
	 * @throws ForbiddenException When the row is not this migration's to touch
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	public function commitVersion(
		SuiteMigration $migration,
		string $ownerId,
		string $versionId,
		string $key,
		?string $login,
		?string $additionalFields,
	): SecretVersion {
		$version = $this->requireOwnedVersionOnOldSuite(
			migration: $migration,
			ownerId: $ownerId,
			versionId: $versionId
		);

		$this->db->beginTransaction();
		try {
			$version->setKey($key);
			$version->setLogin($login);
			$version->setAdditionalFields($additionalFields);
			$version->setEncryptionSuiteId($migration->getNewSuiteId());

			$updated = $this->versionMapper->update($version);

			// Scoped to this version. It previously cleared the owning
			// secret's shared column, which is what erased sibling failures.
			$this->clearRecordFailure(
				migration: $migration,
				store: self::STORE_VERSIONS,
				recordId: $version->getId()
			);

			$this->db->commit();
		} catch (Throwable $exception) {
			$this->db->rollBack();
			throw $exception;
		}//end try

		return $updated;
	}//end commitVersion()

	/**
	 * Commit a re-wrapped attachment grant.
	 *
	 * @param SuiteMigration $migration The migration
	 * @param string $ownerId The acting user's ID
	 * @param string $grantId The grant to re-point
	 * @param string $wrappedFileKey The file key re-wrapped under the new suite
	 *
	 * @return AttachmentGrant
	 *
	 * @throws NotFoundException When the grant does not exist
	 * @throws ForbiddenException When the row is not this migration's to touch
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	public function commitAttachmentGrant(
		SuiteMigration $migration,
		string $ownerId,
		string $grantId,
		string $wrappedFileKey,
	): AttachmentGrant {
		$grant = $this->requireOwnedGrantOnOldSuite(
			migration: $migration,
			ownerId: $ownerId,
			grantId: $grantId
		);

		$this->db->beginTransaction();
		try {
			$grant->setWrappedFileKey($wrappedFileKey);
			$grant->setEncryptionSuiteId($migration->getNewSuiteId());

			$updated = $this->grantMapper->update($grant);

			$this->clearRecordFailure(
				migration: $migration,
				store: self::STORE_ATTACHMENT_GRANTS,
				recordId: $grant->getId()
			);

			// An attachment whose file key was wrapped under the compromised
			// suite is exposed material, so the owning secret carries the
			// warning even when its head migrated in an earlier pass and was
			// therefore not flagged then. Idempotent, so an existing timestamp
			// is not pushed forward.
			try {
				$owningSecret = $this->secretMapper->findById($grant->getSecretId());
				if ($owningSecret->getPossiblyCompromisedAt() === null) {
					$this->raisePossiblyCompromised(secret: $owningSecret);
					$this->secretMapper->update($owningSecret);
				}
			} catch (DoesNotExistException) {
				// The grant outlived its secret; nothing to flag.
			}

			$this->db->commit();
		} catch (Throwable $exception) {
			$this->db->rollBack();
			throw $exception;
		}//end try

		return $updated;
	}//end commitAttachmentGrant()

	/**
	 * Record a per-record migration failure on the owning secret.
	 *
	 * `migration_error` exists only on `keepiq_secrets`, so a version or
	 * grant failure is recorded against the secret that owns it, prefixed with
	 * the store it came from. That keeps the user's failure list one flat list
	 * pointing at things they recognise, and needs no schema change.
	 *
	 * A failure never aborts the run — the caller records and moves on.
	 *
	 * @param SuiteMigration $migration The migration
	 * @param string $ownerId The acting user's ID
	 * @param string $store The store the failure came from
	 * @param string $recordId The failing record's ID
	 * @param string $message The failure message
	 *
	 * @return string The secret ID the failure was recorded against
	 *
	 * @throws NotFoundException When the record does not exist
	 * @throws ForbiddenException When the record is not this migration's to touch
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-re-encrypted-ciphertext-is-verified-before-the-original-is-discarded
	 */
	public function recordFailure(
		SuiteMigration $migration,
		string $ownerId,
		string $store,
		string $recordId,
		string $message,
	): string {
		$secretId = $this->resolveOwningSecretId(
			migration: $migration,
			ownerId: $ownerId,
			store: $store,
			recordId: $recordId
		);

		// Recorded against the RECORD, not against its owning secret. One
		// secret owns its head plus N versions plus M attachment grants, so a
		// single column on the secret could only ever hold one of up to
		// 1 + N + M independent verdicts — whichever was written last. That is
		// what let a sibling's success erase a recorded failure, and what made
		// a failed version invisible to the completion gates once its secret
		// head had migrated.
		//
		// Idempotent by (migration, store, record): a retry that fails again
		// replaces its message instead of adding a second row, so the
		// acknowledgement threshold stays equal to the number of records
		// actually lost.
		$this->failureMapper->record(
			migrationId: $migration->getId(),
			store: $store,
			recordId: $recordId,
			secretId: $secretId,
			// Bound the stored text: it is echoed back to the user and the
			// column is not a log sink.
			message: mb_substr($message, 0, 1000)
		);

		$this->logger->warning(
			'Keepiq: migration record failed',
			[
				'migrationId' => $migration->getId(),
				'store' => $store,
				'recordId' => $recordId,
				'secretId' => $secretId,
			]
		);

		return $secretId;
	}//end recordFailure()

	/**
	 * Drop the owner's version rows beyond the per-secret window.
	 *
	 * Inherited behaviour, not a new decision: the version-history spec fixes
	 * the window at head plus N and requires the older snapshots to be dropped
	 * and the loss stated to the user. Runs at termination so the count is
	 * final and reportable in one number.
	 *
	 * @param SuiteMigration $migration The migration
	 * @param string $ownerId The migration owner's user ID
	 *
	 * @return int The number of version rows dropped
	 *
	 * @spec openspec/specs/secret-version-history/spec.md#requirement-compromise-recovery-migration-re-encrypts-a-bounded-window
	 */
	public function dropVersionsBeyondWindow(SuiteMigration $migration, string $ownerId): int {
		$oldSuiteId = $migration->getOldSuiteId();
		$window = $this->getVersionWindow();
		$dropped = 0;

		// Paged by secret; each pass re-queries from offset 0 because the
		// previous pass deleted rows, which shifts every later offset.
		while (true) {
			$secretIds = $this->versionMapper->findSecretIdsWithSuiteVersionsForOwner(
				encryptionSuiteId: $oldSuiteId,
				ownerType: 'user',
				ownerId: $ownerId,
				limit: 200,
				offset: 0
			);

			if ($secretIds === []) {
				break;
			}

			$deletedThisPass = 0;

			foreach ($secretIds as $secretId) {
				$versions = $this->versionMapper->findBySecretAndSuite(
					secretId: $secretId,
					encryptionSuiteId: $oldSuiteId
				);

				foreach ($versions as $index => $version) {
					if ($index < $window) {
						continue;
					}

					$this->versionMapper->delete($version);
					++$dropped;
					++$deletedThisPass;
				}
			}

			// Every remaining row in this page is inside the window, so no
			// later page can contain a droppable row either.
			if ($deletedThisPass === 0) {
				break;
			}
		}//end while

		if ($dropped > 0) {
			$this->logger->info(
				'Keepiq: dropped version history beyond the migration window',
				[
					'migrationId' => $migration->getId(),
					'dropped' => $dropped,
					'window' => $window,
				]
			);
		}

		return $dropped;
	}//end dropVersionsBeyondWindow()

	/**
	 * Raise `possibly_compromised_at`, idempotently.
	 *
	 * An already-set timestamp is preserved: it records when the value was
	 * first known to be exposed, and a retry must not push that forward and
	 * make the exposure look newer than it is.
	 *
	 * @param Secret $secret The secret to flag
	 *
	 * @return void
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/secrets/spec.md#requirement-possibly-compromised-flag-lifecycle
	 */
	private function raisePossiblyCompromised(Secret $secret): void {
		if ($secret->getPossiblyCompromisedAt() !== null) {
			return;
		}

		$secret->setPossiblyCompromisedAt(new DateTime());
	}//end raisePossiblyCompromised()

	/**
	 * Clear the failure for exactly the record just committed.
	 *
	 * Scoped to (migration, store, record). The previous implementation nulled
	 * a column on the OWNING SECRET, which was shared by the secret head and
	 * every one of its versions and grants — so committing any one of them
	 * erased a sibling's recorded failure, reclassifying a genuinely failed
	 * record as "not yet attempted" and holding the vault write-locked with no
	 * route to completion.
	 *
	 * @param SuiteMigration $migration The migration
	 * @param string $store The store the committed record lives in
	 * @param string $recordId The committed record's ID
	 *
	 * @return void
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	private function clearRecordFailure(SuiteMigration $migration, string $store, string $recordId): void {
		$this->failureMapper->clearRecord(
			migrationId: $migration->getId(),
			store: $store,
			recordId: $recordId
		);
	}//end clearRecordFailure()

	/**
	 * Resolve which secret a failure should be recorded against.
	 *
	 * @param SuiteMigration $migration The migration
	 * @param string $ownerId The acting user's ID
	 * @param string $store The store the failure came from
	 * @param string $recordId The failing record's ID
	 *
	 * @return string The owning secret's ID
	 *
	 * @throws NotFoundException When the record does not exist
	 * @throws ForbiddenException When the record is not this migration's to touch
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	private function resolveOwningSecretId(
		SuiteMigration $migration,
		string $ownerId,
		string $store,
		string $recordId,
	): string {
		if ($store === 'secrets') {
			return $this->requireOwnedSecretOnOldSuite(
				migration: $migration,
				ownerId: $ownerId,
				secretId: $recordId
			)->getId();
		}

		if ($store === 'versions') {
			return $this->requireOwnedVersionOnOldSuite(
				migration: $migration,
				ownerId: $ownerId,
				versionId: $recordId
			)->getSecretId();
		}

		if ($store === 'attachmentGrants') {
			return $this->requireOwnedGrantOnOldSuite(
				migration: $migration,
				ownerId: $ownerId,
				grantId: $recordId
			)->getSecretId();
		}

		throw new ForbiddenException(message: 'Unknown migration store: ' . $store);
	}//end resolveOwningSecretId()

	/**
	 * Load a secret, insisting it is the migration owner's and still on the
	 * old suite.
	 *
	 * The suite half of the guard is what makes "process only unmigrated rows"
	 * a server-side invariant instead of a client promise: a replayed or
	 * out-of-order request targeting an already-migrated row is refused rather
	 * than overwriting good new ciphertext with a stale re-encryption.
	 *
	 * @param SuiteMigration $migration The migration
	 * @param string $ownerId The acting user's ID
	 * @param string $secretId The secret ID
	 *
	 * @return Secret
	 *
	 * @throws NotFoundException When the secret does not exist
	 * @throws ForbiddenException When the row fails either half of the guard
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	private function requireOwnedSecretOnOldSuite(
		SuiteMigration $migration,
		string $ownerId,
		string $secretId,
	): Secret {
		try {
			$secret = $this->secretMapper->findById($secretId);
		} catch (DoesNotExistException) {
			throw new NotFoundException(message: 'Secret not found');
		}

		if ($secret->getOwnerType() !== 'user' || $secret->getOwnerId() !== $ownerId) {
			throw new ForbiddenException(message: 'Secret does not belong to you');
		}

		if ($secret->getEncryptionSuiteId() !== $migration->getOldSuiteId()) {
			throw new ForbiddenException(message: 'Secret is not bound to this migration\'s old suite');
		}

		return $secret;
	}//end requireOwnedSecretOnOldSuite()

	/**
	 * Load a version, insisting its secret is the migration owner's and the
	 * version is still on the old suite.
	 *
	 * Ownership is checked on the owning secret, while suite binding is checked
	 * on the version row: a head that has already migrated still owns versions
	 * that have not.
	 *
	 * @param SuiteMigration $migration The migration
	 * @param string $ownerId The acting user's ID
	 * @param string $versionId The version ID
	 *
	 * @return SecretVersion
	 *
	 * @throws NotFoundException When the version or its secret does not exist
	 * @throws ForbiddenException When the row fails either half of the guard
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	private function requireOwnedVersionOnOldSuite(
		SuiteMigration $migration,
		string $ownerId,
		string $versionId,
	): SecretVersion {
		try {
			$version = $this->versionMapper->findById($versionId);
		} catch (DoesNotExistException) {
			throw new NotFoundException(message: 'Secret version not found');
		}

		if ($version->getEncryptionSuiteId() !== $migration->getOldSuiteId()) {
			throw new ForbiddenException(message: 'Version is not bound to this migration\'s old suite');
		}

		try {
			$secret = $this->secretMapper->findById($version->getSecretId());
		} catch (DoesNotExistException) {
			throw new NotFoundException(message: 'Owning secret not found');
		}

		if ($secret->getOwnerType() !== 'user' || $secret->getOwnerId() !== $ownerId) {
			throw new ForbiddenException(message: 'Version does not belong to you');
		}

		return $version;
	}//end requireOwnedVersionOnOldSuite()

	/**
	 * Load an attachment grant, insisting it is addressed to the migration
	 * owner and still on the old suite.
	 *
	 * Scoped by recipient: the rotating user can only re-wrap the file keys
	 * wrapped to their own certificate. A grant addressed to somebody else is
	 * refused even when it hangs off a secret this user owns — re-pointing it
	 * would seal that recipient out of the attachment.
	 *
	 * @param SuiteMigration $migration The migration
	 * @param string $ownerId The acting user's ID
	 * @param string $grantId The grant ID
	 *
	 * @return AttachmentGrant
	 *
	 * @throws NotFoundException When the grant does not exist
	 * @throws ForbiddenException When the row fails either half of the guard
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	private function requireOwnedGrantOnOldSuite(
		SuiteMigration $migration,
		string $ownerId,
		string $grantId,
	): AttachmentGrant {
		try {
			$grant = $this->grantMapper->findById($grantId);
		} catch (DoesNotExistException) {
			throw new NotFoundException(message: 'Attachment grant not found');
		}

		if ($grant->getRecipientType() !== 'user' || $grant->getRecipientId() !== $ownerId) {
			throw new ForbiddenException(message: 'Attachment grant does not belong to you');
		}

		if ($grant->getEncryptionSuiteId() !== $migration->getOldSuiteId()) {
			throw new ForbiddenException(message: 'Attachment grant is not bound to this migration\'s old suite');
		}

		return $grant;
	}//end requireOwnedGrantOnOldSuite()
}//end class
