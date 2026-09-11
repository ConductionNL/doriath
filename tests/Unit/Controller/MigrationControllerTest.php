<?php

/**
 * Unit tests for MigrationController.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Controller
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

namespace OCA\Keepiq\Tests\Unit\Controller;

use DateTime;
use OCA\Keepiq\Controller\MigrationController;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SuiteMigration;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\MigrationAbortRefusedException;
use OCA\Keepiq\Exception\MigrationIncompleteException;
use OCA\Keepiq\Exception\NotFoundException;
use InvalidArgumentException;
use OCA\Keepiq\Db\EmergencyContact;
use OCA\Keepiq\Service\EmergencyEnvelopeInvalidationService;
use OCA\Keepiq\Service\EncryptionSuiteService;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\MigrationWorkService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MigrationController.
 */
class MigrationControllerTest extends TestCase {

	private MigrationController $controller;

	private MigrationService&MockObject $migrationService;

	private MigrationWorkService&MockObject $workService;

	private EncryptionSuiteService&MockObject $suiteService;

	private EmergencyEnvelopeInvalidationService&MockObject $envelopeService;

	private IUserSession&MockObject $userSession;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$request = $this->createMock(IRequest::class);
		$this->migrationService = $this->createMock(MigrationService::class);
		$this->workService = $this->createMock(MigrationWorkService::class);
		$this->suiteService = $this->createMock(EncryptionSuiteService::class);
		$this->envelopeService = $this->createMock(EmergencyEnvelopeInvalidationService::class);
		$this->userSession = $this->createMock(IUserSession::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);

