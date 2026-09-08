<?php

/**
 * Keepiq Share Service
 *
 * The user-to-user secret-share lifecycle: createShare (owner/delegate
 * authorization + recipient-suite precondition), createBatchShares (group
 * expansion) and getSharesForSecret (owner/delegate-only view).
 *
 * The three surfaces that used to live here alongside them have their own
 * classes now: bulk registration of pre-encrypted rows
 * (DirectShareRegistrar), the multi-recipient re-encryption fan-out
 * (ShareSyncService) and revocation with its delete cascade
 * (ShareRevocationService). Their public entry points are re-exported
 * below so ShareController and SecretService keep one seam.
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
use InvalidArgumentException;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Business logic for the user-to-user secret-share lifecycle.
 *
 * Authorization is two-tier: the Secret owner is always authorized; a
 * SecretDelegation (temporary or permanent) authorizes the delegate as
 * well. Every path — creation here, plus revocation and sync in their own
 * classes — falls through ShareAuthorizationService, so the authorization
 * surface stays in one place.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Pre-existing suppression,
 *   narrowed but not yet retired. The four mappers it used to thread through
 *   are gone: authorization moved to ShareAuthorizationService, the bulk path
 *   to DirectShareRegistrar, the fan-out to ShareSyncService and the delete
 *   cascade to ShareRevocationService, which took the value from 22 to 14.
 *   Eleven of those fourteen are what createShare/createBatchShares/
 *   listSharesForSecret genuinely need; the last three are the collaborators
 *   whose entry points this class re-exports so ShareController and
 *   SecretService keep one seam. Retiring the tag means repointing those two
 *   callers, which is outside this change.
 */
class ShareService {

	/**
	 * The share audit trail.
	 *
	 * @var ShareAuditTrail
	 */
	private ShareAuditTrail $auditTrail;

	/**
	 * Constructor for ShareService.
	 *
	 * @param ShareTargetMapper $mapper The share-target mapper
	 * @param IDBConnection $db The DB connection (batch transaction)
	 * @param NotificationService $notificationService The notification dispatcher
	 * @param ShareAuthorizationService $auth The share authorization service
	 * @param DirectShareRegistrar $directRegistrar The bulk direct-share registrar
	 * @param ShareSyncService $syncService The multi-recipient sync service
	 * @param ShareRevocationService $revocationService The revocation + cascade service
	 * @param ShareAuditTrail|null $auditTrail The share audit trail
	 *
	 * @return void
	 */
	public function __construct(
		private ShareTargetMapper $mapper,
		private IDBConnection $db,
		private NotificationService $notificationService,
		private ShareAuthorizationService $auth,
		private DirectShareRegistrar $directRegistrar,
		private ShareSyncService $syncService,
		private ShareRevocationService $revocationService,
		?ShareAuditTrail $auditTrail = null,
	) {
		$this->auditTrail = ($auditTrail ?? new ShareAuditTrail());
	}//end __construct()

	/**
	 * Register a batch of DIRECT user-to-user shares from client-encrypted
	 * blobs (bulk-actions §6.1/§7.1).
	 *
	 * @param string $userId The sharing owner
	 * @param array<int,array<string,mixed>> $shares Rows {sourceSecretId, targetUserId, encryptedKey, encryptedLogin?, encryptedAdditionalFields?}
	 *
	 * @return array<int,array{sourceSecretId:string,targetUserId:string,status:string,recipientSecretId?:string}>
	 *
	 * @spec openspec/specs/bulk-actions/spec.md#requirement-the-four-bulk-operations
	 */
	public function registerDirectShares(string $userId, array $shares): array {
		return $this->directRegistrar->registerDirectShares(userId: $userId, shares: $shares);
	}//end registerDirectShares()

	/**
	 * The active-suite PEM certificate of a share recipient — public key
	 * material only (needed client-side to encrypt the copy; ADR-003).
	 *
	 * @param string $targetUserId The prospective recipient
	 *
	 * @return string|null The PEM certificate (null = no active suite)
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-share-a-secret
	 */
	public function recipientCertificate(string $targetUserId): ?string {
		return $this->directRegistrar->recipientCertificate(targetUserId: $targetUserId);
	}//end recipientCertificate()

	/**
	 * The active certificates of several prospective recipients.
	 *
	 * @param string[] $targetUserIds The prospective recipients
	 *
	 * @return array<string,string> PEM certificate, keyed by user ID
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-recipient-shareability-lookup
	 */
	public function recipientCertificates(array $targetUserIds): array {
		return $this->directRegistrar->recipientCertificates(targetUserIds: $targetUserIds);
	}//end recipientCertificates()

