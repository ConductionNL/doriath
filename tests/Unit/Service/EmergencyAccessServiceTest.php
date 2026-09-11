<?php

/**
 * Unit tests for EmergencyAccessService — the break-glass state machine, the
 * approved+grantee release gate (no oracle), key-change invalidation, audit
 * dispatch, and grantor notifications. The recovery envelope is opaque
 * ciphertext throughout; no test asserts a server-side decryption because the
 * server never holds a usable key (zero-knowledge, ADR-003).
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Db\EmergencyContact;
use OCA\Keepiq\Db\EmergencyContactMapper;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Service\EmergencyAccessAuditTrail;
use OCA\Keepiq\Service\EmergencyAccessService;
use OCA\Keepiq\Service\EmergencyEnvelopeInvalidationService;
use OCA\Keepiq\Service\NotificationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for the emergency-access lifecycle service.
 */
class EmergencyAccessServiceTest extends TestCase {

	/**
	 * @var EmergencyContactMapper
	 */
	private $mapper;

	/**
	 * @var EncryptionSuiteMapper
	 */
	private $suiteMapper;

	/**
	 * @var NotificationService
	 */
	private $notificationService;

	/**
	 * @var IEventDispatcher
	 */
	private $dispatcher;

	/**
	 * @var array<int,AuditEvent>
	 */
	private array $dispatched = [];

	/**
	 * @var EmergencyAccessService
	 */
	private EmergencyAccessService $service;

	/**
	 * @var EmergencyEnvelopeInvalidationService
	 */
	private EmergencyEnvelopeInvalidationService $invalidationService;

	/**
	 * Set up mocks and capture dispatched audit events.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(EmergencyContactMapper::class);
		$this->suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
		$this->notificationService = $this->createMock(NotificationService::class);
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->dispatched = [];
		$this->dispatcher->method('dispatchTyped')->willReturnCallback(
			function ($event): void {
				if ($event instanceof AuditEvent) {
					$this->dispatched[] = $event;
				}
			}
		);

		$auditTrail = new EmergencyAccessAuditTrail(eventDispatcher: $this->dispatcher);

		$this->service = new EmergencyAccessService(
			mapper: $this->mapper,
			suiteMapper: $this->suiteMapper,
			notificationService: $this->notificationService,
			auditTrail: $auditTrail,
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->invalidationService = new EmergencyEnvelopeInvalidationService(
			mapper: $this->mapper,
			suiteMapper: $this->suiteMapper,
			auditTrail: $auditTrail,
		);
	}//end setUp()

	/**
	 * Build a mock active suite with an id and certificate.
	 *
	 * @param string $id The suite id
	 * @param string $cert The certificate PEM
	 *
	 * @return EncryptionSuite
	 */
	private function suite(string $id, string $cert = 'CERT-PEM'): EncryptionSuite {
		// A real entity — getCertificate() is a magic @method that cannot be mocked.
		$suite = new EncryptionSuite();
		$suite->setId($id);
		$suite->setCertificate($cert);
		return $suite;
	}//end suite()

	/**
	 * Build a contact entity in a given state.
	 *
	 * @param string $state The lifecycle state
	 *
	 * @return EmergencyContact
	 */
	private function contact(string $state): EmergencyContact {
		$c = new EmergencyContact();
		$c->setId('rel-1');
		$c->setGrantorUserId('alice');
		$c->setGranteeUserId('bob');
		$c->setAccessLevel(EmergencyContact::ACCESS_VIEW);
		$c->setWaitPeriodDays(7);
		$c->setState($state);
		$c->setRecoveryEnvelope('ENVELOPE-CIPHERTEXT');
		$c->setGrantorSuiteId('grantor-suite');
		$c->setGranteeSuiteId('grantee-suite');
		return $c;
	}//end contact()

	/**
	 * Count captured audit events of a given type.
	 *
	 * @param string $eventType The event type
	 *
	 * @return int
	 */
	private function auditCount(string $eventType): int {
		return count(array_filter($this->dispatched, static fn (AuditEvent $e): bool => $e->getEventType() === $eventType));
	}//end auditCount()

