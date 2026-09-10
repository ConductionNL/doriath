<?php

/**
 * Keepiq Migration Service
 *
 * Tracks compromise recovery migrations (suite to suite).
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
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\SuiteMigration;
use OCA\Keepiq\Db\SuiteMigrationMapper;
use OCA\Keepiq\Event\SuiteMigrationAbortedEvent;
use OCA\Keepiq\Event\SuiteMigrationCompletedEvent;
use OCA\Keepiq\Event\SuiteMigrationStartedEvent;
use OCA\Keepiq\Exception\MigrationAbortRefusedException;
use OCA\Keepiq\Exception\MigrationIncompleteException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Tracks compromise recovery migrations (suite to suite).
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Terminating a migration is an
 *   ordered sequence over several subsystems — suite status, link shares,
 *   passkeys, the outstanding-row gate and the completion event — and the order
 *   is the security property. Keeping the sequence in one place is the point.
 */
class MigrationService {
	/**
	 * Constructor for MigrationService.
	 *
	 * @param SuiteMigrationMapper $mapper The suite migration mapper
	 * @param EncryptionSuiteMapper $suiteMapper The encryption suite mapper
	 * @param EncryptionSuiteService $suiteService The suite service (terminal markCompromised)
	 * @param LinkShareService $linkShareService The link share service (terminal cascade-revoke)
	 * @param MigrationWorkService $workService The work service (outstanding-row evidence)
	 * @param WriteLockService $writeLockService The write-lock oracle
	 * @param LoggerInterface $logger The logger interface
	 * @param IEventDispatcher|null $eventDispatcher The optional event dispatcher
	 * @param \OCA\Keepiq\Service\PasskeyService|null $passkeyService The passkey service (null when unwired)
	 *
	 * @return void
	 */
	public function __construct(
		private SuiteMigrationMapper $mapper,
		private EncryptionSuiteMapper $suiteMapper,
		private EncryptionSuiteService $suiteService,
		private LinkShareService $linkShareService,
		private MigrationWorkService $workService,
		private WriteLockService $writeLockService,
		private LoggerInterface $logger,
		private ?IEventDispatcher $eventDispatcher = null,
		private ?\OCA\Keepiq\Service\PasskeyService $passkeyService = null,
	) {
	}//end __construct()

	/**
	 * Initiate a compromise recovery migration.
	 *
	 * @param string $oldSuiteId The old suite ID
	 * @param string $newSuiteId The new suite ID
	 *
	 * @return SuiteMigration
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-4
	 */
	public function initiateCompromiseRecovery(string $oldSuiteId, string $newSuiteId): SuiteMigration {
		$migration = new SuiteMigration();
		$migration->setId(Uuid::uuid4()->toString());
		$migration->setOldSuiteId($oldSuiteId);
		$migration->setNewSuiteId($newSuiteId);
		$migration->setStatus('in_progress');
		$migration->setStartedAt(new DateTime());

		$this->mapper->insert($migration);

		$this->logger->info("Keepiq: Compromise recovery started, migrating from {$oldSuiteId} to {$newSuiteId}");

		if ($this->eventDispatcher !== null) {
			$this->eventDispatcher->dispatchTyped(
				new SuiteMigrationStartedEvent(
					oldSuiteId: $oldSuiteId,
					newSuiteId: $newSuiteId,
					migrationId: $migration->getId()
				)
			);
		}

		return $migration;
	}//end initiateCompromiseRecovery()

