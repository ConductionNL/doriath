<?php

/**
 * Keepiq Attachment Service
 *
 * Business logic for encrypted attachments (encrypted-attachments §2):
 * ciphertext blobs in IAppData, metadata + per-copy RSA-wrapped file keys
 * in Keepiq's own tables. The server brokers where bytes live and who
 * holds a wrapped key but never sees plaintext bytes, the plaintext
 * filename, or the file key (ADR-003). One physical blob per file; a
 * blob is unlinked only when its LAST grant is deleted.
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
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Db\Attachment;
use OCA\Keepiq\Db\AttachmentGrant;
use OCA\Keepiq\Db\AttachmentGrantMapper;
use OCA\Keepiq\Db\AttachmentMapper;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\IAppConfig;
use Ramsey\Uuid\Uuid;

/**
 * Business logic for the encrypted-attachment lifecycle.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The service threads the
 *   attachment/grant/secret/suite mappers plus app-data and config so the
 *   blob + grant invariants live in one place.
 */
class AttachmentService {
	/**
	 * The IAppData folder holding attachment ciphertext blobs.
	 */
	private const BLOB_FOLDER = 'attachments';

	/**
	 * The IAppData namespace the blob folder lives under.
	 *
	 * Follows the app id. Blobs written under the pre-rename namespace are
	 * relocated by OCA\Keepiq\Repair\MoveAttachmentBlobs before this is
	 * read — see blobFolder().
	 */
	private const BLOB_APP_DATA_NAMESPACE = Application::APP_ID;

	/**
	 * The pre-rename AppData namespace, still consulted on READ.
	 *
	 * MoveAttachmentBlobs relocates blobs out of here, but it is deliberately
	 * incapable of aborting an upgrade: a pre-migration step that throws leaves
	 * the app half-migrated. That safety has a cost — a blob it declines to
	 * move (a collision, a short copy, a storage error) keeps its bytes here
	 * while reads look only in the new namespace, so preserving the source
	 * would protect the data and still make the attachment unavailable.
	 *
	 * So reads fall back here. Availability then does not depend on the
	 * migration having completed, which is what makes the migration safe to
	 * make non-fatal. Writes never come here; this is a read-only tail that
	 * goes away with the rest of the pre-stable compatibility, per
	 * Application::PRE_STABLE_COMPAT_REMOVED_IN.
	 *
	 * @var string
	 */
	private const LEGACY_BLOB_APP_DATA_NAMESPACE = 'doriath';

	/**
	 * Constructor for AttachmentService.
	 *
	 * @param AttachmentMapper $mapper The attachment mapper
	 * @param AttachmentGrantMapper $grantMapper The grant mapper
	 * @param SecretMapper $secretMapper The secret mapper (authorization)
	 * @param EncryptionSuiteMapper $suiteMapper The suite mapper (grant provenance)
	 * @param IAppDataFactory $appDataFactory The app-data factory (blob storage)
	 * @param IAppConfig $appConfig The app config (limits)
	 * @param WriteLockService $writeLockService The compromise-recovery write lock
	 * @param IEventDispatcher|null $eventDispatcher The audit event dispatcher
	 * @param AuditEventFactory $auditEvents The audit-event factory
	 *
	 * @return void
	 */
	public function __construct(
		private AttachmentMapper $mapper,
		private AttachmentGrantMapper $grantMapper,
		private SecretMapper $secretMapper,
		private EncryptionSuiteMapper $suiteMapper,
		private IAppDataFactory $appDataFactory,
		private IAppConfig $appConfig,
		private WriteLockService $writeLockService,
		private ?IEventDispatcher $eventDispatcher = null,
		private AuditEventFactory $auditEvents = new AuditEventFactory(),
	) {
	}//end __construct()

	/**
	 * Dispatch an audit event when a dispatcher is wired.
	 *
	 * @param AuditEvent $event The audit event
	 *
	 * @return void
	 */
	private function dispatchAudit(AuditEvent $event): void {
		$this->eventDispatcher?->dispatchTyped($event);
	}//end dispatchAudit()