	/**
	 * Designating a grantee with an active suite creates a granted relationship
	 * and dispatches the granted audit event.
	 *
	 * @return void
	 */
	public function testDesignateCreatesGrantedRelationship(): void {
		$this->suiteMapper->method('findActiveByOwner')->willReturnCallback(
			fn (string $ownerType, string $ownerId): EncryptionSuite => $this->suite($ownerId . '-suite')
		);
		$this->mapper->method('findByGrantorAndGrantee')->willThrowException(new DoesNotExistException('none'));
		$this->mapper->method('findById')->willThrowException(new DoesNotExistException('none'));
		$this->mapper->expects($this->once())->method('insert')->willReturnArgument(0);

		$contact = $this->service->designate(
			grantorUserId: 'alice',
			granteeUserId: 'bob',
			waitPeriodDays: 7,
			accessLevel: 'view',
			recoveryEnvelope: 'ENVELOPE-CIPHERTEXT',
		);

		$this->assertSame(EmergencyContact::STATE_GRANTED, $contact->getState());
		$this->assertSame('ENVELOPE-CIPHERTEXT', $contact->getRecoveryEnvelope());
		$this->assertSame(1, $this->auditCount(AuditEventTypes::EMERGENCY_ACCESS_GRANTED));
	}//end testDesignateCreatesGrantedRelationship()

	/**
	 * A non-view access level is rejected (v1 is view only).
	 *
	 * @return void
	 */
	public function testDesignateRejectsNonViewLevel(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->designate('alice', 'bob', 7, 'takeover', 'ENV');
	}//end testDesignateRejectsNonViewLevel()

	/**
	 * An unsupported wait period is rejected.
	 *
	 * @return void
	 */
	public function testDesignateRejectsBadWaitPeriod(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->designate('alice', 'bob', 5, 'view', 'ENV');
	}//end testDesignateRejectsBadWaitPeriod()

	/**
	 * Designating a grantee with no active suite fails loudly.
	 *
	 * @return void
	 */
	public function testDesignateRejectsGranteeWithoutSuite(): void {
		$this->suiteMapper->method('findActiveByOwner')->willThrowException(new DoesNotExistException('none'));
		$this->expectException(InvalidArgumentException::class);
		$this->service->designate('alice', 'bob', 7, 'view', 'ENV');
	}//end testDesignateRejectsGranteeWithoutSuite()

	/**
	 * A grantee request moves the relationship to requested, notifies the
	 * grantor, and dispatches the requested audit event; no envelope is released.
	 *
	 * @return void
	 */
	public function testRequestStartsTimerAndNotifiesGrantor(): void {
		$this->mapper->method('findById')->willReturn($this->contact(EmergencyContact::STATE_GRANTED));
		$this->mapper->method('update')->willReturnArgument(0);
		$this->notificationService->expects($this->once())
			->method('notify')
			->with('emergency_access_requested', 'alice', $this->anything(), 'emergency_access', 'rel-1');

		$contact = $this->service->request(granteeUserId: 'bob', id: 'rel-1');

		$this->assertSame(EmergencyContact::STATE_REQUESTED, $contact->getState());
		$this->assertNotNull($contact->getRequestedAt());
		$this->assertSame(1, $this->auditCount(AuditEventTypes::EMERGENCY_ACCESS_REQUESTED));
	}//end testRequestStartsTimerAndNotifiesGrantor()

	/**
	 * A non-grantee cannot request.
	 *
	 * @return void
	 */
	public function testRequestRejectsNonGrantee(): void {
		$this->mapper->method('findById')->willReturn($this->contact(EmergencyContact::STATE_GRANTED));
		$this->expectException(ForbiddenException::class);
		$this->service->request(granteeUserId: 'mallory', id: 'rel-1');
	}//end testRequestRejectsNonGrantee()