	/**
	 * Complete a migration (with or without errors).
	 *
	 * @param string $migrationId The migration ID
	 * @param bool $hasErrors Whether the migration had errors
	 * @param int|null $acceptUnrecoverable How many records the caller accepts losing access to,
	 *                                      required when any row on the old suite has a recorded
	 *                                      failure. Null means "no loss accepted", which refuses.
	 *
	 * @return array<string,mixed> The terminal migration, dropped-version count and lost secrets
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $hasErrors is outcome DATA forwarded
	 *   from MigrationController::complete(), not a mode switch: the completion path is
	 *   identical either way and the flag only selects which terminal status string is
	 *   written to the row. The false default is the ordinary success call.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-4
	 */
	public function completeMigration(
		string $migrationId,
		bool $hasErrors = false,
		?int $acceptUnrecoverable = null,
	): array {
		$migration = $this->mapper->findById($migrationId);

		// Idempotent by status. Without this, replaying the endpoint with a
		// retained migration id re-runs the whole destructive cascade:
		// markCompromised overwrites the audit fields, and
		// LinkShareService::deleteByUserId plus
		// PasskeyService::deleteAllOnRotation delete every link share and
		// passkey created AFTER the rotation finished, with
		// SuiteMigrationCompletedEvent re-dispatched to its listeners. An
		// ordinary client retry of a POST that timed out is enough to trigger
		// it. MigrationController::commitRecord already guards this way.
		if ($migration->getStatus() !== 'in_progress') {
			$this->logger->info(
				'Keepiq: completeMigration called on an already-terminated migration; ignoring',
				['migrationId' => $migrationId, 'status' => $migration->getStatus()]
			);

			return [
				'status' => $migration->getStatus(),
				'droppedVersions' => 0,
				'unrecoverable' => [],
				'alreadyCompleted' => true,
			];
		}

		// Terminal work, moved here from EncryptionSuiteController::compromiseRecovery.
		// It used to run at the START of recovery, which marked the old suite
		// compromised before anything had been migrated — every read then threw
		// SuiteBlockedException and the browser could not decrypt the very
		// ciphertext it needed to migrate. None of this can safely happen until
		// the migration is over.
		$ownerId = $this->resolveOwnerId(suiteId: $migration->getOldSuiteId());

		// Version history is deliberately lossy: only the head plus the N most
		// recent snapshots are re-encrypted, and the rest are dropped here so
		// the count is final and can be stated to the user in one number
		// (secret-version-history spec). This runs BEFORE the outstanding-row
		// check so out-of-window rows the client was never asked to migrate
		// cannot hold the gate shut.
		[$droppedVersions, $unrecoverable] = $this->prepareTermination(
			migration: $migration,
			ownerId: $ownerId,
			acceptUnrecoverable: $acceptUnrecoverable
		);

		$status = 'completed';
		if ($hasErrors === true || $unrecoverable !== []) {
			$status = 'completed_with_errors';
		}

		$migration->setStatus($status);

		$migration->setCompletedAt(new DateTime());

		$this->mapper->update($migration);

		$this->suiteService->markCompromised(
			id: $migration->getOldSuiteId(),
			compromisedBy: ($ownerId ?? '')
		);

		$this->revokeOwnerKeyMaterial(migration: $migration, ownerId: $ownerId);

		// The failure rows are in-flight accounting, not history. Dropping them
		// with the run keeps a later migration for the same owner from
		// inheriting a stale acknowledgement threshold.
		$this->workService->clearFailureAccounting(migration: $migration);

		// NOT re-implemented here: pending SecretRequests are unlocked and
		// re-pointed by SuiteMigrationCompletedListener, and emergency-access
		// envelopes are invalidated by EmergencyAccessSuiteRotationListener.
		// Both already listen for the event dispatched below.
		$this->logger->info(
			"Keepiq: Compromise recovery completed for migration {$migrationId}",
			['hasErrors' => $hasErrors]
		);

		$this->eventDispatcher?->dispatchTyped(
			new SuiteMigrationCompletedEvent(
				oldSuiteId: $migration->getOldSuiteId(),
				newSuiteId: $migration->getNewSuiteId(),
				migrationId: $migration->getId(),
				hasErrors: $hasErrors,
			)
		);

		return (
			$migration->jsonSerialize() + [
				'droppedVersions' => $droppedVersions,
				'unrecoverable' => $unrecoverable,
			]
		);
	}//end completeMigration()

