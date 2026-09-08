<?php

/**
 * Keepiq Recipient Secret Copy Factory
 *
 * Materialises a share recipient's own encrypted Secret row from blobs
 * the browser already encrypted under that recipient's public key, and
 * answers the certificate question the browser needs to produce them.
 *
 * Both operations are one and the same lookup — the recipient's ACTIVE
 * EncryptionSuite — which is why they live together and away from the
 * registrar that drives the batch. The server never sees plaintext
 * (ADR-003); it only stores the ciphertext it is handed.
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
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Ramsey\Uuid\Uuid;

/**
 * Builds recipient-side encrypted Secret copies.
 */
class RecipientSecretCopyFactory {
	/**
	 * Constructor for RecipientSecretCopyFactory.
	 *
	 * @param SecretMapper $secretMapper The Secret mapper (copy writes)
	 * @param EncryptionSuiteMapper $suiteMapper The EncryptionSuite mapper (recipient suite)
	 * @param SecretTypeService|null $typeService The type service (recipient-copy type resolution)
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; the copy behaviour carries the spec anchors.
	 */
	public function __construct(
		private SecretMapper $secretMapper,
		private EncryptionSuiteMapper $suiteMapper,
		private ?SecretTypeService $typeService = null,
	) {
	}//end __construct()

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
	public function certificateFor(string $targetUserId): ?string {
		try {
			return $this->suiteMapper
				->findActiveByOwner(ownerType: 'user', ownerId: $targetUserId)
				->getCertificate();
		} catch (DoesNotExistException) {
			return null;
		}
	}//end certificateFor()

	/**
	 * The active certificates of several prospective recipients, in one query.
	 *
	 * Batch form of certificateFor(). A user with no active suite is absent
	 * from the result rather than present with a null, so the caller cannot
	 * mistake "not shareable" for "shareable with nothing".
	 *
	 * @param string[] $targetUserIds The prospective recipients
	 *
	 * @return array<string,string> PEM certificate, keyed by user ID
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-recipient-shareability-lookup
	 */
	public function certificatesFor(array $targetUserIds): array {
		$certificates = [];
		foreach ($this->suiteMapper->findActiveByOwners(ownerType: 'user', ownerIds: $targetUserIds) as $userId => $suite) {
			$certificates[$userId] = $suite->getCertificate();
		}

		return $certificates;
	}//end certificatesFor()

	/**
	 * Create a recipient's Secret copy from client-encrypted blobs, or
	 * null when the recipient has no active suite (skip, not fail).
	 *
	 * @param Secret $source The owner's source secret
	 * @param string $targetUserId The recipient
	 * @param string $encryptedKey Recipient-encrypted key blob
	 * @param string|null $encryptedLogin Recipient-encrypted login blob
	 * @param string|null $encryptedExtras Recipient-encrypted additionalFields blob
	 *
	 * @return Secret|null
	 *
	 * @spec openspec/specs/bulk-actions/spec.md#requirement-the-four-bulk-operations
	 */
	public function create(
		Secret $source,
		string $targetUserId,
		string $encryptedKey,
		?string $encryptedLogin,
		?string $encryptedExtras,
	): ?Secret {
		try {
			$suite = $this->suiteMapper->findActiveByOwner(ownerType: 'user', ownerId: $targetUserId);
		} catch (DoesNotExistException) {
			return null;
		}

		$typeId = null;
		if ($this->typeService !== null) {
			try {
				$typeId = $this->typeService->resolveTypeForSecret($source->getTypeId(), $targetUserId);
			} catch (InvalidArgumentException) {
				$typeId = $this->typeService->resolveTypeForSecret(null, $targetUserId);
			}
		}

		$now = new DateTime();
		$copy = new Secret();
		$copy->setId(Uuid::uuid4()->toString());
		$copy->setName($source->getName());
		$copy->setUrl($source->getUrl());
		if ($typeId !== null) {
			$copy->setTypeId($typeId);
		}

		$copy->setFolderId(null);
		$copy->setKey($encryptedKey);
		$copy->setLogin($encryptedLogin);
		$copy->setAdditionalFields($encryptedExtras);
		$copy->setEncryptionSuiteId($suite->getId());
		$copy->setOwnerType('user');
		$copy->setOwnerId($targetUserId);
		$copy->setCreatedAt($now);
		$copy->setUpdatedAt($now);
		$copy->setKeyUpdatedAt($now);
		$this->secretMapper->insert($copy);

		return $copy;
	}//end create()
}//end class