	/**
	 * A grantor decline within the window returns to granted and releases nothing.
	 *
	 * @return void
	 */
	public function testDeclineReleasesNothing(): void {
		$this->mapper->method('findById')->willReturn($this->contact(EmergencyContact::STATE_REQUESTED));
		$this->mapper->method('update')->willReturnArgument(0);

		$contact = $this->service->decline(grantorUserId: 'alice', id: 'rel-1');

		$this->assertSame(EmergencyContact::STATE_GRANTED, $contact->getState());
		$this->assertNull($contact->getRequestedAt());
		$this->assertSame(1, $this->auditCount(AuditEventTypes::EMERGENCY_ACCESS_DECLINED));
	}//end testDeclineReleasesNothing()

	/**
	 * An elapsed pending request is promoted to approved with the system actor.
	 *
	 * @return void
	 */
	public function testPromoteIfElapsedApproves(): void {
		$contact = $this->contact(EmergencyContact::STATE_REQUESTED);
		$contact->setRequestedAt((new DateTime())->modify('-8 days'));
		$this->mapper->method('update')->willReturnArgument(0);

		$result = $this->service->promoteIfElapsed(contact: $contact);

		$this->assertSame(EmergencyContact::STATE_APPROVED, $result->getState());
		$this->assertSame(1, $this->auditCount(AuditEventTypes::EMERGENCY_ACCESS_APPROVED));
		$approved = array_values(
			array_filter(
				$this->dispatched,
				static fn (AuditEvent $e): bool => $e->getEventType() === AuditEventTypes::EMERGENCY_ACCESS_APPROVED
			)
		)[0];
		$this->assertSame(AuditEvent::ACTOR_SYSTEM, $approved->getActorType());
	}//end testPromoteIfElapsedApproves()

	/**
	 * A not-yet-elapsed pending request is NOT approved.
	 *
	 * @return void
	 */
	public function testPromoteIfElapsedWaitsForDeadline(): void {
		$contact = $this->contact(EmergencyContact::STATE_REQUESTED);
		$contact->setRequestedAt((new DateTime())->modify('-1 day'));

		$result = $this->service->promoteIfElapsed(contact: $contact);

		$this->assertSame(EmergencyContact::STATE_REQUESTED, $result->getState());
		$this->assertSame(0, $this->auditCount(AuditEventTypes::EMERGENCY_ACCESS_APPROVED));
	}//end testPromoteIfElapsedWaitsForDeadline()

	/**
	 * The envelope is released only when approved AND the caller is the grantee;
	 * it also notifies the grantor and audits the access.
	 *
	 * @return void
	 */
	public function testFetchEnvelopeReleasedWhenApprovedGrantee(): void {
		$this->mapper->method('findById')->willReturn($this->contact(EmergencyContact::STATE_APPROVED));
		$this->notificationService->expects($this->once())
			->method('notify')
			->with('emergency_access_accessed', 'alice', $this->anything(), 'emergency_access', 'rel-1');

		$envelope = $this->service->fetchEnvelope(granteeUserId: 'bob', id: 'rel-1');

		$this->assertSame('ENVELOPE-CIPHERTEXT', $envelope);
		$this->assertSame(1, $this->auditCount(AuditEventTypes::EMERGENCY_ACCESS_ACCESSED));
	}//end testFetchEnvelopeReleasedWhenApprovedGrantee()

	/**
	 * The envelope is refused before approval (still requested) — no oracle.
	 *
	 * @return void
	 */
	public function testFetchEnvelopeRefusedBeforeApproval(): void {
		$contact = $this->contact(EmergencyContact::STATE_REQUESTED);
		$contact->setRequestedAt((new DateTime())->modify('-1 day'));
		$this->mapper->method('findById')->willReturn($contact);

		$this->expectException(ForbiddenException::class);
		$this->service->fetchEnvelope(granteeUserId: 'bob', id: 'rel-1');
	}//end testFetchEnvelopeRefusedBeforeApproval()