		// Named arguments: the constructor gained the work service between
		// migrationService and suiteService, and positional wiring would slot
		// a suite-service mock into the work-service parameter.
		$this->controller = new MigrationController(
			request: $request,
			migrationService: $this->migrationService,
			workService: $this->workService,
			suiteService: $this->suiteService,
			envelopeService: $this->envelopeService,
			userSession: $this->userSession,
		);
	}//end setUp()

	/**
	 * Test getStatus returns migration when in progress.
	 *
	 * @return void
	 */
	public function testGetStatusReturnsMigration(): void {
		$migration = new SuiteMigration();
		$migration->setId('migr-1');
		$migration->setOldSuiteId('old-suite');
		$migration->setNewSuiteId('new-suite');
		$migration->setStatus('in_progress');

		$this->migrationService->method('getInProgressMigration')
			->with('user', 'testuser')
			->willReturn($migration);

		$response = $this->controller->getStatus();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('migr-1', $response->getData()['id']);
		$this->assertSame('in_progress', $response->getData()['status']);
	}//end testGetStatusReturnsMigration()

	/**
	 * Test getStatus returns 'none' when no migration in progress.
	 *
	 * @return void
	 */
	public function testGetStatusReturnsNoneWhenNoMigration(): void {
		$this->migrationService->method('getInProgressMigration')
			->willThrowException(new DoesNotExistException('No migration'));

		$response = $this->controller->getStatus();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('none', $response->getData()['status']);
	}//end testGetStatusReturnsNoneWhenNoMigration()

	/**
	 * Build an EncryptionSuite owned by 'testuser' for ownership-check stubs.
	 *
	 * @return EncryptionSuite
	 */
	private function makeOwnedSuite(): EncryptionSuite {
		$suite = new EncryptionSuite();
		$suite->setOwnerType('user');
		$suite->setOwnerId('testuser');
		return $suite;
	}//end makeOwnedSuite()

	/**
	 * Build a SuiteMigration with the given id and old suite reference.
	 *
	 * @param string $id Migration id
	 * @param string $oldSuiteId Id of the old suite
	 *
	 * @return SuiteMigration
	 */
	private function makeMigration(
		string $id,
		string $oldSuiteId = 'suite-1',
		string $status = 'in_progress',
	): SuiteMigration {
		$migration = new SuiteMigration();
		$migration->setId($id);
		$migration->setOldSuiteId($oldSuiteId);
		$migration->setNewSuiteId('suite-2');
		$migration->setStatus($status);
		return $migration;
	}//end makeMigration()

	/**
	 * Stub an in-progress migration owned by 'testuser'.
	 *
	 * @param string $status The migration status
	 *
	 * @return void
	 */
	private function arrangeOwnMigration(string $status = 'in_progress'): void {
		$this->migrationService->method('getMigration')
			->willReturn($this->makeMigration('migr-1', status: $status));
		$this->suiteService->method('getSuite')->willReturn($this->makeOwnedSuite());
	}//end arrangeOwnMigration()

	/**
	 * Stub a migration whose old suite belongs to somebody else.
	 *
	 * The nil UUID stands in for the foreign owner so the fixture can never be
	 * mistaken for a real account.
	 *
	 * @return void
	 */
	private function arrangeForeignMigration(): void {
		$foreign = new EncryptionSuite();
		$foreign->setOwnerType('user');
		$foreign->setOwnerId('00000000-0000-0000-0000-000000000000');

		$this->migrationService->method('getMigration')->willReturn($this->makeMigration('migr-1'));
		$this->suiteService->method('getSuite')->willReturn($foreign);
	}//end arrangeForeignMigration()

	/**
	 * Test complete delegates and returns migration.
	 *
	 * @return void
	 */
	public function testCompleteReturnsMigration(): void {
		$pending = $this->makeMigration('migr-1');
		$this->migrationService->method('getMigration')
			->with('migr-1')
			->willReturn($pending);

		$this->suiteService->method('getSuite')
			->with('suite-1')
			->willReturn($this->makeOwnedSuite());

		$completed = new SuiteMigration();
		$completed->setId('migr-1');
		$completed->setStatus('completed');

		// completeMigration returns the serialized migration plus the count of
		// version rows dropped for falling outside the re-encryption window.
		$this->migrationService->method('completeMigration')
			->with('migr-1', false)
			->willReturn($completed->jsonSerialize() + ['droppedVersions' => 0]);

		$response = $this->controller->complete('migr-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('completed', $response->getData()['status']);
		$this->assertSame(0, $response->getData()['droppedVersions']);
	}//end testCompleteReturnsMigration()

	/**
	 * Test complete with errors.
	 *
	 * @return void
	 */
	public function testCompleteWithErrors(): void {
		$pending = $this->makeMigration('migr-1');
		$this->migrationService->method('getMigration')
			->with('migr-1')
			->willReturn($pending);

		$this->suiteService->method('getSuite')
			->with('suite-1')
			->willReturn($this->makeOwnedSuite());

		$completed = new SuiteMigration();
		$completed->setId('migr-1');
		$completed->setStatus('completed_with_errors');

		$this->migrationService->method('completeMigration')
			->with('migr-1', true)
			->willReturn($completed->jsonSerialize() + ['droppedVersions' => 3]);

		$response = $this->controller->complete('migr-1', true);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('completed_with_errors', $response->getData()['status']);
	}//end testCompleteWithErrors()

	/**
	 * Abort delegates and returns the terminal result.
	 *
	 * @return void
	 */
	public function testAbortReturnsResult(): void {
		$this->arrangeOwnMigration();

		$aborted = new SuiteMigration();
		$aborted->setId('migr-1');
		$aborted->setStatus('aborted');
		$this->migrationService->method('abortMigration')
			->with('migr-1')
			->willReturn($aborted->jsonSerialize() + ['aborted' => true]);

		$response = $this->controller->abort('migr-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['aborted']);
		$this->assertSame('aborted', $response->getData()['status']);
	}//end testAbortReturnsResult()

	/**
	 * Abort after a record has moved is a 409 that reports the committed count
	 * and points at resuming, not a generic fault.
	 *
	 * @return void
	 */
	public function testAbortRefusedReturns409WithCommittedCount(): void {
		$this->arrangeOwnMigration();

		$this->migrationService->method('abortMigration')
			->willThrowException((new MigrationAbortRefusedException('records moved'))->withCommitted(2));

		$response = $this->controller->abort('migr-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('migration_abort_refused', $response->getData()['error']);
		$this->assertSame(2, $response->getData()['committed']);
	}//end testAbortRefusedReturns409WithCommittedCount()

	/**
	 * Abort refuses another user's migration and never reaches the service.
	 *
	 * @return void
	 */
	public function testAbortForbiddenForAnotherUsersMigration(): void {
		$this->arrangeForeignMigration();
		$this->migrationService->expects($this->never())->method('abortMigration');

		$response = $this->controller->abort('migr-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testAbortForbiddenForAnotherUsersMigration()

	/**
	 * A missing migration is 404 on complete, as on every sibling endpoint.
	 *
	 * It used to fall through to the generic arm and return 400, while getWork
	 * and all three re-encryption endpoints returned 404 for the same
	 * condition — so a client could not tell "no such migration" from
	 * "malformed request" on the one endpoint where that matters most.
	 *
	 * @return void
	 */
	public function testCompleteReturns404WhenTheMigrationIsMissing(): void {
		$this->migrationService->method('getMigration')
			->willThrowException(new DoesNotExistException('Migration not found'));

		$response = $this->controller->complete('nonexistent');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testCompleteReturns404WhenTheMigrationIsMissing()

	/**
	 * A 409 carries the authoritative acknowledgement number.
	 *
	 * The client must echo `requiredAcknowledgement` back rather than counting
	 * its own failure list: the two count different things (one entry per
	 * failed record per pass, versus distinct records currently failed), and
	 * because the server compared with a strict `===`, every acknowledgement
	 * derived from the client's list was refused — leaving the vault
	 * write-locked with no reachable way out.
	 *
	 * @return void
	 */
	public function testCompleteReturnsTheRequiredAcknowledgementOn409(): void {
		$this->arrangeOwnMigration();
		$this->migrationService->method('completeMigration')
			->willThrowException(
				(new MigrationIncompleteException(message: '3 record(s) could not be decrypted'))
					->withRequiredAcknowledgement(3)
			);

		$response = $this->controller->complete('migr-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('migration_incomplete', $response->getData()['error']);
		$this->assertSame(3, $response->getData()['requiredAcknowledgement']);
	}//end testCompleteReturnsTheRequiredAcknowledgementOn409()

	/**
	 * Test an incomplete migration is refused with a distinct 409.
	 *
	 * The client must be able to tell "work remains, resume it" apart from a
	 * generic fault, and must not treat it as rotation having finished.
	 *
	 * @return void
	 */
	public function testCompleteReturns409WhenMigrationIncomplete(): void {
		$this->arrangeOwnMigration();
		$this->migrationService->method('completeMigration')
			->willThrowException(new MigrationIncompleteException('3 record(s) still on the old key'));

		$response = $this->controller->complete('migr-1');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('migration_incomplete', $response->getData()['error']);
	}//end testCompleteReturns409WhenMigrationIncomplete()

	/**
	 * Test the work list is returned for the migration's owner.
	 *
	 * @return void
	 */
	public function testGetWorkReturnsOutstandingWork(): void {
		$this->arrangeOwnMigration();
		$this->workService->method('listWork')->willReturn(
			[
				'secrets' => ['records' => [['id' => 'secret-1', 'key' => 'CIPHERTEXT']], 'remaining' => 1],
				'totalRemaining' => 1,
			]
		);

		$response = $this->controller->getWork('migr-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $response->getData()['totalRemaining']);
	}//end testGetWorkReturnsOutstandingWork()

	/**
	 * Test the work list is refused for somebody else's migration.
	 *
	 * @return void
	 */
	public function testGetWorkRefusesForeignMigration(): void {
		$this->arrangeForeignMigration();
		$this->workService->expects($this->never())->method('listWork');

		$response = $this->controller->getWork('migr-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testGetWorkRefusesForeignMigration()

	/**
	 * Test each re-encryption endpoint refuses somebody else's migration.
	 *
	 * A migration id must not be a cross-user handle onto these write paths.
	 *
	 * @return void
	 */
	public function testReEncryptEndpointsRefuseForeignMigration(): void {
		$this->arrangeForeignMigration();

		$this->workService->expects($this->never())->method('commitSecret');
		$this->workService->expects($this->never())->method('commitVersion');
		$this->workService->expects($this->never())->method('commitAttachmentGrant');

		$responses = [
			$this->controller->reEncryptSecret('migr-1', 'secret-1', key: 'NEW'),
			$this->controller->reEncryptVersion('migr-1', 'version-1', key: 'NEW'),
			$this->controller->reEncryptAttachmentGrant('migr-1', 'grant-1', wrappedFileKey: 'NEW'),
		];

		foreach ($responses as $response) {
			$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		}
	}//end testReEncryptEndpointsRefuseForeignMigration()

	/**
	 * Test a per-object guard rejection is surfaced as 403.
	 *
	 * @return void
	 */
	public function testReEncryptSecretSurfacesGuardRejection(): void {
		$this->arrangeOwnMigration();
		$this->workService->method('commitSecret')
			->willThrowException(new ForbiddenException('Secret is not bound to this migration\'s old suite'));

		$response = $this->controller->reEncryptSecret('migr-1', 'secret-1', key: 'NEW');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testReEncryptSecretSurfacesGuardRejection()

	/**
	 * Test a terminated migration accepts no further re-encryptions.
	 *
	 * @return void
	 */
	public function testReEncryptRefusedOnceMigrationIsTerminal(): void {
		$this->arrangeOwnMigration(status: 'completed');
		$this->workService->expects($this->never())->method('commitSecret');

		$response = $this->controller->reEncryptSecret('migr-1', 'secret-1', key: 'NEW');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
	}//end testReEncryptRefusedOnceMigrationIsTerminal()

	/**
	 * Test a commit without ciphertext is refused rather than writing an empty
	 * value.
	 *
	 * @return void
	 */
	public function testReEncryptSecretRequiresCiphertext(): void {
		$this->arrangeOwnMigration();
		$this->workService->expects($this->never())->method('commitSecret');

		$response = $this->controller->reEncryptSecret('migr-1', 'secret-1');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testReEncryptSecretRequiresCiphertext()

	/**
	 * Test a committed secret's response carries no value, only identifiers.
	 *
	 * @return void
	 */
	public function testReEncryptSecretResponseCarriesNoValue(): void {
		$this->arrangeOwnMigration();

		$secret = new Secret();
		$secret->setId('secret-1');
		$secret->setEncryptionSuiteId('suite-2');
		$secret->setKey('NEW-KEY-CIPHERTEXT');
		$secret->setPossiblyCompromisedAt(new DateTime());

		$this->workService->method('commitSecret')->willReturn($secret);

		$response = $this->controller->reEncryptSecret('migr-1', 'secret-1', key: 'NEW-KEY-CIPHERTEXT');
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		// Confirmation only: the id, where the row now points, and the flag.
		// No blob is echoed back, so there is nothing here that could ever be a
		// decrypted value.
		$this->assertSame(['id', 'encryptionSuiteId', 'possiblyCompromisedAt'], array_keys($data));
		$this->assertSame('suite-2', $data['encryptionSuiteId']);
		$this->assertNotNull($data['possiblyCompromisedAt']);
	}//end testReEncryptSecretResponseCarriesNoValue()

	/**
	 * Test a reported failure is recorded against the owning secret.
	 *
	 * @return void
	 */
	public function testReEncryptRecordsReportedFailure(): void {
		$this->arrangeOwnMigration();

		$this->workService->expects($this->once())
			->method('recordFailure')
			->with(
				$this->anything(),
				'testuser',
				'versions',
				'version-1',
				'Re-encrypted key did not decrypt back to the original value'
			)
			->willReturn('secret-1');

		// A reported failure must not also commit anything.
		$this->workService->expects($this->never())->method('commitVersion');

		$response = $this->controller->reEncryptVersion(
			'migr-1',
			'version-1',
			error: 'Re-encrypted key did not decrypt back to the original value'
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['recorded']);
		$this->assertSame('secret-1', $response->getData()['secretId']);
	}//end testReEncryptRecordsReportedFailure()

	/**
	 * Test a missing record is reported as 404 rather than 403.
	 *
	 * @return void
	 */
	public function testReEncryptGrantReportsMissingRecord(): void {
		$this->arrangeOwnMigration();
		$this->workService->method('commitAttachmentGrant')
			->willThrowException(new NotFoundException('Attachment grant not found'));

		$response = $this->controller->reEncryptAttachmentGrant(
			'migr-1',
			'grant-1',
			wrappedFileKey: 'NEW'
		);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testReEncryptGrantReportsMissingRecord()

	/**
	 * A re-point delegates to the envelope service with the migration's owner and
	 * suite ids resolved from the guarded migration, and echoes the result.
	 *
	 * @return void
	 */
	public function testReEnvelopeEmergencyContactSucceeds(): void {
		$this->arrangeOwnMigration();

		$repointed = new EmergencyContact();
		$repointed->setId('rel-1');
		$repointed->setGrantorSuiteId('suite-2');
		$repointed->setState(EmergencyContact::STATE_GRANTED);

		$this->envelopeService->expects($this->once())
			->method('reEnvelopeForRotation')
			->with('testuser', 'suite-1', 'suite-2', 'rel-1', 'ENV', 'grantee-suite')
			->willReturn($repointed);

		$response = $this->controller->reEnvelopeEmergencyContact(
			'migr-1',
			'rel-1',
			recoveryEnvelope: 'ENV',
			granteeSuiteId: 'grantee-suite'
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('rel-1', $response->getData()['id']);
		$this->assertSame('suite-2', $response->getData()['grantorSuiteId']);
		$this->assertSame(EmergencyContact::STATE_GRANTED, $response->getData()['state']);
	}//end testReEnvelopeEmergencyContactSucceeds()

	/**
	 * A re-point missing the envelope or grantee suite is a 400 and never reaches
	 * the service.
	 *
	 * @return void
	 */
	public function testReEnvelopeEmergencyContactRequiresEnvelopeAndSuite(): void {
		$this->envelopeService->expects($this->never())->method('reEnvelopeForRotation');

		$response = $this->controller->reEnvelopeEmergencyContact('migr-1', 'rel-1', recoveryEnvelope: 'ENV');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testReEnvelopeEmergencyContactRequiresEnvelopeAndSuite()

	/**
	 * A re-point against a terminated migration is a 409 and never reaches the
	 * service — the completion sweep has already run.
	 *
	 * @return void
	 */
	public function testReEnvelopeEmergencyContactRefusesTerminatedMigration(): void {
		$this->arrangeOwnMigration(status: 'completed');
		$this->envelopeService->expects($this->never())->method('reEnvelopeForRotation');

		$response = $this->controller->reEnvelopeEmergencyContact(
			'migr-1',
			'rel-1',
			recoveryEnvelope: 'ENV',
			granteeSuiteId: 'grantee-suite'
		);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
	}//end testReEnvelopeEmergencyContactRefusesTerminatedMigration()

	/**
	 * A foreign contact surfaces as 403.
	 *
	 * @return void
	 */
	public function testReEnvelopeEmergencyContactMapsForbiddenTo403(): void {
		$this->arrangeOwnMigration();
		$this->envelopeService->method('reEnvelopeForRotation')
			->willThrowException(new ForbiddenException('Emergency contact does not belong to you'));

		$response = $this->controller->reEnvelopeEmergencyContact(
			'migr-1',
			'rel-1',
			recoveryEnvelope: 'ENV',
			granteeSuiteId: 'grantee-suite'
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}//end testReEnvelopeEmergencyContactMapsForbiddenTo403()

	/**
	 * A missing contact surfaces as 404.
	 *
	 * @return void
	 */
	public function testReEnvelopeEmergencyContactMapsNotFoundTo404(): void {
		$this->arrangeOwnMigration();
		$this->envelopeService->method('reEnvelopeForRotation')
			->willThrowException(new NotFoundException('Emergency contact not found'));

		$response = $this->controller->reEnvelopeEmergencyContact(
			'migr-1',
			'rel-1',
			recoveryEnvelope: 'ENV',
			granteeSuiteId: 'grantee-suite'
		);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testReEnvelopeEmergencyContactMapsNotFoundTo404()

	/**
	 * A malformed or misaddressed envelope surfaces as 400.
	 *
	 * @return void
	 */
	public function testReEnvelopeEmergencyContactMapsInvalidArgumentTo400(): void {
		$this->arrangeOwnMigration();
		$this->envelopeService->method('reEnvelopeForRotation')
			->willThrowException(new InvalidArgumentException('Recovery envelope is not valid JSON'));

		$response = $this->controller->reEnvelopeEmergencyContact(
			'migr-1',
			'rel-1',
			recoveryEnvelope: 'ENV',
			granteeSuiteId: 'grantee-suite'
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testReEnvelopeEmergencyContactMapsInvalidArgumentTo400()
}//end class