	/**
	 * Abort a compromise-recovery migration, returning the vault to the old suite.
	 *
	 * The abort route the `compromiseRecovery` refusal message already promises.
	 * It is the non-destructive terminal: completion carries the vault FORWARD to
	 * the new suite and marks the old one compromised; abort carries it BACK to
	 * the old suite, which stays `active` and readable.
	 *
	 * Abort is permitted ONLY while no record has been committed to the new
	 * suite. Once a record has moved, the two possible outcomes both lose data —
	 * discarding the successor strands what has moved, keeping it strands what has
	 * not — so the migration stays `in_progress` and the caller is pointed at
	 * resuming. This restriction is also exactly sufficient for the case abort
	 * exists to remedy: producing a valid re-encrypted record requires the
	 * plaintext, hence the master password, so a hostile session that never held
	 * it can never have committed a record and can always be aborted away.
	 *
	 * Idempotent by status, like completeMigration: a retried abort on an already
	 * terminal migration is a no-op, not a second teardown.
	 *
	 * @param string $migrationId The migration to abort
	 *
	 * @return array<string,mixed> The terminal migration plus an `aborted` flag
	 *
	 * @throws MigrationAbortRefusedException When a record has already been committed
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-migration-can-be-aborted-before-any-record-moves
	 */
	public function abortMigration(string $migrationId): array {
		$migration = $this->mapper->findById($migrationId);

		if ($migration->getStatus() !== 'in_progress') {
			$this->logger->info(
				'Keepiq: abortMigration called on an already-terminated migration; ignoring',
				['migrationId' => $migrationId, 'status' => $migration->getStatus()]
			);

			return [
				'status' => $migration->getStatus(),
				'aborted' => false,
				'alreadyTerminated' => true,
			];
		}

		$ownerId = $this->resolveOwnerId(suiteId: $migration->getOldSuiteId());

		// The one gate: nothing may have moved to the new suite yet.
		$committed = 0;
		if ($ownerId !== null) {
			$committed = $this->workService->countCommitted(migration: $migration, ownerId: $ownerId);
		}

		if ($committed > 0) {
			throw (new MigrationAbortRefusedException(
				message: sprintf(
					'%d record(s) have already been re-encrypted to the new suite, so this '
					. 'migration can no longer be aborted without losing data. Resume it to finish, '
					. 'or complete it.',
					$committed
				)
			))->withCommitted($committed);
		}

		// Terminal, but the RESTORATIVE terminal. The old suite is untouched and
		// stays active; the successor — created empty moments ago and never
		// written to — is discarded. It is DELETED rather than revoked on
		// purpose: revoking a user suite runs the lost-identity cascade
		// (EncryptionSuiteRevokedListener sweeps the owner's incoming
		// ShareTargets and promotes their delegations), which would destroy real
		// state over a migration the abort exists to undo.
		$migration->setStatus('aborted');
		$migration->setCompletedAt(new DateTime());
		$this->mapper->update($migration);

		try {
			$successor = $this->suiteMapper->findById($migration->getNewSuiteId());
			$this->suiteMapper->delete($successor);
		} catch (DoesNotExistException) {
			// Already gone — nothing to discard.
			$this->logger->warning(
				'Keepiq: successor suite already absent during abort',
				['migrationId' => $migrationId, 'newSuiteId' => $migration->getNewSuiteId()]
			);
		}

		$this->workService->clearFailureAccounting(migration: $migration);

		// NOT SuiteMigrationCompletedEvent: that event runs the terminal cascade
		// (compromise-flagging, link-share revocation, emergency-access
		// invalidation) which must never fire for an abort. The aborted event
		// carries the single reaction abort needs — releasing the SecretRequests
		// that SuiteMigrationStartedListener locked, keeping them on the old
		// suite — handled by SuiteMigrationAbortedListener.
		$this->eventDispatcher?->dispatchTyped(
			new SuiteMigrationAbortedEvent(
				oldSuiteId: $migration->getOldSuiteId(),
				newSuiteId: $migration->getNewSuiteId(),
				migrationId: $migration->getId(),
			)
		);

		$this->logger->info(
			"Keepiq: Compromise recovery aborted for migration {$migrationId}; vault returned to the old suite",
			['oldSuiteId' => $migration->getOldSuiteId()]
		);

		return (
			$migration->jsonSerialize() + [
				'aborted' => true,
			]
		);
	}//end abortMigration()

