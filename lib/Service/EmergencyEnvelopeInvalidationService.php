<?php

/**
 * Keepiq Emergency Envelope Invalidation Service
 *
 * Envelope invalidation on key change (add-emergency-access): when a
 * grantor's or grantee's encryption suite is rotated or revoked, the
 * grantee-encrypted recovery envelopes that escrow the now-stale key must
 * stop being usable.
 *
 * On a grantor ROTATION (compromise recovery) the envelope is now MIGRATED
 * rather than invalidated wherever the grantee is still reachable: the browser
 * mints a fresh envelope escrowing the new private key and re-points the
 * contact through reEnvelopeForRotation(). invalidateForGrantorRotation() then
 * runs at completion as a residual SWEEP, catching only the contacts the loop
 * could not carry (grantee has no active suite). Revocation of the grantor's
 * suite still DELETES the envelopes outright, because it produces no new key to
 * migrate to.
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
use OCA\Keepiq\Db\EmergencyContact;
use OCA\Keepiq\Db\EmergencyContactMapper;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Key-change invalidation of break-glass recovery envelopes.
 */
class EmergencyEnvelopeInvalidationService {
	/**
	 * The recovery-envelope format this service knows how to shape-check.
	 *
	 * Mirrors ENVELOPE_VERSION / ENVELOPE_ALG in src/crypto/emergencyEnvelope.js.
	 * The grantor cannot open the envelope (only the grantee can), so the server
	 * asserts its shape rather than round-tripping it.
	 */
	private const ENVELOPE_VERSION = 1;

	private const ENVELOPE_ALG = 'RSA-OAEP+AES-256-GCM';

	/**
	 * Constructor for EmergencyEnvelopeInvalidationService.
	 *
	 * @param EmergencyContactMapper $mapper The emergency-contact mapper
	 * @param EncryptionSuiteMapper $suiteMapper The encryption-suite mapper (grantee active-suite lookup)
	 * @param EmergencyAccessAuditTrail $auditTrail The emergency-access audit trail
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only — no domain logic.
	 */
	public function __construct(
		private EmergencyContactMapper $mapper,
		private EncryptionSuiteMapper $suiteMapper,
		private EmergencyAccessAuditTrail $auditTrail,
	) {
	}//end __construct()

	/**
	 * Invalidate a grantor's recovery envelopes after their suite is ROTATED
	 * (compromise recovery). The envelopes hold the stale private key, so they
	 * are marked invalid and the grantor must re-establish emergency access.
	 *
	 * @param string $grantorSuiteId The rotated (old) suite ID
	 * @param string $reason The invalidation reason tag
	 *
	 * @return int The number of relationships invalidated
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-envelope-invalidation-on-key-change
	 */
	public function invalidateForGrantorRotation(string $grantorSuiteId, string $reason = 'grantor_rotation'): int {
		$count = 0;
		foreach ($this->mapper->findByGrantorSuite(grantorSuiteId: $grantorSuiteId) as $contact) {
			if ($contact->getState() === EmergencyContact::STATE_INVALIDATED) {
				continue;
			}

			$this->invalidate(contact: $contact, reason: $reason);
			$count++;
		}

		return $count;
	}//end invalidateForGrantorRotation()

	/**
	 * Count the grantor's usable (non-invalidated) emergency contacts on a suite.
	 *
	 * A revocation about to DELETE these envelopes uses this to refuse silently
	 * destroying a still-working break-glass path: the count (never the
	 * identities, which stay grantor-private) is surfaced so the administrator
	 * can decide with the loss in view.
	 *
	 * @param string $grantorSuiteId The grantor suite about to be revoked
	 *
	 * @return int The number of usable emergency contacts bound to that suite
	 *
	 * @spec openspec/changes/migrate-emergency-access-on-rotation/specs/emergency-access/spec.md#requirement-envelope-invalidation-on-key-change
	 */
	public function countUsableForGrantorSuite(string $grantorSuiteId): int {
		$count = 0;
		foreach ($this->mapper->findByGrantorSuite(grantorSuiteId: $grantorSuiteId) as $contact) {
			if ($contact->getState() !== EmergencyContact::STATE_INVALIDATED) {
				$count++;
			}
		}

		return $count;
	}//end countUsableForGrantorSuite()

