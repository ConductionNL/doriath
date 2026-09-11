<?php

/**
 * Keepiq Migration Controller
 *
 * Controller for suite migration tracking.
 *
 * @category Controller
 * @package  OCA\Keepiq\Controller
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

namespace OCA\Keepiq\Controller;

use Exception;
use InvalidArgumentException;
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Attribute\VaultKeyProofRequired;
use OCA\Keepiq\Db\SuiteMigration;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\MigrationAbortRefusedException;
use OCA\Keepiq\Exception\MigrationIncompleteException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\EmergencyEnvelopeInvalidationService;
use OCA\Keepiq\Service\EncryptionSuiteService;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\MigrationWorkService;
use OCA\Keepiq\Service\VaultKeyProofService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for suite migration tracking.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The migration-work endpoints
 *   share one guard shell and one exception-to-status mapping, so the controller
 *   references the migration entity, the guard exceptions and the work services.
 *   Splitting the stores across controllers would duplicate the ownership guard
 *   once per store.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Same cause: one controller
 *   deliberately holds every per-record migration-work endpoint (secrets,
 *   versions, attachment grants, emergency contacts) plus status/complete/abort,
 *   because they all authorise through the same private requireOwnMigration
 *   guard. The aggregate complexity is the sum of small, uniform endpoints, not a
 *   single tangled method; dispersing them to satisfy the threshold would copy
 *   the guard into each new controller — the very IDOR risk the shared shell
 *   exists to prevent.
 */
class MigrationController extends OCSController {
	/**
	 * Constructor for MigrationController.
	 *
	 * @param IRequest $request The request object
	 * @param MigrationService $migrationService The migration service
	 * @param MigrationWorkService $workService The per-record migration work service
	 * @param EncryptionSuiteService $suiteService The suite service (ownership check)
	 * @param EmergencyEnvelopeInvalidationService $envelopeService The emergency-envelope re-point service
	 * @param IUserSession $userSession The user session
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private MigrationService $migrationService,
		private MigrationWorkService $workService,
		private EncryptionSuiteService $suiteService,
		private EmergencyEnvelopeInvalidationService $envelopeService,
		private IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Get in-progress migration status for the current user.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-4
	 */
	#[NoAdminRequired]
	public function getStatus(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$userId = $user->getUID();

		try {
			$migration = $this->migrationService->getInProgressMigration(ownerType: 'user', ownerId: $userId);
			return new JSONResponse(data: $migration->jsonSerialize());
		} catch (DoesNotExistException) {
			return new JSONResponse(data: ['status' => 'none']);
		}
	}//end getStatus()