	/**
	 * Run everything that must happen — and must be allowed — before a
	 * migration may be marked terminal.
	 *
	 * Version history is deliberately lossy: only the head plus the N most
	 * recent snapshots are re-encrypted, and the rest are dropped here so the
	 * count is final and can be stated to the user in one number
	 * (secret-version-history spec). The drop runs BEFORE the outstanding-row
	 * check so out-of-window rows the client was never asked to migrate cannot
	 * hold the gate shut.
	 *
	 * Then the gate. Terminating marks the old suite compromised, and a
	 * compromised suite refuses to serve its ciphertext, so every row still
	 * bound to it loses access. Two very different situations look alike from
	 * here and must not be treated alike.
	 *
	 * Rows nobody attempted (no migration_error) mean the migration is simply
	 * unfinished — a closed tab, a crash, a `complete` that was never called.
	 * Finalising would take the whole un-reached remainder of the vault down
	 * with it, so this refuses and the user resumes.
	 *
	 * Rows that were attempted and reported unrecoverable are the other case:
	 * retrying will not help them, and holding the migration open forever would
	 * leave the vault write-locked with no way out. Those may be finalised, but
	 * only against an explicit acknowledgement of how many lose access.
	 *
	 * @param SuiteMigration $migration The migration being completed
	 * @param string|null $ownerId The resolved owner, or null
	 * @param int|null $acceptUnrecoverable The client's acknowledged loss count
	 *
	 * @return array{0:int,1:array<int,array<string,mixed>>} Dropped-version count
	 *                                                       and the rows that lose access
	 *
	 * @throws MigrationIncompleteException When the migration may not terminate
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-a-migration-always-has-a-way-to-terminate
	 */
	private function prepareTermination(
		SuiteMigration $migration,
		?string $ownerId,
		?int $acceptUnrecoverable,
	): array {
		if ($ownerId === null) {
			// Refuse rather than skip. Returning [0, []] here turned every
			// safety gate off in exactly the state where it is least safe to
			// proceed: dropVersionsBeyondWindow, assertNothingUnaccountedFor
			// and assertLossAcknowledged were all bypassed, the migration went
			// to `completed`, and markCompromised locked a suite that may still
			// have held every secret. An unresolvable owner means the gate
			// cannot be EVALUATED, which is not the same as the gate passing.
			// revokeOwnerKeyMaterial already refuses to guess in this
			// situation; this now matches it.
			$this->logger->error(
				'Keepiq: refusing to terminate a migration whose owner cannot be resolved',
				[
					'migrationId' => $migration->getId(),
					'oldSuiteId' => $migration->getOldSuiteId(),
				]
			);

			throw new MigrationIncompleteException(
				message: 'The owner of this migration could not be resolved, so it cannot be '
					. 'verified as complete. The old suite has been left active and nothing has '
					. 'been locked. This needs manual review.'
			);
		}

		$droppedVersions = $this->workService->dropVersionsBeyondWindow(
			migration: $migration,
			ownerId: $ownerId
		);

		$this->assertNothingUnaccountedFor(migration: $migration, ownerId: $ownerId);

		$unrecoverable = $this->workService->listUnrecoverable(migration: $migration);

		$this->assertLossAcknowledged(
			migration: $migration,
			acceptUnrecoverable: $acceptUnrecoverable
		);

		return [$droppedVersions, $unrecoverable];
	}//end prepareTermination()