	/**
	 * The app-data namespace holding attachment ciphertext blobs.
	 *
	 * DELIBERATELY THE OLD APP ID, `doriath`, AFTER THE doriath -> keepiq
	 * RENAME. `IAppDataFactory::get($appId)` resolves to the on-disk folder
	 * `appdata_<instanceid>/<appId>/`, so this string is a STORAGE LOCATION,
	 * not a label. Every attachment lives at
	 * `appdata_<instanceid>/<namespace>/attachments/<blob_ref>`, addressed by
	 * the `blob_ref` column in `keepiq_attachments` — and the bytes are
	 * AES-GCM ciphertext whose file key is RSA-wrapped per recipient, so they
	 * cannot be re-created from anything the server holds.
	 *
	 * It follows the app id only because the blobs were MOVED to match. Doing
	 * one without the other points the app at an empty folder: uploads keep
	 * working, every existing attachment 404s on download, and nothing logs an
	 * error. OCA\Keepiq\Repair\MoveAttachmentBlobs performs the relocation as
	 * a pre-migration step, verifying each copy before removing its source.
	 *
	 * @return \OCP\Files\SimpleFS\ISimpleFolder
	 */
	private function blobFolder(): \OCP\Files\SimpleFS\ISimpleFolder {
		$appData = $this->appDataFactory->get(self::BLOB_APP_DATA_NAMESPACE);
		try {
			return $appData->getFolder(self::BLOB_FOLDER);
		} catch (NotFoundException) {
			return $appData->newFolder(self::BLOB_FOLDER);
		}
	}//end blobFolder()

	/**
	 * Locate one blob for reading or deleting, new namespace first.
	 *
	 * Falls back to the pre-rename namespace so an attachment MoveAttachmentBlobs
	 * declined to relocate stays readable. Without this, a skipped blob is
	 * bytes-intact and unreachable, which is indistinguishable from lost.
	 *
	 * @param string $blobRef The blob file name.
	 *
	 * @return \OCP\Files\SimpleFS\ISimpleFile The blob.
	 *
	 * @throws NotFoundException When neither namespace holds it.
	 */
	private function blobFileForRead(string $blobRef): \OCP\Files\SimpleFS\ISimpleFile {
		try {
			return $this->blobFolder()->getFile($blobRef);
		} catch (NotFoundException) {
			// Not in the current namespace — it may not have been relocated.
		}

		return $this->appDataFactory
			->get(self::LEGACY_BLOB_APP_DATA_NAMESPACE)
			->getFolder(self::BLOB_FOLDER)
			->getFile($blobRef);
	}//end blobFileForRead()

	/**
	 * Upload a client-encrypted attachment against an owned secret.
	 *
	 * The blob is AES-GCM ciphertext produced in the browser; the caller
	 * supplies the encrypted `{filename, contentType}` metadata and the
	 * owner's RSA-wrapped file key. Size limit and per-user quota are
	 * enforced server-side in ciphertext bytes BEFORE persisting.
	 *
	 * @param string $secretId The owning secret UUID
	 * @param string $userId The caller (must own the secret)
	 * @param string $blob The ciphertext bytes
	 * @param string $encryptedMetadata The AES-GCM metadata ciphertext
	 * @param string $wrappedFileKey The owner's RSA-wrapped file key
	 *
	 * @return array{attachment: Attachment, grant: AttachmentGrant}
	 *
	 * @throws InvalidArgumentException On validation/authorization failure
	 *
	 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-client-side-encrypted-attachment-upload
	 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-per-attachment-size-limit-and-per-user-quota
	 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-attachment-operations-are-auditable
	 */
	public function upload(
		string $secretId,
		string $userId,
		string $blob,
		string $encryptedMetadata,
		string $wrappedFileKey,
	): array {
		// A new attachment would be wrapped to whichever suite is current, while
		// the migration is walking the other one — so it would either be missed
		// or counted as outstanding work that the client never fetched. Reads
		// stay open; only the write is refused.
		$this->writeLockService->assertNotWriteLocked(ownerId: $userId);

		$secret = $this->loadOwnedSecret(secretId: $secretId, userId: $userId);

		if ($blob === '' || $encryptedMetadata === '' || $wrappedFileKey === '') {
			throw new InvalidArgumentException('blob, encryptedMetadata, and wrappedFileKey are required');
		}

		$size = strlen($blob);
		$maxBytes = $this->appConfig->getValueInt(Application::APP_ID, 'attachment_max_bytes', 26214400);
		if ($size > $maxBytes) {
			throw new InvalidArgumentException(
				'Attachment exceeds the per-attachment limit of ' . $maxBytes . ' bytes'
			);
		}

		$quota = $this->appConfig->getValueInt(Application::APP_ID, 'attachment_user_quota_bytes', 104857600);
		$used = $this->mapper->sumBytesForOwner(ownerId: $userId);
		if (($used + $size) > $quota) {
			throw new InvalidArgumentException(
				'Attachment quota exceeded (' . $used . ' of ' . $quota . ' bytes in use)'
			);
		}

		$suiteId = null;
		try {
			$suiteId = $this->suiteMapper->findActiveByOwner(ownerType: 'user', ownerId: $userId)->getId();
		} catch (DoesNotExistException) {
			// Provenance only — a missing suite does not block the upload.
		}

		$blobRef = Uuid::uuid4()->toString() . '.bin';
		$this->blobFolder()->newFile($blobRef)->putContent($blob);

		$attachment = new Attachment();
		$attachment->setId(Uuid::uuid4()->toString());
		$attachment->setSourceSecretId($secretId);
		$attachment->setBlobRef($blobRef);
		$attachment->setEncryptedMetadata($encryptedMetadata);
		$attachment->setSizeBytes($size);
		$attachment->setCreatedAt(new DateTime());
		$attachment->setUpdatedAt(new DateTime());
		$attachment = $this->mapper->insert($attachment);

		$grant = $this->insertGrant(
			attachmentId: $attachment->getId(),
			secretId: $secretId,
			recipientType: 'user',
			recipientId: $userId,
			wrappedFileKey: $wrappedFileKey,
			suiteId: $suiteId,
		);

		$this->dispatchAudit(
			event: $this->auditEvents->forUser(
				actorId: $userId,
				eventType: AuditEventTypes::ATTACHMENT_UPLOADED,
				objectType: 'attachment',
				objectId: $attachment->getId(),
				objectName: $secret->getName(),
				metadata: [
					'secretId' => $secretId,
					'sizeBytes' => $size,
				],
			)
		);

		return [
			'attachment' => $attachment,
			'grant' => $grant,
		];
	}//end upload()

