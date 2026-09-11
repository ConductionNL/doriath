<?php

/**
 * Unit tests for EmergencyEnvelopeInvalidationService — specifically the
 * rotation MIGRATION path added by migrate-emergency-access-on-rotation, plus
 * the residual SWEEP the completion listener now performs.
 *
 * The re-point is the one migrated store the grantor cannot round-trip: only the
 * grantee can open the envelope, so the server shape-checks it and asserts it
 * was sealed to the grantee's CURRENT active suite, then re-points the contact
 * to the new suite keeping it `granted`. A contact the browser could not carry
 * stays on the old suite for the sweep to invalidate.
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

use InvalidArgumentException;
use OCA\Keepiq\Db\EmergencyContact;
use OCA\Keepiq\Db\EmergencyContactMapper;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\EmergencyAccessAuditTrail;
use OCA\Keepiq\Service\EmergencyEnvelopeInvalidationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the rotation re-envelope + residual-sweep behaviour.
 */
class EmergencyEnvelopeInvalidationServiceTest extends TestCase {

	/**
	 * @var EmergencyContactMapper
	 */
	private $mapper;

	/**
	 * @var EncryptionSuiteMapper
	 */
	private $suiteMapper;

	/**
	 * @var array<int,AuditEvent>
	 */
	private array $dispatched = [];

	/**
	 * @var EmergencyEnvelopeInvalidationService
	 */
	private EmergencyEnvelopeInvalidationService $service;

	/**
	 * Set up mocks and capture dispatched audit events.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(EmergencyContactMapper::class);
		$this->suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
		$this->dispatched = [];

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function ($event): void {
				if ($event instanceof AuditEvent) {
					$this->dispatched[] = $event;
				}
			}
		);

		$this->service = new EmergencyEnvelopeInvalidationService(
			mapper: $this->mapper,
			suiteMapper: $this->suiteMapper,
			auditTrail: new EmergencyAccessAuditTrail(eventDispatcher: $dispatcher),
		);

		$this->mapper->method('update')->willReturnArgument(0);
	}//end setUp()

	/**
	 * A well-formed recovery envelope sealed to the grantee's current suite.
	 *
	 * @return string
	 */
	private function envelope(): string {
		return (string)json_encode([
			'v' => 1,
			'alg' => 'RSA-OAEP+AES-256-GCM',
			'encKey' => 'd3JhcHBlZC1hZXMta2V5',
			'iv' => 'aXYtYnl0ZXM=',
			'ct' => 'Y2lwaGVydGV4dA==',
		]);
	}//end envelope()

	/**
	 * Build an emergency contact on the old suite.
	 *
	 * @param string $grantor The grantor user id
	 * @param string $grantorSuite The grantor suite the contact is bound to
	 * @param string $state The lifecycle state
	 *
	 * @return EmergencyContact
	 */
	private function contact(
		string $grantor = 'alice',
		string $grantorSuite = 'old-suite',
		string $state = EmergencyContact::STATE_GRANTED,
	): EmergencyContact {
		$c = new EmergencyContact();
		$c->setId('rel-1');
		$c->setGrantorUserId($grantor);
		$c->setGranteeUserId('bob');
		$c->setAccessLevel(EmergencyContact::ACCESS_VIEW);
		$c->setWaitPeriodDays(7);
		$c->setState($state);
		$c->setRecoveryEnvelope('OLD-ENVELOPE');
		$c->setGrantorSuiteId($grantorSuite);
		$c->setGranteeSuiteId('grantee-old');
		return $c;
	}//end contact()

	/**
	 * A real active-suite entity (magic getId cannot be mocked).
	 *
	 * @param string $id The suite id
	 *
	 * @return EncryptionSuite
	 */
	private function suite(string $id): EncryptionSuite {
		$suite = new EncryptionSuite();
		$suite->setId($id);
		return $suite;
	}//end suite()

	/**
	 * Count captured audit events of a type.
	 *
	 * @param string $eventType The event type
	 *
	 * @return int
	 */
	private function auditCount(string $eventType): int {
		return count(array_filter($this->dispatched, static fn (AuditEvent $e): bool => $e->getEventType() === $eventType));
	}//end auditCount()