	/**
	 * Revoke the key material the rotated-away suite could still open.
	 *
	 * Owner-scoped, so it cannot run without a resolved owner: targeting the
	 * wrong rows would revoke somebody else's shares. When the owner is
	 * unresolved this shouts and returns rather than guessing — the suite is
	 * already marked compromised by the caller, which is the security-critical
	 * half, and the rest needs manual review.
	 *
	 * @param SuiteMigration $migration The completed migration
	 * @param string|null $ownerId The resolved owner, or null
	 *
	 * @return void
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-suite-migration
	 */
	private function revokeOwnerKeyMaterial(SuiteMigration $migration, ?string $ownerId): void {
		if ($ownerId === null) {
			$this->logger->error(
				'Keepiq: migration completed but owner could not be resolved — '
				. 'link shares and passkeys were NOT revoked and need manual review',
				['migrationId' => $migration->getId(), 'oldSuiteId' => $migration->getOldSuiteId()]
			);
			return;
		}

		// Cascade-revoke every link share created by this user: the public-key
		// fingerprint baked into each share's encrypted snapshot belongs to the
		// now-compromised key pair, so any outstanding link must be force-locked
		// out and re-shared under the new suite (implement-link-sharing §5.2).
		$this->linkShareService->deleteByUserId(userId: $ownerId);

		// A new key pair invalidates every passkey unlock envelope — the wrapped
		// unlock key can never open the new suite (passkey-vault-login §D4).
		$this->passkeyService?->deleteAllOnRotation($ownerId);
	}//end revokeOwnerKeyMaterial()

	/**
	 * Refuse to terminate while rows remain that nobody has attempted.
	 *
	 * "Unaccounted for" means still on the old suite with no `migration_error`
	 * recorded against the owning secret — nobody has tried to migrate it. That
	 * is an unfinished migration, and finalising it would mark the old suite
	 * compromised and take the entire un-reached remainder of the vault down
	 * with it. The refusal is what pushes the user toward resuming instead.
	 *
	 * Rows that WERE attempted and reported unrecoverable are deliberately not
	 * counted here: keeping the migration open for them would hold the write
	 * lock forever with no way out, which is worse than the loss they represent
	 * and, per the invariant enforced in assertLossAcknowledged, they were
	 * already unreadable before this migration started.
	 *
	 * @param SuiteMigration $migration The migration being completed
	 * @param string $ownerId The migration owner's user id
	 *
	 * @return void
	 *
	 * @throws MigrationIncompleteException When unattempted rows remain
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	private function assertNothingUnaccountedFor(SuiteMigration $migration, string $ownerId): void {
		$outstanding = $this->workService->countOutstanding(
			migration: $migration,
			ownerId: $ownerId
		);

		if ($outstanding['unaccountedTotal'] === 0) {
			return;
		}

		$this->logger->warning(
			'Keepiq: refused to complete a migration with unattempted rows on the old suite',
			[
				'migrationId' => $migration->getId(),
				'oldSuiteId' => $migration->getOldSuiteId(),
				'unaccountedTotal' => $outstanding['unaccountedTotal'],
				'failedTotal' => $outstanding['failedTotal'],
			]
		);

		throw new MigrationIncompleteException(
			message: sprintf(
				'Migration is not complete: %d record(s) have not been migrated yet '
				. '(%d secret(s), %d version(s), %d attachment grant(s)). '
				. 'The migration remains in progress and can be resumed with the old master password.',
				$outstanding['unaccountedTotal'],
				$outstanding['unaccountedSecrets'],
				$outstanding['unaccountedVersions'],
				$outstanding['unaccountedGrants']
			)
		);
	}//end assertNothingUnaccountedFor()

	/**
	 * Refuse to lock secrets out of the vault without an explicit acknowledgement.
	 *
	 * Finalising with failures marks the old suite compromised, so every failed
	 * row loses access. That MUST be a decision the user made, never a
	 * side-effect of a client calling `complete`: a run in which every record
	 * failed — a wrong new public key, a broken crypto path — would otherwise
	 * silently lock a user out of their entire vault. The client therefore has
	 * to state how many losses it believes it is accepting, and the number has
	 * to match what the server can see.
	 *
	 * @param SuiteMigration $migration The migration being completed
	 * @param int|null $acceptUnrecoverable The client's acknowledged count
	 *
	 * @return void
	 *
	 * @throws MigrationIncompleteException When the loss is unacknowledged or miscounted
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/secrets/spec.md#requirement-possibly-compromised-flag-lifecycle
	 */
	private function assertLossAcknowledged(
		SuiteMigration $migration,
		?int $acceptUnrecoverable,
	): void {
		// The authoritative count, NOT count($unrecoverable): that list is
		// capped for display, so deriving the threshold from it would both
		// under-report the loss and make the acknowledgement unsatisfiable once
		// the real number exceeded the cap.
		$count = $this->workService->countUnrecoverable(migration: $migration);
		if ($count === 0) {
			return;
		}

		if ($acceptUnrecoverable === $count) {
			$this->logger->warning(
				'Keepiq: finalising a migration with acknowledged unrecoverable secrets',
				[
					'migrationId' => $migration->getId(),
					'oldSuiteId' => $migration->getOldSuiteId(),
					'unrecoverable' => $count,
				]
			);
			return;
		}

		// The number goes back in the response so the client can echo it
		// verbatim instead of counting its own list, which counts a different
		// thing (one entry per failed record per pass, versus distinct records
		// currently failed).
		throw (new MigrationIncompleteException(
			message: sprintf(
				'%d record(s) could not be decrypted with the old key and will lose access when this '
				. 'migration is finalised. Confirm by completing with acceptUnrecoverable=%d. '
				. 'Their stored ciphertext is retained either way.',
				$count,
				$count
			)
		))->withRequiredAcknowledgement($count);
	}//end assertLossAcknowledged()