	/**
	 * Complete a migration.
	 *
	 * @param string $id The migration ID
	 * @param bool $hasErrors Whether the migration had errors
	 * @param int|null $acceptUnrecoverable Acknowledged count of secrets that will lose access
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $hasErrors is outcome DATA carried
	 *   in the POST body and bound by name by the Nextcloud router, not a mode switch
	 *   the caller picks: it only selects which terminal status string is recorded.
	 *   Splitting the method would split the route and change the HTTP contract.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-4
	 */
	#[NoAdminRequired]
	#[VaultKeyProofRequired(
		binds: ['id'],
		subject: 'migrationOldSuite',
		purpose: VaultKeyProofService::PURPOSE_COMPLETE_MIGRATION
	)]
	public function complete(string $id, bool $hasErrors = false, ?int $acceptUnrecoverable = null): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			// Enforce ownership: verify the migration's old suite belongs to the
			// current user before allowing them to mark it complete.
			$this->requireOwnMigration(migrationId: $id, userId: $user->getUID());

			$result = $this->migrationService->completeMigration(
				migrationId: $id,
				hasErrors: $hasErrors,
				acceptUnrecoverable: $acceptUnrecoverable
			);
			return new JSONResponse(data: $result);
		} catch (ForbiddenException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		} catch (MigrationIncompleteException $e) {
			// Distinct from a generic fault: the migration is intact, still
			// in progress, and resumable. The client must not treat this as
			// "rotation finished".
			//
			// `requiredAcknowledgement` is the authoritative number of
			// unrecoverable records. The client echoes it back rather than
			// counting its own failure list, which counts a different thing —
			// one entry per failed record per pass, versus distinct records
			// currently failed. Null when the refusal is about rows nobody has
			// attempted yet, which resuming fixes rather than acknowledging.
			$body = [
				'error' => 'migration_incomplete',
				'message' => $e->getMessage(),
			];

			$required = $e->getRequiredAcknowledgement();
			if ($required !== null) {
				$body['requiredAcknowledgement'] = $required;
			}

			return new JSONResponse(data: $body, statusCode: Http::STATUS_CONFLICT);
		} catch (NotFoundException $e) {
			// Was falling through to the generic arm and returning 400, while
			// getWork and all three re-encryption endpoints return 404 for the
			// same condition. A client could not tell "no such migration" from
			// "malformed request" on the one endpoint where it matters most.
			return new JSONResponse(
				data: ['message' => $e->getMessage()],
				statusCode: Http::STATUS_NOT_FOUND
			);
		} catch (Exception $e) {
			return new JSONResponse(
				data: ['message' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}//end try
	}//end complete()

	/**
	 * Abort a migration, returning the vault to the old suite.
	 *
	 * The endpoint the `compromiseRecovery` refusal already tells users to use.
	 * Non-destructive: it discards the unused successor and leaves the old suite
	 * active. Permitted only while no record has been committed to the new suite;
	 * once records have moved the server refuses and points at resuming.
	 *
	 * @param string $id The migration ID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/harden-vault-key-material-guards/specs/encryption-suites/spec.md#requirement-a-migration-can-be-aborted-before-any-record-moves
	 */
	#[NoAdminRequired]
	public function abort(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->requireOwnMigration(migrationId: $id, userId: $user->getUID());

			$result = $this->migrationService->abortMigration(migrationId: $id);
			return new JSONResponse(data: $result);
		} catch (ForbiddenException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		} catch (MigrationAbortRefusedException $e) {
			// The migration is intact and resumable — a record has already
			// moved, so abort would lose data. Distinct from a generic fault so
			// the client offers "resume", not "try abort again".
			return new JSONResponse(
				data: [
					'error' => 'migration_abort_refused',
					'message' => $e->getMessage(),
					'committed' => $e->getCommitted(),
				],
				statusCode: Http::STATUS_CONFLICT
			);
		} catch (NotFoundException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
		} catch (Exception $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}//end try
	}//end abort()

	/**
	 * List the records still bound to the migration's old suite.
	 *
	 * This is the migration's work list and its progress denominator, derived
	 * by query from the rows themselves rather than from a client-reported
	 * count — so a resumed migration asks again and gets exactly what is left.
	 * Read-only: the version rows beyond the re-encryption window are reported
	 * as a count here and dropped when the migration terminates.
	 *
	 * @param string $id The migration ID
	 * @param int $limit Page size per store
	 * @param int $offset Page offset per store
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	#[NoAdminRequired]
	public function getWork(string $id, int $limit = 25, int $offset = 0): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$userId = $user->getUID();

		try {
			$migration = $this->requireOwnMigration(migrationId: $id, userId: $userId);

			return new JSONResponse(
				data: $this->workService->listWork(
					migration: $migration,
					ownerId: $userId,
					limit: $limit,
					offset: $offset
				)
			);
		} catch (NotFoundException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		}
	}//end getWork()

	/**
	 * Commit one re-encrypted secret, or record its failure.
	 *
	 * The browser sends the outcome for exactly one record: ciphertext it has
	 * already decrypted back and byte-compared under the new private key, or
	 * an `error` describing why that verification failed. A failure is recorded
	 * and the run continues; the original ciphertext is left untouched.
	 *
	 * @param string $id The migration ID
	 * @param string $secretId The secret ID
	 * @param string|null $key The re-encrypted key ciphertext
	 * @param string|null $login The re-encrypted login ciphertext
	 * @param string|null $additionalFields The re-encrypted additional-fields ciphertext
	 * @param string|null $error A per-record failure to record instead
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	#[NoAdminRequired]
	public function reEncryptSecret(
		string $id,
		string $secretId,
		?string $key = null,
		?string $login = null,
		?string $additionalFields = null,
		?string $error = null,
	): JSONResponse {
		$userId = $this->uid();
		if ($userId === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$migration = $this->requireOwnMigration(migrationId: $id, userId: $userId);
		} catch (NotFoundException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		}

		return $this->commitRecord(
			migration: $migration,
			userId: $userId,
			store: 'secrets',
			recordId: $secretId,
			error: $error,
			commit: function ($migration, $userId) use ($secretId, $key, $login, $additionalFields) {
				if ($key === null) {
					throw new ForbiddenException(message: 'A re-encrypted key ciphertext is required');
				}

				$secret = $this->workService->commitSecret(
					migration: $migration,
					ownerId: $userId,
					secretId: $secretId,
					key: $key,
					login: $login,
					additionalFields: $additionalFields
				);

				return [
					'id' => $secret->getId(),
					'encryptionSuiteId' => $secret->getEncryptionSuiteId(),
					'possiblyCompromisedAt' => $secret->getPossiblyCompromisedAt()?->format('c'),
				];
			}
		);
	}//end reEncryptSecret()

	/**
	 * Commit one re-encrypted secret version, or record its failure.
	 *
	 * @param string $id The migration ID
	 * @param string $versionId The version ID
	 * @param string|null $key The re-encrypted key ciphertext
	 * @param string|null $login The re-encrypted login ciphertext
	 * @param string|null $additionalFields The re-encrypted additional-fields ciphertext
	 * @param string|null $error A per-record failure to record instead
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	#[NoAdminRequired]
	public function reEncryptVersion(
		string $id,
		string $versionId,
		?string $key = null,
		?string $login = null,
		?string $additionalFields = null,
		?string $error = null,
	): JSONResponse {
		$userId = $this->uid();
		if ($userId === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$migration = $this->requireOwnMigration(migrationId: $id, userId: $userId);
		} catch (NotFoundException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		}

		return $this->commitRecord(
			migration: $migration,
			userId: $userId,
			store: 'versions',
			recordId: $versionId,
			error: $error,
			commit: function ($migration, $userId) use ($versionId, $key, $login, $additionalFields) {
				if ($key === null) {
					throw new ForbiddenException(message: 'A re-encrypted key ciphertext is required');
				}

				$version = $this->workService->commitVersion(
					migration: $migration,
					ownerId: $userId,
					versionId: $versionId,
					key: $key,
					login: $login,
					additionalFields: $additionalFields
				);

				return [
					'id' => $version->getId(),
					'secretId' => $version->getSecretId(),
					'encryptionSuiteId' => $version->getEncryptionSuiteId(),
				];
			}
		);
	}//end reEncryptVersion()

	/**
	 * Commit one re-wrapped attachment grant, or record its failure.
	 *
	 * @param string $id The migration ID
	 * @param string $grantId The attachment grant ID
	 * @param string|null $wrappedFileKey The file key re-wrapped under the new suite
	 * @param string|null $error A per-record failure to record instead
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	#[NoAdminRequired]
	public function reEncryptAttachmentGrant(
		string $id,
		string $grantId,
		?string $wrappedFileKey = null,
		?string $error = null,
	): JSONResponse {
		$userId = $this->uid();
		if ($userId === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$migration = $this->requireOwnMigration(migrationId: $id, userId: $userId);
		} catch (NotFoundException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		}

		return $this->commitRecord(
			migration: $migration,
			userId: $userId,
			store: 'attachmentGrants',
			recordId: $grantId,
			error: $error,
			commit: function ($migration, $userId) use ($grantId, $wrappedFileKey) {
				if ($wrappedFileKey === null) {
					throw new ForbiddenException(message: 'A re-wrapped file key is required');
				}

				$grant = $this->workService->commitAttachmentGrant(
					migration: $migration,
					ownerId: $userId,
					grantId: $grantId,
					wrappedFileKey: $wrappedFileKey
				);

				return [
					'id' => $grant->getId(),
					'secretId' => $grant->getSecretId(),
					'encryptionSuiteId' => $grant->getEncryptionSuiteId(),
				];
			}
		);
	}//end reEncryptAttachmentGrant()

	/**
	 * Re-point one emergency-access recovery envelope onto the new suite.
	 *
	 * Emergency contacts are the one migrated store not produced by
	 * decrypt-then-re-encrypt: the browser builds a fresh envelope escrowing the
	 * NEW private key, sealed to the grantee's current certificate, and posts it
	 * here. Deliberately NOT routed through commitRecord: emergency contacts are
	 * outside the completion gate (design D2), so there is no per-record failure
	 * to account and a contact the browser could not carry is simply left on the
	 * old suite for the completion sweep to invalidate — never recorded as a
	 * migration failure that would block the gate.
	 *
	 * @param string $id The migration ID
	 * @param string $contactId The emergency-contact ID
	 * @param string|null $recoveryEnvelope The fresh envelope escrowing the new private key
	 * @param string|null $granteeSuiteId The grantee suite the envelope was sealed to
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/migrate-emergency-access-on-rotation/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	#[NoAdminRequired]
	public function reEnvelopeEmergencyContact(
		string $id,
		string $contactId,
		?string $recoveryEnvelope = null,
		?string $granteeSuiteId = null,
	): JSONResponse {
		$userId = $this->uid();
		if ($userId === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		if ($recoveryEnvelope === null || $granteeSuiteId === null) {
			return new JSONResponse(
				data: ['message' => 'A recovery envelope and grantee suite are required'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$migration = $this->requireOwnMigration(migrationId: $id, userId: $userId);

			// Re-pointing to the new suite only makes sense while the migration
			// owns the write lock; once terminated the sweep has already run.
			if ($migration->getStatus() !== 'in_progress') {
				return new JSONResponse(
					data: ['message' => 'Migration is no longer in progress'],
					statusCode: Http::STATUS_CONFLICT
				);
			}

			$contact = $this->envelopeService->reEnvelopeForRotation(
				ownerId: $userId,
				oldSuiteId: $migration->getOldSuiteId(),
				newSuiteId: $migration->getNewSuiteId(),
				contactId: $contactId,
				recoveryEnvelope: $recoveryEnvelope,
				sealedSuiteId: $granteeSuiteId
			);

			return new JSONResponse(
				data: [
					'id' => $contact->getId(),
					'grantorSuiteId' => $contact->getGrantorSuiteId(),
					'state' => $contact->getState(),
				]
			);
		} catch (NotFoundException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}//end try
	}//end reEnvelopeEmergencyContact()

	/**
	 * The acting user's id, or null when unauthenticated.
	 *
	 * @return string|null
	 */
	private function uid(): ?string {
		return $this->userSession->getUser()?->getUID();
	}//end uid()

	/**
	 * Shared tail for the three per-record endpoints.
	 *
	 * Holds the parts that MUST be identical across all three — the in-progress
	 * check, the error-vs-commit branch and the exception mapping — so a future
	 * fourth store cannot ship with weaker handling than the other three.
	 *
	 * The ownership guard deliberately does NOT live here: each endpoint calls
	 * `requireOwnMigration` in its own body and passes the result in, so the
	 * authorization is visible at the endpoint it protects rather than buried
	 * one call deeper (hydra-gate-no-admin-idor reads method bodies, and so do
	 * people).
	 *
	 * @param SuiteMigration $migration The already-guarded migration
	 * @param string $userId The acting user's id
	 * @param string $store The store being written
	 * @param string $recordId The record ID
	 * @param string|null $error A per-record failure to record instead of committing
	 * @param callable $commit Performs the guarded commit, returning the response body
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	private function commitRecord(
		SuiteMigration $migration,
		string $userId,
		string $store,
		string $recordId,
		?string $error,
		callable $commit,
	): JSONResponse {
		try {
			// A terminated migration has already released the write lock and
			// marked the old suite compromised. Accepting more re-encryptions
			// against it would re-point rows to a suite whose migration is
			// closed, with no gate left to notice.
			if ($migration->getStatus() !== 'in_progress') {
				return new JSONResponse(
					data: ['message' => 'Migration is no longer in progress'],
					statusCode: Http::STATUS_CONFLICT
				);
			}

			if ($error !== null) {
				$secretId = $this->workService->recordFailure(
					migration: $migration,
					ownerId: $userId,
					store: $store,
					recordId: $recordId,
					message: $error
				);

				return new JSONResponse(
					data: [
						'recorded' => true,
						'store' => $store,
						'recordId' => $recordId,
						'secretId' => $secretId,
					]
				);
			}

			return new JSONResponse(data: $commit($migration, $userId));
		} catch (NotFoundException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		} catch (Exception $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}//end try
	}//end commitRecord()

	/**
	 * Resolve a migration and insist it belongs to the acting user.
	 *
	 * A migration row records only suite ids, so ownership is established
	 * through the old suite. Without this every migration id would be a
	 * cross-user handle onto somebody else's re-encryption endpoints.
	 *
	 * @param string $migrationId The migration ID
	 * @param string $userId The acting user's ID
	 *
	 * @return SuiteMigration
	 *
	 * @throws NotFoundException When no such migration or suite exists
	 * @throws ForbiddenException When the migration belongs to another user
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	private function requireOwnMigration(string $migrationId, string $userId): SuiteMigration {
		try {
			$migration = $this->migrationService->getMigration(migrationId: $migrationId);
			$oldSuite = $this->suiteService->getSuite($migration->getOldSuiteId());
		} catch (DoesNotExistException) {
			throw new NotFoundException(message: 'Migration not found');
		}

		if ($oldSuite->getOwnerType() !== 'user' || $oldSuite->getOwnerId() !== $userId) {
			throw new ForbiddenException(message: 'Forbidden: migration does not belong to you');
		}

		return $migration;
	}//end requireOwnMigration()
}//end class