	/**
	 * List a secret's attachments with the CALLER'S own wrapped file key
	 * per attachment. Authorized for the owner and for any recipient
	 * holding a grant; others receive an empty list.
	 *
	 * @param string $secretId The secret UUID (owner's or a copy's source)
	 * @param string $userId The caller
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-single-blob-envelope-with-per-recipient-key-wrapping
	 */
	public function listForSecret(string $secretId, string $userId): array {
		$out = [];
		foreach ($this->mapper->findBySourceSecret(sourceSecretId: $secretId) as $attachment) {
			try {
				$grant = $this->grantMapper->findForRecipient(
					attachmentId: $attachment->getId(),
					recipientId: $userId
				);
			} catch (DoesNotExistException) {
				continue;
			}

			$row = $attachment->jsonSerialize();
			$row['wrappedFileKey'] = $grant->getWrappedFileKey();
			$out[] = $row;
		}

		return $out;
	}//end listForSecret()

	/**
	 * Stream an attachment's ciphertext blob — only to a caller holding a
	 * grant for it.
	 *
	 * @param string $attachmentId The attachment UUID
	 * @param string $userId The caller
	 *
	 * @return string The ciphertext bytes
	 *
	 * @throws InvalidArgumentException When not found / no grant held
	 *
	 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-single-blob-envelope-with-per-recipient-key-wrapping
	 */
	public function downloadBlob(string $attachmentId, string $userId): string {
		$attachment = $this->loadAttachment(attachmentId: $attachmentId);

		try {
			$this->grantMapper->findForRecipient(attachmentId: $attachmentId, recipientId: $userId);
		} catch (DoesNotExistException) {
			throw new InvalidArgumentException('No grant held for this attachment');
		}

		try {
			$bytes = $this->blobFileForRead(blobRef: $attachment->getBlobRef())->getContent();
		} catch (NotFoundException) {
			throw new InvalidArgumentException('Attachment blob is missing');
		}

		$this->dispatchAudit(
			event: $this->auditEvents->forUser(
				actorId: $userId,
				eventType: AuditEventTypes::ATTACHMENT_DOWNLOADED,
				objectType: 'attachment',
				objectId: $attachmentId,
				objectName: '',
				metadata: [
					'secretId' => $attachment->getSourceSecretId(),
					'sizeBytes' => $attachment->getSizeBytes(),
				],
			)
		);

		return $bytes;
	}//end downloadBlob()