	/**
	 * The envelope is refused to a non-grantee even when approved.
	 *
	 * @return void
	 */
	public function testFetchEnvelopeRefusedToNonGrantee(): void {
		$this->mapper->method('findById')->willReturn($this->contact(EmergencyContact::STATE_APPROVED));
		$this->expectException(ForbiddenException::class);
		$this->service->fetchEnvelope(granteeUserId: 'mallory', id: 'rel-1');
	}//end testFetchEnvelopeRefusedToNonGrantee()

	/**
	 * Revoking deletes the relationship (envelope) and audits the revoke.
	 *
	 * @return void
	 */
	public function testRevokeDeletesEnvelope(): void {
		$this->mapper->method('findById')->willReturn($this->contact(EmergencyContact::STATE_GRANTED));
		$this->mapper->expects($this->once())->method('delete');

		$this->service->revoke(grantorUserId: 'alice', id: 'rel-1');

		$this->assertSame(1, $this->auditCount(AuditEventTypes::EMERGENCY_ACCESS_REVOKED));
	}//end testRevokeDeletesEnvelope()

	/**
	 * Suite rotation invalidates a grantor's envelopes and audits invalidated.
	 *
	 * @return void
	 */
	public function testRotationInvalidatesGrantorEnvelopes(): void {
		$this->mapper->method('findByGrantorSuite')->willReturn([$this->contact(EmergencyContact::STATE_GRANTED)]);
		$this->mapper->expects($this->once())->method('update')->willReturnArgument(0);

		$count = $this->invalidationService->invalidateForGrantorRotation(grantorSuiteId: 'grantor-suite');

		$this->assertSame(1, $count);
		$this->assertSame(1, $this->auditCount(AuditEventTypes::EMERGENCY_ACCESS_INVALIDATED));
	}//end testRotationInvalidatesGrantorEnvelopes()

	/**
	 * Suite revocation clears a grantor's envelopes (delete) and audits.
	 *
	 * @return void
	 */
	public function testRevocationClearsGrantorEnvelopes(): void {
		$this->mapper->method('findByGrantorSuite')->willReturn([$this->contact(EmergencyContact::STATE_GRANTED)]);
		$this->mapper->expects($this->once())->method('delete');

		$count = $this->invalidationService->clearForGrantorRevocation(grantorSuiteId: 'grantor-suite');

		$this->assertSame(1, $count);
		$this->assertSame(1, $this->auditCount(AuditEventTypes::EMERGENCY_ACCESS_INVALIDATED));
	}//end testRevocationClearsGrantorEnvelopes()

	/**
	 * A grantee's suite revocation invalidates envelopes encrypted to them.
	 *
	 * @return void
	 */
	public function testGranteeRevocationInvalidatesEnvelopes(): void {
		$this->mapper->method('findByGranteeSuite')->willReturn([$this->contact(EmergencyContact::STATE_GRANTED)]);
		$this->mapper->expects($this->once())->method('update')->willReturnArgument(0);

		$count = $this->invalidationService->invalidateForGranteeRevocation(granteeSuiteId: 'grantee-suite');

		$this->assertSame(1, $count);
	}//end testGranteeRevocationInvalidatesEnvelopes()

	/**
	 * No audit entry for any lifecycle event carries the recovery envelope or a
	 * forbidden secret-material key (design D8 — structural no-leak).
	 *
	 * @return void
	 */
	public function testNoAuditEntryCarriesEnvelopeOrSecretMaterial(): void {
		$this->mapper->method('findById')->willReturn($this->contact(EmergencyContact::STATE_APPROVED));
		$this->service->fetchEnvelope(granteeUserId: 'bob', id: 'rel-1');

		foreach ($this->dispatched as $event) {
			$encoded = json_encode($event->getMetadata());
			$this->assertStringNotContainsString('ENVELOPE-CIPHERTEXT', (string)$encoded);
			foreach (AuditEventTypes::FORBIDDEN_KEYS as $forbidden) {
				$this->assertArrayNotHasKey($forbidden, $event->getMetadata());
			}
		}
	}//end testNoAuditEntryCarriesEnvelopeOrSecretMaterial()
}//end class