	/**
	 * A reachable contact is re-pointed to the new suite, its envelope replaced,
	 * its state kept granted, its invalidated reason cleared, and a (re-)grant
	 * audit dispatched.
	 *
	 * @return void
	 */
	public function testReEnvelopeRepointsToNewSuiteAndKeepsGranted(): void {
		$contact = $this->contact();
		$contact->setInvalidatedReason('was-invalidated-earlier');
		$this->mapper->method('findById')->willReturn($contact);
		$this->suiteMapper->method('findActiveByOwner')->willReturn($this->suite('grantee-active'));

		$updated = $this->service->reEnvelopeForRotation(
			ownerId: 'alice',
			oldSuiteId: 'old-suite',
			newSuiteId: 'new-suite',
			contactId: 'rel-1',
			recoveryEnvelope: $this->envelope(),
			sealedSuiteId: 'grantee-active',
		);

		$this->assertSame('new-suite', $updated->getGrantorSuiteId());
		$this->assertSame('grantee-active', $updated->getGranteeSuiteId());
		$this->assertSame(EmergencyContact::STATE_GRANTED, $updated->getState());
		$this->assertSame($this->envelope(), $updated->getRecoveryEnvelope());
		$this->assertNull($updated->getInvalidatedReason());
		$this->assertSame(1, $this->auditCount(AuditEventTypes::EMERGENCY_ACCESS_GRANTED));
	}//end testReEnvelopeRepointsToNewSuiteAndKeepsGranted()

	/**
	 * A contact whose grantor is not the migration owner is refused.
	 *
	 * @return void
	 */
	public function testReEnvelopeRefusesForeignGrantor(): void {
		$this->mapper->method('findById')->willReturn($this->contact(grantor: 'mallory'));

		$this->expectException(ForbiddenException::class);
		$this->service->reEnvelopeForRotation(
			ownerId: 'alice',
			oldSuiteId: 'old-suite',
			newSuiteId: 'new-suite',
			contactId: 'rel-1',
			recoveryEnvelope: $this->envelope(),
			sealedSuiteId: 'grantee-active',
		);
	}//end testReEnvelopeRefusesForeignGrantor()

	/**
	 * A contact bound to a suite other than the migration's old suite is refused.
	 *
	 * @return void
	 */
	public function testReEnvelopeRefusesContactOnDifferentSuite(): void {
		$this->mapper->method('findById')->willReturn($this->contact(grantorSuite: 'some-other-suite'));

		$this->expectException(ForbiddenException::class);
		$this->service->reEnvelopeForRotation(
			ownerId: 'alice',
			oldSuiteId: 'old-suite',
			newSuiteId: 'new-suite',
			contactId: 'rel-1',
			recoveryEnvelope: $this->envelope(),
			sealedSuiteId: 'grantee-active',
		);
	}//end testReEnvelopeRefusesContactOnDifferentSuite()

	/**
	 * A missing contact surfaces as NotFound.
	 *
	 * @return void
	 */
	public function testReEnvelopeThrowsWhenContactMissing(): void {
		$this->mapper->method('findById')->willThrowException(new DoesNotExistException('nope'));

		$this->expectException(NotFoundException::class);
		$this->service->reEnvelopeForRotation(
			ownerId: 'alice',
			oldSuiteId: 'old-suite',
			newSuiteId: 'new-suite',
			contactId: 'rel-1',
			recoveryEnvelope: $this->envelope(),
			sealedSuiteId: 'grantee-active',
		);
	}//end testReEnvelopeThrowsWhenContactMissing()