	/**
	 * Add a grant for a recipient copy (share/sync re-wrap). The blob is
	 * never re-uploaded; only the tiny wrapped key is per-recipient.
	 * Idempotent per (attachment, copy).
	 *
	 * @param string $attachmentId The attachment UUID
	 * @param string $userId The caller (must own the source secret)
	 * @param string $copySecretId The recipient's Secret copy UUID
	 * @param string $recipientId The recipient user/application ID
	 * @param string $wrappedFileKey The file key wrapped under the recipient's cert
	 * @param string $recipientType The recipient type (`user`|`application`)
	 *
	 * @return AttachmentGrant
	 *
	 * @throws InvalidArgumentException On validation/authorization failure
	 *
	 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-single-blob-envelope-with-per-recipient-key-wrapping
	 */
	public function addGrant(
		string $attachmentId,
		string $userId,
		string $copySecretId,
		string $recipientId,
		string $wrappedFileKey,
		string $recipientType = 'user',
	): AttachmentGrant {
		$this->writeLockService->assertNotWriteLocked(ownerId: $userId);

		$attachment = $this->loadAttachment(attachmentId: $attachmentId);
		$this->loadOwnedSecret(secretId: $attachment->getSourceSecretId(), userId: $userId);

		if ($copySecretId === '' || $recipientId === '' || $wrappedFileKey === '') {
			throw new InvalidArgumentException('copySecretId, recipientId, and wrappedFileKey are required');
		}

		if (in_array($recipientType, ['user', 'application'], true) === false) {
			throw new InvalidArgumentException('recipientType must be user or application');
		}

		// Idempotent per copy: an existing grant for this copy is returned.
		foreach ($this->grantMapper->findBySecret(secretId: $copySecretId) as $existing) {
			if ($existing->getAttachmentId() === $attachmentId) {
				return $existing;
			}
		}

		$suiteId = null;
		try {
			$suiteId = $this->suiteMapper->findActiveByOwner(ownerType: $recipientType, ownerId: $recipientId)->getId();
		} catch (DoesNotExistException) {
			// Provenance only.
		}

		return $this->insertGrant(
			attachmentId: $attachmentId,
			secretId: $copySecretId,
			recipientType: $recipientType,
			recipientId: $recipientId,
			wrappedFileKey: $wrappedFileKey,
			suiteId: $suiteId,
		);
	}//end addGrant()

	/**
	 * Delete an attachment entirely — owner-only. Removes all grants and
	 * the blob, reclaiming quota. Idempotent.
	 *
	 * @param string $attachmentId The attachment UUID
	 * @param string $userId The caller (must own the source secret)
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException On not found / not authorized
	 *
	 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-attachment-deletion-cascade
	 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-attachment-operations-are-auditable
	 */
	public function delete(string $attachmentId, string $userId): void {
		// Deliberately NOT write-locked. The lock exists to stop new ciphertext
		// appearing under a suite the migration has already walked past;
		// deleting only shrinks the work list, which is re-derived from the rows
		// on every pass, so it cannot strand a run. And a user who has just
		// declared their key compromised is precisely the user who may need to
		// revoke something immediately.
		$attachment = $this->loadAttachment(attachmentId: $attachmentId);
		$secret = $this->loadOwnedSecret(secretId: $attachment->getSourceSecretId(), userId: $userId);

		foreach ($this->grantMapper->findByAttachment(attachmentId: $attachmentId) as $grant) {
			$this->grantMapper->delete($grant);
		}

		$this->unlinkBlobIfOrphaned(attachment: $attachment);
		$this->mapper->delete($attachment);

		$this->dispatchAudit(
			event: $this->auditEvents->forUser(
				actorId: $userId,
				eventType: AuditEventTypes::ATTACHMENT_DELETED,
				objectType: 'attachment',
				objectId: $attachmentId,
				objectName: $secret->getName(),
				metadata: [
					'secretId' => $attachment->getSourceSecretId(),
					'sizeBytes' => $attachment->getSizeBytes(),
				],
			)
		);
	}//end delete()

	/**
	 * Cascade: delete every attachment of a source secret (secret delete,
	 * folder cascade, account deletion). Idempotent; no authorization —
	 * the caller (SecretService/AccountDeletionService) already owns the
	 * decision.
	 *
	 * @param string $sourceSecretId The source secret UUID
	 *
	 * @return int Attachments removed
	 *
	 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-attachment-deletion-cascade
	 */
	public function deleteForSecret(string $sourceSecretId): int {
		$removed = 0;
		foreach ($this->mapper->findBySourceSecret(sourceSecretId: $sourceSecretId) as $attachment) {
			foreach ($this->grantMapper->findByAttachment(attachmentId: $attachment->getId()) as $grant) {
				$this->grantMapper->delete($grant);
			}

			$this->unlinkBlobIfOrphaned(attachment: $attachment);
			$this->mapper->delete($attachment);
			++$removed;
		}

		return $removed;
	}//end deleteForSecret()

