<?php

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\SuiteMigration;
use OCA\Keepiq\Db\SuiteMigrationMapper;
use OCA\Keepiq\Event\SuiteMigrationAbortedEvent;
use OCA\Keepiq\Event\SuiteMigrationCompletedEvent;
use OCA\Keepiq\Exception\MigrationAbortRefusedException;
use OCA\Keepiq\Exception\MigrationIncompleteException;
use OCA\Keepiq\Service\EncryptionSuiteService;
use OCA\Keepiq\Service\LinkShareService;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\MigrationWorkService;
use OCA\Keepiq\Service\WriteLockService;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MigrationServiceTest extends TestCase {

	private MigrationService $service;

	private SuiteMigrationMapper&MockObject $migrationMapper;

	private EncryptionSuiteMapper&MockObject $suiteMapper;

	private MigrationWorkService&MockObject $workService;

	private EncryptionSuiteService&MockObject $suiteService;

	private LinkShareService&MockObject $linkShareService;

	private WriteLockService&MockObject $writeLockService;

	protected function setUp(): void {
		$this->migrationMapper = $this->createMock(SuiteMigrationMapper::class);
		$this->suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
		$this->workService = $this->createMock(MigrationWorkService::class);
		$this->suiteService = $this->createMock(EncryptionSuiteService::class);
		$this->linkShareService = $this->createMock(LinkShareService::class);
		$this->writeLockService = $this->createMock(WriteLockService::class);
		$logger = $this->createMock(LoggerInterface::class);

		// Named arguments: the constructor gained collaborators for the terminal
		// work moved out of EncryptionSuiteController, and positional wiring
		// would silently slot the logger into the suite-service parameter.
		$this->service = new MigrationService(
			mapper: $this->migrationMapper,
			suiteMapper: $this->suiteMapper,
			suiteService: $this->suiteService,
			linkShareService: $this->linkShareService,
			workService: $this->workService,
			writeLockService: $this->writeLockService,
			logger: $logger,
		);
	}//end setUp()

	public function testInitiateCompromiseRecovery(): void {
		$this->migrationMapper->expects($this->once())->method('insert');

		$migration = $this->service->initiateCompromiseRecovery('old-suite', 'new-suite');

		$this->assertEquals('in_progress', $migration->getStatus());
		$this->assertEquals('old-suite', $migration->getOldSuiteId());
		$this->assertEquals('new-suite', $migration->getNewSuiteId());
	}//end testInitiateCompromiseRecovery()

	public function testCompleteMigrationSetsMigrationCompleted(): void {
		$migration = new SuiteMigration();
		$migration->setId('migration-1');
		$migration->setOldSuiteId('old-suite');
		$migration->setNewSuiteId('new-suite');
		$migration->setStatus('in_progress');

		$this->migrationMapper->method('findById')->willReturn($migration);
		$this->migrationMapper->expects($this->once())->method('update');

		// The owner has to resolve for the safety gates to be evaluable at all;
		// an unresolvable owner is now a refusal, covered by its own test.
		$suite = new EncryptionSuite();
		$suite->setId('old-suite');
		$suite->setOwnerType('user');
		$suite->setOwnerId('alice');
		$this->suiteMapper->method('findById')->willReturn($suite);
		$this->stubNothingOutstanding();

		// The old suite is marked compromised through EncryptionSuiteService
		// (mocked here), never by writing the suite mapper directly.
		$this->suiteMapper->expects($this->never())->method('update');

		$result = $this->service->completeMigration('migration-1');

		$this->assertEquals('completed', $result['status']);
		$this->assertNotNull($result['completedAt']);
	}//end testCompleteMigrationSetsMigrationCompleted()

	public function testCompleteMigrationWithErrors(): void {
		// The owner must be arranged: an unresolvable owner is now a refusal
		// rather than a silent skip of every safety gate, so a migration with
		// no resolvable suite owner never reaches a terminal status.
		$this->arrangeOwnedMigration();
		$this->stubNothingOutstanding();

		$result = $this->service->completeMigration('migration-1', true);

		$this->assertEquals('completed_with_errors', $result['status']);
	}//end testCompleteMigrationWithErrors()

	/**
	 * An unresolvable owner refuses termination instead of failing open.
	 *
	 * The gate used to return [0, []] when resolveOwnerId() came back null,
	 * which skipped dropVersionsBeyondWindow, assertNothingUnaccountedFor AND
	 * assertLossAcknowledged, drove the migration to `completed`, and called
	 * markCompromised on a suite that may still have held every secret. An
	 * unresolvable owner means the gate cannot be evaluated, which is not the
	 * same as the gate passing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-a-migration-always-has-a-way-to-terminate
	 */
	public function testUnresolvableOwnerRefusesTerminationRatherThanFailingOpen(): void {
		$migration = new SuiteMigration();
		$migration->setId('migration-1');
		$migration->setOldSuiteId('old-suite');
		$migration->setNewSuiteId('new-suite');
		$migration->setStatus('in_progress');

		$this->migrationMapper->method('findById')->willReturn($migration);
		// No suite arranged, so resolveOwnerId() yields null.

		$this->suiteService->expects($this->never())->method('markCompromised');
		$this->migrationMapper->expects($this->never())->method('update');

		$this->expectException(MigrationIncompleteException::class);

		$this->service->completeMigration('migration-1');
	}//end testUnresolvableOwnerRefusesTerminationRatherThanFailingOpen()

	/**
	 * Completion is idempotent: the destructive cascade is not replayable.
	 *
	 * Replaying complete() with a retained migration id used to re-run
	 * markCompromised (overwriting audit fields), delete every link share and
	 * passkey created AFTER the rotation, and re-dispatch the completion event.
	 * An ordinary client retry of a timed-out POST was enough.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-a-migration-always-has-a-way-to-terminate
	 */
	public function testCompletingAnAlreadyCompletedMigrationIsANoOp(): void {
		$migration = new SuiteMigration();
		$migration->setId('migration-1');
		$migration->setOldSuiteId('old-suite');
		$migration->setNewSuiteId('new-suite');
		$migration->setStatus('completed');

		$this->migrationMapper->method('findById')->willReturn($migration);

		$this->suiteService->expects($this->never())->method('markCompromised');
		$this->migrationMapper->expects($this->never())->method('update');
		$this->workService->expects($this->never())->method('dropVersionsBeyondWindow');

		$result = $this->service->completeMigration('migration-1');

		$this->assertSame('completed', $result['status']);
		$this->assertTrue($result['alreadyCompleted']);
	}//end testCompletingAnAlreadyCompletedMigrationIsANoOp()

	public function testIsWriteLockedWhenMigrationInProgress(): void {
		// Delegated to WriteLockService so there is one implementation; the
		// walk over the owner's suites is covered by WriteLockServiceTest.
		$this->writeLockService->method('isWriteLocked')->willReturn(true);

		$this->assertTrue($this->service->isWriteLocked('user', 'testuser'));
	}//end testIsWriteLockedWhenMigrationInProgress()

	public function testIsNotWriteLockedWhenNoMigration(): void {
		$this->writeLockService->method('isWriteLocked')->willReturn(false);

		$this->assertFalse($this->service->isWriteLocked('user', 'testuser'));
	}//end testIsNotWriteLockedWhenNoMigration()

	/**
	 * Aborting an untouched migration restores the old suite and discards the
	 * successor by DELETING it (never revoking, which would cascade the
	 * user-suite revocation side effects).
	 *
	 * @return void
	 */
	public function testAbortRestoresOldSuiteAndDeletesTheSuccessor(): void {
		$migration = $this->arrangeAbortableMigration(committed: 0);

		$this->migrationMapper->expects($this->once())->method('update');
		// The successor is DELETED, not revoked — revokeSuite must never run.
		$this->suiteMapper->expects($this->once())->method('delete');
		$this->suiteService->expects($this->never())->method('revokeSuite');
		$this->workService->expects($this->once())->method('clearFailureAccounting');

		$result = $this->service->abortMigration('migration-1');

		$this->assertTrue($result['aborted']);
		$this->assertSame('aborted', $migration->getStatus());
	}//end testAbortRestoresOldSuiteAndDeletesTheSuccessor()

	/**
	 * Once a record has been committed to the new suite, abort is refused and
	 * reports the committed count; nothing is torn down.
	 *
	 * @return void
	 */
	public function testAbortRefusedAfterARecordHasBeenCommitted(): void {
		$this->arrangeAbortableMigration(committed: 3);

		$this->migrationMapper->expects($this->never())->method('update');
		$this->suiteMapper->expects($this->never())->method('delete');

		try {
			$this->service->abortMigration('migration-1');
			$this->fail('Expected MigrationAbortRefusedException');
		} catch (MigrationAbortRefusedException $e) {
			$this->assertSame(3, $e->getCommitted());
		}
	}//end testAbortRefusedAfterARecordHasBeenCommitted()

	/**
	 * Aborting an already-terminated migration is a no-op, not a second
	 * teardown — mirrors completeMigration's idempotency guard.
	 *
	 * @return void
	 */
	public function testAbortingAnAlreadyTerminatedMigrationIsANoOp(): void {
		$migration = new SuiteMigration();
		$migration->setId('migration-1');
		$migration->setOldSuiteId('old-suite');
		$migration->setNewSuiteId('new-suite');
		$migration->setStatus('completed');
		$this->migrationMapper->method('findById')->willReturn($migration);

		$this->migrationMapper->expects($this->never())->method('update');
		$this->suiteMapper->expects($this->never())->method('delete');

		$result = $this->service->abortMigration('migration-1');

		$this->assertFalse($result['aborted']);
		$this->assertTrue($result['alreadyTerminated']);
	}//end testAbortingAnAlreadyTerminatedMigrationIsANoOp()

	/**
	 * Abort dispatches SuiteMigrationAbortedEvent and NEVER
	 * SuiteMigrationCompletedEvent — the latter runs the terminal cascade
	 * (compromise-flagging, link-share revocation, emergency-access
	 * invalidation) that must not fire when nothing migrated.
	 *
	 * @return void
	 */
	public function testAbortDispatchesAbortedEventNotCompleted(): void {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$service = new MigrationService(
			mapper: $this->migrationMapper,
			suiteMapper: $this->suiteMapper,
			suiteService: $this->suiteService,
			linkShareService: $this->linkShareService,
			workService: $this->workService,
			writeLockService: $this->writeLockService,
			logger: $this->createMock(LoggerInterface::class),
			eventDispatcher: $dispatcher,
		);
		$this->arrangeAbortableMigration(committed: 0);

		$dispatched = [];
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			static function (object $event) use (&$dispatched): void {
				$dispatched[] = $event::class;
			}
		);

		$service->abortMigration('migration-1');

		$this->assertContains(SuiteMigrationAbortedEvent::class, $dispatched);
		$this->assertNotContains(SuiteMigrationCompletedEvent::class, $dispatched);
	}//end testAbortDispatchesAbortedEventNotCompleted()

	/**
	 * Wire an in-progress, abortable migration: the old suite resolves to an
	 * owner and the successor is a distinct suite so the delete path is
	 * exercised. `committed` sets what countCommitted reports.
	 *
	 * @param int $committed How many records countCommitted should report
	 *
	 * @return SuiteMigration
	 */
	private function arrangeAbortableMigration(int $committed): SuiteMigration {
		$migration = new SuiteMigration();
		$migration->setId('migration-1');
		$migration->setOldSuiteId('old-suite');
		$migration->setNewSuiteId('new-suite');
		$migration->setStatus('in_progress');
		$this->migrationMapper->method('findById')->willReturn($migration);

		$old = new EncryptionSuite();
		$old->setId('old-suite');
		$old->setOwnerType('user');
		$old->setOwnerId('alice');

		$successor = new EncryptionSuite();
		$successor->setId('new-suite');
		$successor->setOwnerType('user');
		$successor->setOwnerId('alice');

		$this->suiteMapper->method('findById')->willReturnCallback(
			static function (string $id) use ($old, $successor): EncryptionSuite {
				return ($id === 'new-suite') ? $successor : $old;
			}
		);

		$this->workService->method('countCommitted')->willReturn($committed);

		return $migration;
	}//end arrangeAbortableMigration()

	/**
	 * Wire an in-progress migration whose old suite resolves to an owner, so
	 * the owner-scoped terminal work and the completion gate both engage.
	 *
	 * @return SuiteMigration
	 */
	private function arrangeOwnedMigration(): SuiteMigration {
		$migration = new SuiteMigration();
		$migration->setId('migration-1');
		$migration->setOldSuiteId('old-suite');
		$migration->setNewSuiteId('new-suite');
		$migration->setStatus('in_progress');

		$suite = new EncryptionSuite();
		$suite->setId('old-suite');
		$suite->setOwnerType('user');
		$suite->setOwnerId('alice');

		$this->migrationMapper->method('findById')->willReturn($migration);
		$this->suiteMapper->method('findById')->willReturn($suite);

		return $migration;
	}//end arrangeOwnedMigration()

	/**
	 * Stub rows left on the old suite that NOBODY ATTEMPTED — an unfinished
	 * migration. These block completion.
	 *
	 * @param int $secrets Unattempted secrets
	 * @param int $versions Unattempted version rows
	 * @param int $grants Unattempted attachment grants
	 *
	 * @return void
	 */
	private function stubUnattempted(int $secrets, int $versions, int $grants): void {
		$total = ($secrets + $versions + $grants);

		$this->workService->method('countOutstanding')->willReturn(
			[
				'secrets' => $secrets,
				'versions' => $versions,
				'attachmentGrants' => $grants,
				'total' => $total,
				'unaccountedSecrets' => $secrets,
				'unaccountedVersions' => $versions,
				'unaccountedGrants' => $grants,
				'unaccountedTotal' => $total,
				'failedTotal' => 0,
			]
		);
	}//end stubUnattempted()

	/**
	 * Stub rows left on the old suite that were ALL attempted and reported
	 * unrecoverable. These do not block completion, but finalising them
	 * requires an acknowledgement.
	 *
	 * @param int $failedSecrets How many secrets failed
	 *
	 * @return void
	 */
	private function stubFailedOnly(int $failedSecrets): void {
		$this->workService->method('countOutstanding')->willReturn(
			[
				'secrets' => $failedSecrets,
				'versions' => 0,
				'attachmentGrants' => 0,
				'total' => $failedSecrets,
				'unaccountedSecrets' => 0,
				'unaccountedVersions' => 0,
				'unaccountedGrants' => 0,
				'unaccountedTotal' => 0,
				'failedTotal' => $failedSecrets,
			]
		);

		$rows = [];
		for ($index = 0; $index < $failedSecrets; $index++) {
			$rows[] = [
				'id' => 'secret-' . $index,
				'name' => 'unreadable-' . $index,
				'error' => 'secrets: could not decrypt with the old key',
			];
		}

		$this->workService->method('listUnrecoverable')->willReturn($rows);

		// The acknowledgement threshold now comes from a COUNT over the failure
		// table, not from the size of the (capped) display list above. With more
		// failures than the display cap those two numbers differ, and only the
		// count can be satisfied.
		$this->workService->method('countUnrecoverable')->willReturn($failedSecrets);
	}//end stubFailedOnly()

	/**
	 * Stub a fully-migrated old suite: nothing left at all.
	 *
	 * @return void
	 */
	private function stubNothingOutstanding(): void {
		$this->stubUnattempted(secrets: 0, versions: 0, grants: 0);
		$this->workService->method('listUnrecoverable')->willReturn([]);
		$this->workService->method('countUnrecoverable')->willReturn(0);
	}//end stubNothingOutstanding()

	/**
	 * Test completion is refused while unattempted rows remain.
	 *
	 * Terminating marks the old suite compromised, and a compromised suite
	 * refuses to serve its ciphertext — so finalising an unfinished migration
	 * would take every row it never reached down with it.
	 *
	 * @return void
	 */
	public function testCompleteRefusedWhileUnattemptedRowsRemain(): void {
		$this->arrangeOwnedMigration();
		// The attachment-grant pass never ran.
		$this->stubUnattempted(secrets: 0, versions: 0, grants: 3);

		// Nothing terminal may happen: the suite stays active, the write lock
		// stays on, and the migration row is not transitioned.
		$this->migrationMapper->expects($this->never())->method('update');

		$this->expectException(MigrationIncompleteException::class);

		$this->service->completeMigration('migration-1');
	}//end testCompleteRefusedWhileUnattemptedRowsRemain()

	/**
	 * Test the refusal message names what has not been migrated and points at
	 * resuming rather than at giving up.
	 *
	 * @return void
	 */
	public function testRefusalReportsTheUnattemptedCounts(): void {
		$this->arrangeOwnedMigration();
		$this->stubUnattempted(secrets: 2, versions: 5, grants: 1);

		try {
			$this->service->completeMigration('migration-1');
			$this->fail('Expected MigrationIncompleteException');
		} catch (MigrationIncompleteException $e) {
			$this->assertStringContainsString('8 record(s)', $e->getMessage());
			$this->assertStringContainsString('2 secret(s)', $e->getMessage());
			$this->assertStringContainsString('5 version(s)', $e->getMessage());
			$this->assertStringContainsString('1 attachment grant(s)', $e->getMessage());
			$this->assertStringContainsString('can be resumed', $e->getMessage());
		}
	}//end testRefusalReportsTheUnattemptedCounts()

	/**
	 * Test a refused completion leaves the old suite active.
	 *
	 * This is the whole point of the gate: the vault must stay readable so the
	 * migration can be resumed.
	 *
	 * @return void
	 */
	public function testRefusedCompletionDoesNotMarkSuiteCompromised(): void {
		$this->arrangeOwnedMigration();
		$this->stubUnattempted(secrets: 1, versions: 0, grants: 0);

		$this->suiteService->expects($this->never())->method('markCompromised');
		$this->linkShareService->expects($this->never())->method('deleteByUserId');

		$this->expectException(MigrationIncompleteException::class);

		$this->service->completeMigration('migration-1');
	}//end testRefusedCompletionDoesNotMarkSuiteCompromised()

	/**
	 * Test an attempted-and-failed row no longer blocks completion, but is not
	 * finalised without an acknowledgement either.
	 *
	 * Retrying will not help these, so holding the migration open would leave
	 * the vault write-locked with no way out. Locking them out is still the
	 * user's call, never a side effect of the client calling complete.
	 *
	 * @return void
	 */
	public function testFailedRowsDoNotBlockButRequireAcknowledgement(): void {
		$this->arrangeOwnedMigration();
		$this->stubFailedOnly(failedSecrets: 1);

		$this->suiteService->expects($this->never())->method('markCompromised');
		$this->migrationMapper->expects($this->never())->method('update');

		try {
			$this->service->completeMigration('migration-1');
			$this->fail('Expected MigrationIncompleteException');
		} catch (MigrationIncompleteException $e) {
			$this->assertStringContainsString('could not be decrypted with the old key', $e->getMessage());
			$this->assertStringContainsString('acceptUnrecoverable=1', $e->getMessage());
			// The user must be told the data itself is not thrown away.
			$this->assertStringContainsString('ciphertext is retained', $e->getMessage());
		}
	}//end testFailedRowsDoNotBlockButRequireAcknowledgement()

	/**
	 * Test a mismatched acknowledgement is refused.
	 *
	 * Guards the case that matters: a run where far more failed than the client
	 * believes must not be waved through by a stale or optimistic number.
	 *
	 * @return void
	 */
	public function testMismatchedAcknowledgementIsRefused(): void {
		$this->arrangeOwnedMigration();
		$this->stubFailedOnly(failedSecrets: 40);

		$this->suiteService->expects($this->never())->method('markCompromised');

		$this->expectException(MigrationIncompleteException::class);

		$this->service->completeMigration('migration-1', false, 1);
	}//end testMismatchedAcknowledgementIsRefused()

	/**
	 * Test an acknowledged loss finalises, locks the old suite and reports the
	 * secrets that lost access.
	 *
	 * @return void
	 */
	public function testAcknowledgedLossFinalisesAndLocksTheOldSuite(): void {
		$this->arrangeOwnedMigration();
		$this->stubFailedOnly(failedSecrets: 2);
		$this->workService->method('dropVersionsBeyondWindow')->willReturn(0);
		$this->suiteService->method('markCompromised')->willReturn(new EncryptionSuite());

		// The old suite IS locked here — that is the user's acknowledged
		// decision, and it is what stops the leaked key serving the rest.
		$this->suiteService->expects($this->once())->method('markCompromised');

		$result = $this->service->completeMigration('migration-1', false, 2);

		$this->assertSame('completed_with_errors', $result['status']);
		$this->assertCount(2, $result['unrecoverable']);
		$this->assertSame('unreadable-0', $result['unrecoverable'][0]['name']);
	}//end testAcknowledgedLossFinalisesAndLocksTheOldSuite()

	/**
	 * Test completion proceeds once nothing is left on the old suite, and does
	 * its terminal work in the specified order.
	 *
	 * @return void
	 */
	public function testCompleteProceedsAndPerformsTerminalWorkInOrder(): void {
		$this->arrangeOwnedMigration();
		$this->stubNothingOutstanding();
		$this->workService->method('dropVersionsBeyondWindow')->willReturn(7);

		$order = [];
		$this->suiteService->method('markCompromised')
			->willReturnCallback(
				function () use (&$order) {
					$order[] = 'markCompromised';
					return new EncryptionSuite();
				}
			);
		$this->linkShareService->method('deleteByUserId')
			->willReturnCallback(
				function () use (&$order) {
					$order[] = 'revokeLinkShares';
				}
			);
		$this->migrationMapper->method('update')
			->willReturnCallback(
				function ($entity) use (&$order) {
					$order[] = 'transitionMigration';
					return $entity;
				}
			);

		$result = $this->service->completeMigration('migration-1');

		$this->assertSame('completed', $result['status']);
		$this->assertNotNull($result['completedAt']);
		// Reported so the dialog can state the version-history loss.
		$this->assertSame(7, $result['droppedVersions']);

		// The old suite is only marked compromised after the gate has passed,
		// and the link-share cascade follows it.
		$this->assertSame(
			['transitionMigration', 'markCompromised', 'revokeLinkShares'],
			$order
		);
	}//end testCompleteProceedsAndPerformsTerminalWorkInOrder()

	/**
	 * Test out-of-window version rows are dropped before the gate is evaluated.
	 *
	 * Rows the client was never asked to migrate must not hold the gate shut.
	 *
	 * @return void
	 */
	public function testVersionDropRunsBeforeTheGate(): void {
		$this->arrangeOwnedMigration();
		$this->stubNothingOutstanding();

		$order = [];
		$this->workService->method('dropVersionsBeyondWindow')
			->willReturnCallback(
				function () use (&$order) {
					$order[] = 'drop';
					return 4;
				}
			);
		$this->workService->method('countOutstanding')
			->willReturnCallback(
				function () use (&$order) {
					$order[] = 'gate';
					return [
						'secrets' => 0,
						'versions' => 0,
						'attachmentGrants' => 0,
						'total' => 0,
					];
				}
			);

		$this->service->completeMigration('migration-1');

		$this->assertSame(['drop', 'gate'], $order);
	}//end testVersionDropRunsBeforeTheGate()
}//end class