	/**
	 * Malformed envelopes (bad JSON, wrong version, wrong algorithm, missing
	 * ciphertext field) are all rejected as bad requests.
	 *
	 * @param string $envelope The malformed envelope
	 *
	 * @return void
	 *
	 * @dataProvider malformedEnvelopes
	 */
	public function testReEnvelopeRejectsMalformedEnvelope(string $envelope): void {
		$this->mapper->method('findById')->willReturn($this->contact());
		$this->suiteMapper->method('findActiveByOwner')->willReturn($this->suite('grantee-active'));

		$this->expectException(InvalidArgumentException::class);
		$this->service->reEnvelopeForRotation(
			ownerId: 'alice',
			oldSuiteId: 'old-suite',
			newSuiteId: 'new-suite',
			contactId: 'rel-1',
			recoveryEnvelope: $envelope,
			sealedSuiteId: 'grantee-active',
		);
	}//end testReEnvelopeRejectsMalformedEnvelope()

	/**
	 * Malformed-envelope cases.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function malformedEnvelopes(): array {
		return [
			'not json' => ['this is not json'],
			'json array not object' => ['[1,2,3]'],
			'wrong version' => ['{"v":2,"alg":"RSA-OAEP+AES-256-GCM","encKey":"a","iv":"b","ct":"c"}'],
			'wrong alg' => ['{"v":1,"alg":"AES-CBC","encKey":"a","iv":"b","ct":"c"}'],
			'missing ct' => ['{"v":1,"alg":"RSA-OAEP+AES-256-GCM","encKey":"a","iv":"b"}'],
			'empty encKey' => ['{"v":1,"alg":"RSA-OAEP+AES-256-GCM","encKey":"","iv":"b","ct":"c"}'],
		];
	}//end malformedEnvelopes()

	/**
	 * An envelope sealed to a suite that is not the grantee's current active
	 * suite is rejected — it would be unopenable.
	 *
	 * @return void
	 */
	public function testReEnvelopeRejectsSuiteMismatch(): void {
		$this->mapper->method('findById')->willReturn($this->contact());
		$this->suiteMapper->method('findActiveByOwner')->willReturn($this->suite('grantee-active'));

		$this->expectException(InvalidArgumentException::class);
		$this->service->reEnvelopeForRotation(
			ownerId: 'alice',
			oldSuiteId: 'old-suite',
			newSuiteId: 'new-suite',
			contactId: 'rel-1',
			recoveryEnvelope: $this->envelope(),
			sealedSuiteId: 'a-stale-grantee-suite',
		);
	}//end testReEnvelopeRejectsSuiteMismatch()

	/**
	 * A grantee with no active suite cannot be sealed to; the re-point is refused
	 * (the client should have treated the contact as residual).
	 *
	 * @return void
	 */
	public function testReEnvelopeRejectsWhenGranteeHasNoActiveSuite(): void {
		$this->mapper->method('findById')->willReturn($this->contact());
		$this->suiteMapper->method('findActiveByOwner')->willThrowException(new DoesNotExistException('none'));

		$this->expectException(InvalidArgumentException::class);
		$this->service->reEnvelopeForRotation(
			ownerId: 'alice',
			oldSuiteId: 'old-suite',
			newSuiteId: 'new-suite',
			contactId: 'rel-1',
			recoveryEnvelope: $this->envelope(),
			sealedSuiteId: 'grantee-active',
		);
	}//end testReEnvelopeRejectsWhenGranteeHasNoActiveSuite()

	/**
	 * The residual sweep invalidates only the contacts still bound to the old
	 * suite. A contact the loop migrated is no longer returned by
	 * findByGrantorSuite(oldSuiteId), so it is never touched by the sweep.
	 *
	 * @return void
	 */
	public function testResidualSweepInvalidatesOnlyOldSuiteContacts(): void {
		// The query is by OLD suite, so a migrated contact (now on the new suite)
		// simply is not in this list — the sweep can only ever see the residual.
		$residual = $this->contact(grantorSuite: 'old-suite');
		$this->mapper->method('findByGrantorSuite')->willReturn([$residual]);

		$count = $this->service->invalidateForGrantorRotation(grantorSuiteId: 'old-suite');

		$this->assertSame(1, $count);
		$this->assertSame(EmergencyContact::STATE_INVALIDATED, $residual->getState());
		$this->assertNull($residual->getRecoveryEnvelope());
		$this->assertSame('grantor_rotation', $residual->getInvalidatedReason());
	}//end testResidualSweepInvalidatesOnlyOldSuiteContacts()
}//end class