	/**
	 * Revocation cascade: delete the grants attached to one Secret COPY
	 * (share revoke / recipient suite revocation). The owner's blob and
	 * remaining grants are untouched.
	 *
	 * @param string $copySecretId The Secret copy UUID
	 *
	 * @return int Grants removed
	 *
	 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-attachment-deletion-cascade
	 */
	public function deleteGrantsForSecretCopy(string $copySecretId): int {
		$removed = 0;
		foreach ($this->grantMapper->findBySecret(secretId: $copySecretId) as $grant) {
			$this->grantMapper->delete($grant);
			++$removed;

			// No blob GC on this path by design: revoking a COPY's grant
			// must never touch the owner's blob. The previous guarded call
			// here passed keepWhileRowExists: true, which made the callee
			// return before any unlink could happen — i.e. it was already
			// unconditionally a no-op. It is spelled out rather than
			// performed so the intent is readable.
		}

		return $removed;
	}//end deleteGrantsForSecretCopy()

	/**
	 * Insert a grant row.
	 *
	 * @param string $attachmentId The attachment UUID
	 * @param string $secretId The copy UUID
	 * @param string $recipientType The recipient type
	 * @param string $recipientId The recipient id
	 * @param string $wrappedFileKey The wrapped file key
	 * @param string|null $suiteId The wrapping suite id
	 *
	 * @return AttachmentGrant
	 */
	private function insertGrant(
		string $attachmentId,
		string $secretId,
		string $recipientType,
		string $recipientId,
		string $wrappedFileKey,
		?string $suiteId,
	): AttachmentGrant {
		$grant = new AttachmentGrant();
		$grant->setId(Uuid::uuid4()->toString());
		$grant->setAttachmentId($attachmentId);
		$grant->setSecretId($secretId);
		$grant->setRecipientType($recipientType);
		$grant->setRecipientId($recipientId);
		$grant->setWrappedFileKey($wrappedFileKey);
		$grant->setEncryptionSuiteId($suiteId);
		$grant->setCreatedAt(new DateTime());

		return $this->grantMapper->insert($grant);
	}//end insertGrant()

	/**
	 * Unlink the blob when no grant references the attachment any more.
	 * Never deletes a blob while a grant remains; missing blobs are
	 * tolerated (idempotent cascade).
	 *
	 * @param Attachment $attachment The attachment row
	 *
	 * @return void
	 */
	private function unlinkBlobIfOrphaned(Attachment $attachment): void {
		if ($this->grantMapper->countByAttachment(attachmentId: $attachment->getId()) > 0) {
			return;
		}

		try {
			$this->blobFileForRead(blobRef: $attachment->getBlobRef())->delete();
		} catch (NotFoundException) {
			// Already gone — idempotent.
		}
	}//end unlinkBlobIfOrphaned()

	/**
	 * Load an attachment by id.
	 *
	 * @param string $attachmentId The attachment UUID
	 *
	 * @return Attachment
	 *
	 * @throws InvalidArgumentException When missing
	 */
	private function loadAttachment(string $attachmentId): Attachment {
		try {
			return $this->mapper->findById(id: $attachmentId);
		} catch (DoesNotExistException) {
			throw new InvalidArgumentException('Attachment not found');
		}
	}//end loadAttachment()

	/**
	 * Load a Secret and assert user ownership.
	 *
	 * @param string $secretId The secret UUID
	 * @param string $userId The candidate owner
	 *
	 * @return Secret
	 *
	 * @throws InvalidArgumentException On missing secret / foreign owner
	 */
	private function loadOwnedSecret(string $secretId, string $userId): Secret {
		try {
			$secret = $this->secretMapper->findById($secretId);
		} catch (DoesNotExistException) {
			throw new InvalidArgumentException('Secret not found');
		}

		if ($secret->getOwnerType() !== 'user' || $secret->getOwnerId() !== $userId) {
			throw new InvalidArgumentException('Not authorized for this secret');
		}

		return $secret;
	}//end loadOwnedSecret()
}//end class
