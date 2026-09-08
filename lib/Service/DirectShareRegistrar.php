<?php

/**
 * Keepiq Direct Share Registrar
 *
 * Bulk registration of DIRECT user-to-user shares from client-encrypted
 * blobs (bulk-actions §6.1/§7.1). For each row the server creates the
 * recipient's Secret copy from the pre-encrypted fields and links the
 * ShareTarget — idempotent, owner-scoped per item, and skip-not-fail so a
 * mixed selection never aborts the run. The server never sees plaintext
 * (ADR-003).
 *
 * Extracted from ShareService: bulk registration has its own report
 * shape, its own per-item guard vocabulary and its own recipient-copy
 * builder, none of which the single-share lifecycle uses.
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
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Ramsey\Uuid\Uuid;

/**
 * Registers batches of pre-encrypted direct shares.
 */
class DirectShareRegistrar {

	/**
	 * The share audit trail.
	 *
	 * @var ShareAuditTrail
	 */
	private ShareAuditTrail $auditTrail;

	/**
	 * Constructor for DirectShareRegistrar.
	 *
	 * @param ShareTargetMapper $mapper The share-target mapper
	 * @param SecretMapper $secretMapper The Secret mapper (owner lookups)
	 * @param RecipientSecretCopyFactory $copyFactory The recipient-copy factory
	 * @param NotificationService $notificationService The notification dispatcher
	 * @param ShareAuditTrail|null $auditTrail The share audit trail
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; the registration behaviour carries the spec anchors.
	 */
	public function __construct(
		private ShareTargetMapper $mapper,
		private SecretMapper $secretMapper,
		private RecipientSecretCopyFactory $copyFactory,
		private NotificationService $notificationService,
		?ShareAuditTrail $auditTrail = null,
	) {
		$this->auditTrail = ($auditTrail ?? new ShareAuditTrail());
	}//end __construct()

	/**
	 * Register a batch of DIRECT user-to-user shares from client-encrypted
	 * blobs: idempotent (an existing share reports `exists`), owner-scoped
	 * per item, and skip-not-fail for ineligible rows. Mirrors the
	 * team-folder fan-out registration.
	 *
	 * @param string $userId The sharing owner
	 * @param array<int,array<string,mixed>> $shares Rows {sourceSecretId, targetUserId, encryptedKey, encryptedLogin?, encryptedAdditionalFields?}
	 *
	 * @return array<int,array{sourceSecretId:string,targetUserId:string,status:string,recipientSecretId?:string}>
	 *
	 * @spec openspec/specs/bulk-actions/spec.md#requirement-the-four-bulk-operations
	 */
	public function registerDirectShares(string $userId, array $shares): array {
		$report = [];
		$newRecipients = [];
		foreach ($shares as $row) {
			$entry = $this->registerDirectShare(userId: $userId, row: $row);
			$report[] = $entry;
			if ($entry['status'] === 'created') {
				$newRecipients[$entry['targetUserId']] = true;
			}
		}

		// One notification per recipient per run — not per secret.
		foreach (array_keys($newRecipients) as $recipientId) {
			$this->notificationService->notify(
				subject: 'secret_shared',
				recipientId: (string)$recipientId,
				params: ['shared_by' => $userId],
			);
		}

		return $report;
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
		return $this->copyFactory->certificateFor(targetUserId: $targetUserId);
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
		return $this->copyFactory->certificatesFor(targetUserIds: $targetUserIds);
	}//end recipientCertificates()