	/**
	 * Create a single share target record.
	 *
	 * Authorization: $userId must be the Secret owner OR an active
	 * delegate. Precondition: the recipient must have an active
	 * EncryptionSuite (the browser-encrypted blob is useless otherwise).
	 * The recipient's encrypted Secret copy must already exist; the
	 * browser persists it through the SecretController before this call.
	 *
	 * @param string $sourceSecretId The owner's source secret ID
	 * @param string $targetUserId The recipient's Nextcloud user ID
	 * @param string $recipientSecretId The recipient's encrypted Secret copy ID
	 * @param string|null $groupShareId Optional group-share linkage
	 * @param string $userId The Nextcloud user ID creating the share
	 *
	 * @return ShareTarget
	 *
	 * @throws InvalidArgumentException When validation fails
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#3.2
	 */
	public function createShare(
		string $sourceSecretId,
		string $targetUserId,
		string $recipientSecretId,
		?string $groupShareId,
		string $userId,
	): ShareTarget {
		if ($sourceSecretId === '') {
			throw new InvalidArgumentException(message: 'sourceSecretId is required');
		}

		if ($targetUserId === '') {
			throw new InvalidArgumentException(message: 'targetUserId is required');
		}

		if ($recipientSecretId === '') {
			throw new InvalidArgumentException(message: 'recipientSecretId is required');
		}

		$source = $this->auth->loadSecret(secretId: $sourceSecretId);

		if ($targetUserId === $source->getOwnerId()) {
			throw new InvalidArgumentException(message: 'Cannot share a secret with its owner');
		}

		if ($targetUserId === $userId) {
			throw new InvalidArgumentException(message: 'Cannot share a secret with yourself');
		}

		$this->auth->assertOwnerOrDelegate(secret: $source, userId: $userId);
		$this->auth->assertRecipientHasActiveSuite(targetUserId: $targetUserId);

		// Enforce one-share-per-(source,recipient) invariant.
		try {
			$this->mapper->findBySourceSecretAndTargetUser(
				sourceSecretId: $sourceSecretId,
				targetUserId: $targetUserId
			);
			throw new InvalidArgumentException(message: 'Secret is already shared with this user');
		} catch (DoesNotExistException) {
			// No existing row — proceed.
		}

		$entity = new ShareTarget();
		$entity->setId(Uuid::uuid4()->toString());
		$entity->setSourceSecretId($sourceSecretId);
		$entity->setTargetUserId($targetUserId);
		$entity->setSecretId($recipientSecretId);
		$entity->setGroupShareId($groupShareId);
		$entity->setCreatedBy($userId);
		$entity->setCreatedAt(new DateTime());

		$persisted = $this->mapper->insert($entity);

		// Fire-and-forget notification to the recipient. The user
		// preference + opt-out check happens inside NotificationService.
		$this->notificationService->notify(
			subject: 'secret_shared',
			recipientId: $targetUserId,
			params: [
				'secretId' => $sourceSecretId,
				'secretName' => $source->getName(),
				'sharedBy' => $userId,
			],
			objectType: 'secret',
			objectId: $sourceSecretId,
		);

		$this->auditTrail->recordShareGranted(
			userId: $userId,
			shareId: $persisted->getId(),
			secretName: $source->getName(),
			recipientId: $targetUserId,
		);

		return $persisted;
	}//end createShare()

	/**
	 * Create a batch of recipient share targets for a group expansion.
	 *
	 * Each member-row blob is expected to carry `{targetUserId,
	 * recipientSecretId}` — the caller (controller) has already created the
	 * recipient Secret rows in the browser and POSTed them. The entire batch
	 * shares one `$groupShareId` so revocation/leave handling can cascade.
	 *
	 * The rows arrive verbatim from an untrusted `#[NoAdminRequired]` request
	 * body, so the shape is deliberately typed as loose `mixed` here — the
	 * per-row guard below is the real validation and must not be removed on
	 * the strength of a docblock promise.
	 *
	 * @param string $sourceSecretId The owner's source secret ID
	 * @param array<int,array<string,mixed>> $shares The per-recipient batch
	 * @param string $groupShareId The GroupShare ID for cascade
	 * @param string $userId The initiator
	 *
	 * @return ShareTarget[]
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#3.6
	 */
	public function createBatchShares(
		string $sourceSecretId,
		array $shares,
		string $groupShareId,
		string $userId,
	): array {
		$created = [];
		$this->db->beginTransaction();
		try {
			foreach ($shares as $row) {
				$targetUserId = (string)($row['targetUserId'] ?? '');
				$recipientSecretId = (string)($row['recipientSecretId'] ?? '');
				if ($targetUserId === '' || $recipientSecretId === '') {
					continue;
				}

				$created[] = $this->createShare(
					sourceSecretId: $sourceSecretId,
					targetUserId: $targetUserId,
					recipientSecretId: $recipientSecretId,
					groupShareId: $groupShareId,
					userId: $userId,
				);
			}

			$this->db->commit();
		} catch (Throwable $exception) {
			$this->db->rollBack();
			throw $exception;
		}//end try

		return $created;
	}//end createBatchShares()