	/**
	 * Clear a grantor's recovery envelopes after their suite is REVOKED — the
	 * envelopes hold a now-void key and are deleted outright.
	 *
	 * @param string $grantorSuiteId The revoked suite ID
	 *
	 * @return int The number of relationships cleared
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-envelope-invalidation-on-key-change
	 */
	public function clearForGrantorRevocation(string $grantorSuiteId): int {
		$count = 0;
		foreach ($this->mapper->findByGrantorSuite(grantorSuiteId: $grantorSuiteId) as $contact) {
			$this->auditTrail->recordInvalidated(
				grantorUserId: $contact->getGrantorUserId(),
				granteeUserId: $contact->getGranteeUserId(),
				id: $contact->getId(),
				reason: 'grantor_revocation',
			);
			$this->mapper->delete($contact);
			$count++;
		}

		return $count;
	}//end clearForGrantorRevocation()

	/**
	 * Invalidate envelopes encrypted to a grantee whose suite was revoked — the
	 * grantee can no longer open them, so they are marked invalid.
	 *
	 * @param string $granteeSuiteId The revoked grantee suite ID
	 *
	 * @return int The number of relationships invalidated
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-envelope-invalidation-on-key-change
	 */
	public function invalidateForGranteeRevocation(string $granteeSuiteId): int {
		$count = 0;
		foreach ($this->mapper->findByGranteeSuite(granteeSuiteId: $granteeSuiteId) as $contact) {
			if ($contact->getState() === EmergencyContact::STATE_INVALIDATED) {
				continue;
			}

			$this->invalidate(contact: $contact, reason: 'grantee_revocation');
			$count++;
		}

		return $count;
	}//end invalidateForGranteeRevocation()

	/**
	 * Migrate one recovery envelope onto the grantor's new suite during a
	 * compromise-recovery rotation.
	 *
	 * The browser has already minted a fresh envelope escrowing the grantor's
	 * NEW private key, sealed to the grantee's current certificate. This re-points
	 * the contact to the new suite and stores that envelope, keeping the contact
	 * `granted`. Unlike the other migrated stores the grantor cannot decrypt what
	 * it just wrote (only the grantee can), so the envelope is shape-checked, not
	 * round-tripped, and the declared grantee suite is asserted to be the
	 * grantee's CURRENT active suite — an envelope sealed to a stale grantee key
	 * would be unopenable.
	 *
	 * Ownership and old-suite binding are enforced exactly as the other migration
	 * writes: the caller (MigrationController) has already established that the
	 * migration belongs to $ownerId, and this insists the contact does too and is
	 * still on $oldSuiteId before touching it.
	 *
	 * @param string $ownerId The migration owner (the contact's grantor)
	 * @param string $oldSuiteId The migration's old suite (the contact must be on it)
	 * @param string $newSuiteId The migration's new suite (the contact is re-pointed to it)
	 * @param string $contactId The emergency-contact ID to re-point
	 * @param string $recoveryEnvelope The fresh envelope escrowing the new private key
	 * @param string $sealedSuiteId The grantee suite the client sealed to
	 *
	 * @return EmergencyContact The re-pointed contact
	 *
	 * @throws NotFoundException When the contact does not exist
	 * @throws ForbiddenException When the contact is not this migration's to touch
	 * @throws InvalidArgumentException When the envelope is malformed or misaddressed
	 *
	 * @spec openspec/changes/migrate-emergency-access-on-rotation/specs/emergency-access/spec.md#requirement-envelope-invalidation-on-key-change
	 */
	public function reEnvelopeForRotation(
		string $ownerId,
		string $oldSuiteId,
		string $newSuiteId,
		string $contactId,
		string $recoveryEnvelope,
		string $sealedSuiteId,
	): EmergencyContact {
		try {
			$contact = $this->mapper->findById(id: $contactId);
		} catch (DoesNotExistException) {
			throw new NotFoundException(message: 'Emergency contact not found');
		}

		if ($contact->getGrantorUserId() !== $ownerId) {
			throw new ForbiddenException(message: 'Emergency contact does not belong to you');
		}

		if ($contact->getGrantorSuiteId() !== $oldSuiteId) {
			throw new ForbiddenException(message: 'Emergency contact is not bound to this migration\'s old suite');
		}

		$this->assertWellFormedEnvelope(envelope: $recoveryEnvelope);

		// The envelope is only openable by the grantee, so the strongest check the
		// grantor's server can make is that it was sealed to the grantee's CURRENT
		// suite. A grantee who rotated since designation has a new active suite;
		// sealing to the old one would produce an envelope they could never open.
		try {
			$granteeSuite = $this->suiteMapper->findActiveByOwner(ownerType: 'user', ownerId: $contact->getGranteeUserId());
		} catch (DoesNotExistException) {
			throw new InvalidArgumentException('The grantee has no active encryption suite to seal to');
		}

		if ($sealedSuiteId !== $granteeSuite->getId()) {
			throw new InvalidArgumentException('The declared grantee suite does not match the grantee\'s active suite');
		}

		$contact->setRecoveryEnvelope($recoveryEnvelope);
		$contact->setGrantorSuiteId($newSuiteId);
		$contact->setGranteeSuiteId($sealedSuiteId);
		$contact->setState(EmergencyContact::STATE_GRANTED);
		$contact->setInvalidatedReason(null);
		$contact->setUpdatedAt(new DateTime());
		$updated = $this->mapper->update($contact);

		// A rotation re-establishes the contact under the new key; audited as a
		// (re-)grant, exactly as recordGranted's contract covers.
		$this->auditTrail->recordGranted(
			grantorUserId: $updated->getGrantorUserId(),
			granteeUserId: $updated->getGranteeUserId(),
			id: $updated->getId(),
			accessLevel: (string)$updated->getAccessLevel(),
			waitPeriodDays: (int)$updated->getWaitPeriodDays(),
		);

		return $updated;
	}//end reEnvelopeForRotation()