	/**
	 * Validate one bulk-share row and, when it is well-formed, register it.
	 *
	 * Skip-not-fail: every rejection is reported as a status so a mixed
	 * selection never aborts the run.
	 *
	 * @param string $userId The sharing owner
	 * @param array<string,mixed> $row One {sourceSecretId, targetUserId, encryptedKey, ...} row
	 *
	 * @return array{sourceSecretId:string,targetUserId:string,status:string,recipientSecretId?:string}
	 */
	private function registerDirectShare(string $userId, array $row): array {
		$sourceSecretId = (string)($row['sourceSecretId'] ?? '');
		$targetUserId = (string)($row['targetUserId'] ?? '');
		$encryptedKey = (string)($row['encryptedKey'] ?? '');
		if ($sourceSecretId === '' || $targetUserId === '' || $encryptedKey === '') {
			return [
				'sourceSecretId' => $sourceSecretId,
				'targetUserId' => $targetUserId,
				'status' => 'invalid',
			];
		}

		if ($targetUserId === $userId) {
			return [
				'sourceSecretId' => $sourceSecretId,
				'targetUserId' => $targetUserId,
				'status' => 'self',
			];
		}

		return $this->createDirectShare(
			userId: $userId,
			sourceSecretId: $sourceSecretId,
			targetUserId: $targetUserId,
			encryptedKey: $encryptedKey,
			row: $row
		);
	}//end registerDirectShare()

	/**
	 * Create the recipient copy and the ShareTarget row for one validated
	 * bulk-share row, enforcing the owner guard and idempotency.
	 *
	 * @param string $userId The sharing owner
	 * @param string $sourceSecretId The owner's source secret
	 * @param string $targetUserId The recipient
	 * @param string $encryptedKey Recipient-encrypted key blob
	 * @param array<string,mixed> $row The original row (optional blobs)
	 *
	 * @return array{sourceSecretId:string,targetUserId:string,status:string,recipientSecretId?:string}
	 */
	private function createDirectShare(
		string $userId,
		string $sourceSecretId,
		string $targetUserId,
		string $encryptedKey,
		array $row,
	): array {
		// Per-item owner guard — a foreign secret is skipped, never a
		// whole-batch failure and never an oracle.
		try {
			$source = $this->secretMapper->findById($sourceSecretId);
		} catch (DoesNotExistException) {
			$source = null;
		}

		if ($source === null || $source->getOwnerType() !== 'user' || $source->getOwnerId() !== $userId) {
			return [
				'sourceSecretId' => $sourceSecretId,
				'targetUserId' => $targetUserId,
				'status' => 'not_owned',
			];
		}

		// Idempotency: an existing share (any provenance) is `exists`.
		try {
			$this->mapper->findBySourceSecretAndTargetUser(
				sourceSecretId: $sourceSecretId,
				targetUserId: $targetUserId
			);
			return [
				'sourceSecretId' => $sourceSecretId,
				'targetUserId' => $targetUserId,
				'status' => 'exists',
			];
		} catch (DoesNotExistException) {
			// No existing share — create below.
		}

		$copy = $this->copyFactory->create(
			source: $source,
			targetUserId: $targetUserId,
			encryptedKey: $encryptedKey,
			encryptedLogin: $this->optionalString(value: ($row['encryptedLogin'] ?? null)),
			encryptedExtras: $this->optionalString(value: ($row['encryptedAdditionalFields'] ?? null)),
		);
		if ($copy === null) {
			return [
				'sourceSecretId' => $sourceSecretId,
				'targetUserId' => $targetUserId,
				'status' => 'no_suite',
			];
		}

		$entity = new ShareTarget();
		$entity->setId(Uuid::uuid4()->toString());
		$entity->setSourceSecretId($sourceSecretId);
		$entity->setTargetUserId($targetUserId);
		$entity->setSecretId($copy->getId());
		$entity->setCreatedBy($userId);
		$entity->setCreatedAt(new DateTime());
		$this->mapper->insert($entity);

		$this->auditTrail->recordBulkShareGranted(
			userId: $userId,
			sourceSecretId: $sourceSecretId,
			secretName: $source->getName(),
			recipientId: $targetUserId,
		);

		return [
			'sourceSecretId' => $sourceSecretId,
			'targetUserId' => $targetUserId,
			'status' => 'created',
			'recipientSecretId' => $copy->getId(),
		];
	}//end createDirectShare()

	/**
	 * Normalise an optional blob value to a non-empty string or null.
	 *
	 * @param mixed $value The raw value
	 *
	 * @return string|null
	 */
	private function optionalString(mixed $value): ?string {
		if (is_string($value) === true && $value !== '') {
			return $value;
		}

		return null;
	}//end optionalString()
}//end class