	/**
	 * List all share targets for a given source secret.
	 *
	 * Only the owner or an active delegate sees the recipient list; for
	 * recipients and non-participants the return is an empty array (so
	 * UI can hide the section without raising a 403).
	 *
	 * @param string $sourceSecretId The source secret ID
	 * @param string $userId The requesting user ID
	 *
	 * @return ShareTarget[]
	 *
	 * @spec openspec/changes/implement-user-sharing/tasks.md#3.5
	 */
	public function listSharesForSecret(string $sourceSecretId, string $userId = ''): array {
		if ($userId === '') {
			// Back-compat: callers that do not provide a userId get the
			// raw list (scaffold semantics, used by the cascade path).
			return $this->mapper->findBySourceSecret($sourceSecretId);
		}

		try {
			$source = $this->auth->loadSecret(secretId: $sourceSecretId);
		} catch (InvalidArgumentException) {
			return [];
		}

		if ($this->auth->isOwnerOrDelegate(secret: $source, userId: $userId) === false) {
			// A write-grade team member needs the recipient list (+
			// certificates) to run the re-encrypt fan-out
			// (folder-permission-grades §2.3); read grades see nothing.
			if ($this->auth->resolveGrade(secret: $source, userId: $userId) !== 'write') {
				return [];
			}
		}

		return $this->mapper->findBySourceSecret($sourceSecretId);
	}//end listSharesForSecret()

	/**
	 * Revoke a single share target — deletes the recipient's encrypted
	 * Secret copy and the share-target row in one transaction.
	 *
	 * @param string $shareId The share-target row ID
	 * @param string $userId The Nextcloud user ID requesting the revoke
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the row does not exist or
	 *                                  the caller is not authorized.
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-revoke-share
	 */
	public function revokeShare(string $shareId, string $userId): void {
		$this->revocationService->revokeShare(shareId: $shareId, userId: $userId);
	}//end revokeShare()

	/**
	 * Push an updated encrypted blob to every recipient.
	 *
	 * @param string $secretId The source secret ID
	 * @param array<int,array<string,string|null>> $updates The per-recipient blobs
	 * @param string $expectedUpdatedAt The owner-side expected ISO timestamp
	 * @param string $userId The requesting user
	 *
	 * @return int Number of recipient copies updated.
	 *
	 * @throws InvalidArgumentException When validation or optimistic-lock check fails
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-sync-on-update
	 */
	public function syncUpdate(
		string $secretId,
		array $updates,
		string $expectedUpdatedAt,
		string $userId,
	): int {
		return $this->syncService->syncUpdate(
			secretId: $secretId,
			updates: $updates,
			expectedUpdatedAt: $expectedUpdatedAt,
			userId: $userId,
		);
	}//end syncUpdate()

	/**
	 * The write context of a secret for the current user
	 * (folder-permission-grades §4).
	 *
	 * @param string $secretId The secret (source or recipient copy) UUID
	 * @param string $userId The requesting user
	 *
	 * @return array{sourceSecretId:string, effectiveGrade:string, ownerCertificate:string|null, sourceUpdatedAt:string|null}
	 *
	 * @throws InvalidArgumentException When the secret does not exist
	 *
	 * @spec openspec/specs/folder-permission-grades/spec.md#requirement-a-write-grade-member-may-update-a-folder-secret-for-all-recipients
	 */
	public function writeContext(string $secretId, string $userId): array {
		return $this->syncService->writeContext(secretId: $secretId, userId: $userId);
	}//end writeContext()

	/**
	 * Cascade-delete all share targets for a secret (called on secret delete).
	 *
	 * @param string $sourceSecretId The source secret ID
	 *
	 * @return void
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-revoke-share
	 */
	public function deleteAllForSecret(string $sourceSecretId): void {
		$this->revocationService->deleteAllForSecret(sourceSecretId: $sourceSecretId);
	}//end deleteAllForSecret()
}//end class