	/**
	 * Shape-check a recovery envelope without opening it.
	 *
	 * Only the grantee can decrypt the envelope, so the server cannot verify its
	 * plaintext. It can insist the envelope parses, carries the expected version
	 * and algorithm, and has the three non-empty ciphertext fields the builder
	 * emits — enough to reject a malformed or truncated submission.
	 *
	 * @param string $envelope The recovery-envelope JSON
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the envelope is not well-formed
	 *
	 * @spec openspec/changes/migrate-emergency-access-on-rotation/specs/emergency-access/spec.md#requirement-envelope-invalidation-on-key-change
	 */
	private function assertWellFormedEnvelope(string $envelope): void {
		$decoded = json_decode($envelope, true);
		if (is_array($decoded) === false) {
			throw new InvalidArgumentException('Recovery envelope is not valid JSON');
		}

		if (($decoded['v'] ?? null) !== self::ENVELOPE_VERSION) {
			throw new InvalidArgumentException('Recovery envelope has an unexpected version');
		}

		if (($decoded['alg'] ?? null) !== self::ENVELOPE_ALG) {
			throw new InvalidArgumentException('Recovery envelope has an unexpected algorithm');
		}

		foreach (['encKey', 'iv', 'ct'] as $field) {
			if (isset($decoded[$field]) === false || is_string($decoded[$field]) === false || $decoded[$field] === '') {
				throw new InvalidArgumentException('Recovery envelope is missing its ' . $field . ' field');
			}
		}
	}//end assertWellFormedEnvelope()

	/**
	 * Mark a relationship invalidated: null the envelope, set the reason, and
	 * audit. The grantee can no longer break glass until the grantor re-establishes.
	 *
	 * @param EmergencyContact $contact The relationship
	 * @param string $reason The invalidation reason tag
	 *
	 * @return void
	 */
	private function invalidate(EmergencyContact $contact, string $reason): void {
		$contact->setState(EmergencyContact::STATE_INVALIDATED);
		$contact->setRecoveryEnvelope(null);
		$contact->setRequestedAt(null);
		$contact->setInvalidatedReason($reason);
		$contact->setUpdatedAt(new DateTime());
		$this->mapper->update($contact);

		$this->auditTrail->recordInvalidated(
			grantorUserId: $contact->getGrantorUserId(),
			granteeUserId: $contact->getGranteeUserId(),
			id: $contact->getId(),
			reason: $reason,
		);
	}//end invalidate()
}//end class