	/**
	 * Resolve the owning user id for a suite.
	 *
	 * The terminal steps of a migration are owner-scoped (link-share revocation,
	 * passkey deletion, the audit actor on markCompromised) but a migration row
	 * records only suite ids, so the owner is resolved through the old suite.
	 *
	 * @param string $suiteId The suite id to resolve the owner of.
	 *
	 * @return string|null The owning user id, or null when unresolvable.
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-suite-migration
	 */
	private function resolveOwnerId(string $suiteId): ?string {
		try {
			$ownerId = $this->suiteMapper->findById($suiteId)->getOwnerId();
		} catch (DoesNotExistException) {
			$this->logger->error(
				'Keepiq: cannot resolve migration owner — suite missing',
				['suiteId' => $suiteId]
			);
			return null;
		}

		// EncryptionSuite::$ownerId is a non-nullable string defaulting to '',
		// so an unset owner arrives as the empty string, never as null.
		if ($ownerId === '') {
			return null;
		}

		return $ownerId;
	}//end resolveOwnerId()

	/**
	 * Get in-progress migration for a given owner (via their old suite).
	 *
	 * @param string $ownerType The owner type
	 * @param string $ownerId The owner ID
	 *
	 * @return SuiteMigration
	 *
	 * @throws DoesNotExistException if no in-progress migration exists
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-4
	 */
	public function getInProgressMigration(string $ownerType, string $ownerId): SuiteMigration {
		$suites = $this->suiteMapper->findByOwner($ownerType, $ownerId);

		foreach ($suites as $suite) {
			if ($this->mapper->hasInProgress($suite->getId()) === true) {
				return $this->mapper->findInProgressByOldSuiteId($suite->getId());
			}
		}

		throw new DoesNotExistException('No in-progress migration found');
	}//end getInProgressMigration()

	/**
	 * Fetch a migration by its ID.
	 *
	 * @param string $migrationId The migration ID
	 *
	 * @return SuiteMigration
	 *
	 * @throws DoesNotExistException if no migration with that ID exists
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-4
	 */
	public function getMigration(string $migrationId): SuiteMigration {
		return $this->mapper->findById($migrationId);
	}//end getMigration()

	/**
	 * Check if an owner is write-locked due to an active migration.
	 *
	 * @param string $ownerType The owner type
	 * @param string $ownerId The owner ID
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-4
	 */
	public function isWriteLocked(string $ownerType, string $ownerId): bool {
		// Delegated so there is one implementation. Services that only need to
		// ASK about the lock depend on WriteLockService directly, because
		// depending on this class would close a cycle through LinkShareService.
		return $this->writeLockService->isWriteLocked(ownerType: $ownerType, ownerId: $ownerId);
	}//end isWriteLocked()
}//end class
