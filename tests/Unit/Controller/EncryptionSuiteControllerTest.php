<?php

/**
 * Unit tests for EncryptionSuiteController.
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

use InvalidArgumentException;
use OCA\Keepiq\Controller\EncryptionSuiteController;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\SuiteMigration;
use OCA\Keepiq\Exception\ConflictException;
use OCA\Keepiq\Service\EncryptionSuiteService;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\EmergencyEnvelopeInvalidationService;
use OCA\Keepiq\Service\VaultKeyProofService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for EncryptionSuiteController.
 */
class EncryptionSuiteControllerTest extends TestCase {

	/**
	 * The controller under test.
	 *
	 * @var EncryptionSuiteController
	 */
	private EncryptionSuiteController $controller;

	/**
	 * The mocked suite service.
	 *
	 * @var EncryptionSuiteService&MockObject
	 */
	private EncryptionSuiteService&MockObject $suiteService;

	/**
	 * The mocked migration service.
	 *
	 * @var MigrationService&MockObject
	 */
	private MigrationService&MockObject $migrationService;

	/**
	 * The mocked vault-key-proof service.
	 *
	 * @var VaultKeyProofService&MockObject
	 */
	private VaultKeyProofService&MockObject $proofService;

	/**
	 * @var EmergencyEnvelopeInvalidationService&MockObject
	 */
	private EmergencyEnvelopeInvalidationService&MockObject $emergencyService;

	/**
	 * The mocked user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$request = $this->createMock(originalClassName: IRequest::class);
		$this->suiteService = $this->createMock(originalClassName: EncryptionSuiteService::class);
		$this->migrationService = $this->createMock(originalClassName: MigrationService::class);
		$this->userSession = $this->createMock(originalClassName: IUserSession::class);
		$this->proofService = $this->createMock(originalClassName: VaultKeyProofService::class);
		$this->emergencyService = $this->createMock(originalClassName: EmergencyEnvelopeInvalidationService::class);

		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('testuser');
		$this->userSession->method('getUser')->willReturn($user);

		$this->controller = new EncryptionSuiteController(
			request: $request,
			suiteService: $this->suiteService,
			migrationService: $this->migrationService,
			userSession: $this->userSession,
			proofService: $this->proofService,
			emergencyService: $this->emergencyService,
		);
	}//end setUp()

	/**
	 * Test index returns the current user's suites.
	 *
	 * @return void
	 */
	public function testIndexReturnsSuites(): void {
		$suite1 = new EncryptionSuite();
		$suite1->setId('suite-1');
		$suite1->setOwnerType('user');
		$suite1->setOwnerId('testuser');
		$suite1->setStatus('active');

		$suite2 = new EncryptionSuite();
		$suite2->setId('suite-2');
		$suite2->setOwnerType('user');
		$suite2->setOwnerId('testuser');
		$suite2->setStatus('revoked');

		$this->suiteService->method('getSuitesByOwner')
			->with('user', 'testuser')
			->willReturn([$suite1, $suite2]);

		$response = $this->controller->index();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$data = $response->getData();
		$this->assertCount(expectedCount: 2, haystack: $data);
		$this->assertSame(expected: 'suite-1', actual: $data[0]['id']);
		$this->assertSame(expected: 'suite-2', actual: $data[1]['id']);
	}//end testIndexReturnsSuites()

	/**
	 * Test show returns a suite.
	 *
	 * @return void
	 */
	public function testShowReturnsSuite(): void {
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setOwnerType('user');
		$suite->setOwnerId('testuser');

		$this->suiteService->method('getSuite')
			->with('suite-1')
			->willReturn($suite);

		$response = $this->controller->show('suite-1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: 'suite-1', actual: $response->getData()['id']);
	}//end testShowReturnsSuite()

	/**
	 * Test show returns 404 when suite not found.
	 *
	 * @return void
	 */
	public function testShowReturns404WhenNotFound(): void {
		$this->suiteService->method('getSuite')
			->willThrowException(new DoesNotExistException('Not found'));

		$response = $this->controller->show('nonexistent');

		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
	}//end testShowReturns404WhenNotFound()

	/**
	 * Test show returns 404 when suite belongs to another user.
	 *
	 * @return void
	 */
	public function testShowReturns404ForOtherUsersSuite(): void {
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setOwnerType('user');
		$suite->setOwnerId('otheruser');

		$this->suiteService->method('getSuite')
			->with('suite-1')
			->willReturn($suite);

		$response = $this->controller->show('suite-1');

		// ValidateOwnership throws RuntimeException caught as a generic Exception -> NOT_FOUND.
		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
		$this->assertArrayHasKey(key: 'message', array: $response->getData());
		$this->assertStringContainsString(needle: 'Access denied', haystack: $response->getData()['message']);
	}//end testShowReturns404ForOtherUsersSuite()

	/**
	 * Test create returns 201 on success.
	 *
	 * @return void
	 */
	public function testCreateReturns201OnSuccess(): void {
		$suite = new EncryptionSuite();
		$suite->setId('new-suite');
		$suite->setOwnerType('user');
		$suite->setOwnerId('testuser');
		$suite->setStatus('active');

		$this->suiteService->method('createSuite')
			->with('user', 'testuser', 'pub-key-pem', 'encrypted-pk')
			->willReturn($suite);

		$response = $this->controller->create('pub-key-pem', 'encrypted-pk');

		$this->assertSame(expected: Http::STATUS_CREATED, actual: $response->getStatus());
		$this->assertSame(expected: 'new-suite', actual: $response->getData()['id']);
	}//end testCreateReturns201OnSuccess()

	/**
	 * Test create returns 503 when CA is degraded.
	 *
	 * @return void
	 */
	public function testCreateReturns503WhenCaDegraded(): void {
		$this->suiteService->method('createSuite')
			->willThrowException(new RuntimeException('CA is not healthy'));

		$response = $this->controller->create('pub-key', 'encrypted-pk');

		$this->assertSame(expected: Http::STATUS_SERVICE_UNAVAILABLE, actual: $response->getStatus());
		$this->assertArrayHasKey(key: 'message', array: $response->getData());
	}//end testCreateReturns503WhenCaDegraded()

	/**
	 * A duplicate-suite refusal is a 409, not the 503 its parent class would give.
	 *
	 * Issue #289. `ConflictException` extends `RuntimeException`, and this controller
	 * already maps `RuntimeException` to 503 — so if the catch arms are ever reordered,
	 * or the new arm removed, a duplicate create silently starts reporting "service
	 * unavailable". That tells the client to retry something it must never retry, and
	 * tells the operator a server is unhealthy when nothing is. The status code is the
	 * whole contract here, so it is pinned separately from the service-level test.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-plain-create-refuses-to-mint-a-second-active-suite
	 */
	public function testCreateReturns409WhenAnActiveSuiteAlreadyExists(): void {
		$this->suiteService->method('createSuite')
			->willThrowException(new ConflictException('An active EncryptionSuite already exists for this owner.'));

		$response = $this->controller->create('pub-key', 'encrypted-pk');

		$this->assertSame(expected: Http::STATUS_CONFLICT, actual: $response->getStatus());
		$this->assertSame(
			expected: 'suite_already_exists',
			actual: $response->getData()['error'],
			message: 'the client needs a machine-readable reason, not only English prose'
		);
	}//end testCreateReturns409WhenAnActiveSuiteAlreadyExists()

	/**
	 * A plain create goes through the GUARDED entry point.
	 *
	 * Pinned by method choice: if this call site ever moved to createSuccessorSuite
	 * the guard would simply be off for the one path it exists to protect, and
	 * nothing else would look wrong.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-plain-create-refuses-to-mint-a-second-active-suite
	 */
	public function testCreateUsesTheGuardedEntryPoint(): void {
		$suite = new EncryptionSuite();
		$suite->setId('suite-new');
		$suite->setOwnerType('user');
		$suite->setOwnerId('testuser');
		$suite->setStatus('active');

		$this->suiteService->expects($this->once())->method('createSuite')->willReturn($suite);
		$this->suiteService->expects($this->never())->method('createSuccessorSuite');

		$this->controller->create('pub-key', 'encrypted-pk');
	}//end testCreateUsesTheGuardedEntryPoint()

	/**
	 * Compromise recovery uses the SUCCESSOR entry point, not the guarded one.
	 *
	 * The more important half of the pair: routing recovery through the guarded
	 * createSuite() would refuse key rotation for anyone who has a suite — which is
	 * everyone who could need it — and nothing else would look wrong. Found by
	 * mutation: the earlier flag-based version of this call failed no test at all
	 * until this test existed.
	 *
	 * The old suite must stay active for the whole migration, so this path legitimately
	 * produces two active suites; see "Suite Resolution Is Deterministic During A
	 * Migration".
	 *
	 * @return void
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-a-plain-create-refuses-to-mint-a-second-active-suite
	 */
	public function testCompromiseRecoveryUsesTheSuccessorEntryPoint(): void {
		$oldSuite = new EncryptionSuite();
		$oldSuite->setId('old-suite');
		$oldSuite->setPrivateKey('old-encrypted-pk');

		$newSuite = new EncryptionSuite();
		$newSuite->setId('new-suite');
		$newSuite->setStatus('active');

		$migration = new SuiteMigration();
		$migration->setId('migr-1');
		$migration->setOldSuiteId('old-suite');
		$migration->setNewSuiteId('new-suite');
		$migration->setStatus('in_progress');

		$this->suiteService->method('getActiveSuite')->willReturn($oldSuite);
		$this->migrationService->method('initiateCompromiseRecovery')->willReturn($migration);

		$this->suiteService->expects($this->once())
			->method('createSuccessorSuite')
			->willReturn($newSuite);
		$this->suiteService->expects($this->never())->method('createSuite');

		$response = $this->controller->compromiseRecovery('pub-key', 'encrypted-pk');

		$this->assertSame(expected: Http::STATUS_CREATED, actual: $response->getStatus());
	}//end testCompromiseRecoveryUsesTheSuccessorEntryPoint()

	/**
	 * Test updatePrivateKey returns updated suite.
	 *
	 * @return void
	 */
	public function testUpdatePrivateKeyReturnsSuite(): void {
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setOwnerType('user');
		$suite->setOwnerId('testuser');
		$suite->setPrivateKey('old-pk');

		$this->suiteService->method('getSuite')
			->with('suite-1')
			->willReturn($suite);

		$response = $this->controller->updatePrivateKey('suite-1', 'new-encrypted-pk');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: 'new-encrypted-pk', actual: $response->getData()['privateKey']);
	}//end testUpdatePrivateKeyReturnsSuite()

	/**
	 * Test revoke returns revoked suite.
	 *
	 * @return void
	 */
	public function testRevokeReturnsSuite(): void {
		$owned = new EncryptionSuite();
		$owned->setId('suite-1');
		$owned->setOwnerType('user');
		$owned->setOwnerId('testuser');

		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setStatus('revoked');

		$this->suiteService->method('getSuite')
			->with('suite-1')
			->willReturn($owned);
		$this->suiteService->method('revokeSuite')
			->with('suite-1', 'security concern', 'testuser')
			->willReturn($suite);

		$response = $this->controller->revoke('suite-1', 'security concern');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: 'revoked', actual: $response->getData()['status']);
	}//end testRevokeReturnsSuite()

	/**
	 * Test revoke returns 400 for already compromised suite.
	 *
	 * @return void
	 */
	public function testRevokeReturns400ForCompromisedSuite(): void {
		$owned = new EncryptionSuite();
		$owned->setId('suite-1');
		$owned->setOwnerType('user');
		$owned->setOwnerId('testuser');

		$this->suiteService->method('getSuite')->willReturn($owned);
		$this->suiteService->method('revokeSuite')
			->willThrowException(new InvalidArgumentException('Cannot revoke a compromised suite'));

		$response = $this->controller->revoke('suite-1', 'test');

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
	}//end testRevokeReturns400ForCompromisedSuite()

	/**
	 * Revoke must refuse a suite owned by ANOTHER user, and must not reach the
	 * service at all.
	 *
	 * The `$user === null` preamble is an AUTHENTICATION check. Before this
	 * test existed, any authenticated user could revoke any suite by id, and
	 * revocation cascades a hard delete of the owner's ShareTargets plus a
	 * permanent promotion of their delegations.
	 *
	 * @return void
	 */
	public function testRevokeRefusesAnotherUsersSuiteAndNeverCallsTheService(): void {
		$foreign = new EncryptionSuite();
		$foreign->setId('suite-1');
		$foreign->setOwnerType('user');
		$foreign->setOwnerId('victim');
		$foreign->setStatus('active');

		$this->suiteService->method('getSuite')
			->with('suite-1')
			->willReturn($foreign);
		$this->suiteService->expects($this->never())->method('revokeSuite');

		$response = $this->controller->revoke('suite-1', 'security concern');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		$this->assertStringContainsString(
			needle: 'Access denied',
			haystack: $response->getData()['message']
		);
	}//end testRevokeRefusesAnotherUsersSuiteAndNeverCallsTheService()

	/**
	 * Build an owned, active suite for the revoke-safeguard tests.
	 *
	 * @return EncryptionSuite
	 */
	private function ownedActiveSuite(): EncryptionSuite {
		$owned = new EncryptionSuite();
		$owned->setId('suite-1');
		$owned->setOwnerType('user');
		$owned->setOwnerId('testuser');
		$owned->setStatus('active');
		return $owned;
	}//end ownedActiveSuite()

	/**
	 * While a usable emergency contact exists, revocation without the override is
	 * refused with the COUNT — never the identities — and never reaches the
	 * destructive clear.
	 *
	 * @return void
	 */
	public function testRevokeRefusedWhileUsableEmergencyContactExists(): void {
		$this->suiteService->method('getSuite')->willReturn($this->ownedActiveSuite());
		$this->emergencyService->method('countUsableForGrantorSuite')->with('suite-1')->willReturn(2);
		$this->suiteService->expects($this->never())->method('revokeSuite');

		$response = $this->controller->revoke('suite-1', 'lost password');

		$this->assertSame(expected: Http::STATUS_CONFLICT, actual: $response->getStatus());
		$data = $response->getData();
		$this->assertSame('emergency_access_present', $data['error']);
		$this->assertSame(2, $data['usableEmergencyContacts']);
		// The count is surfaced; the contacts' identities are not.
		$this->assertArrayNotHasKey('granteeUserId', $data);
		$this->assertArrayNotHasKey('contacts', $data);
	}//end testRevokeRefusedWhileUsableEmergencyContactExists()

	/**
	 * With the explicit override, revocation proceeds and reaches the service
	 * even though a usable emergency contact exists.
	 *
	 * @return void
	 */
	public function testRevokeProceedsWithOverrideDespiteEmergencyContact(): void {
		$revoked = new EncryptionSuite();
		$revoked->setId('suite-1');
		$revoked->setStatus('revoked');

		$this->suiteService->method('getSuite')->willReturn($this->ownedActiveSuite());
		$this->emergencyService->method('countUsableForGrantorSuite')->willReturn(2);
		$this->suiteService->expects($this->once())
			->method('revokeSuite')
			->with('suite-1', 'lost password', 'testuser')
			->willReturn($revoked);

		$response = $this->controller->revoke('suite-1', 'lost password', acceptEmergencyLoss: true);

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: 'revoked', actual: $response->getData()['status']);
	}//end testRevokeProceedsWithOverrideDespiteEmergencyContact()

	/**
	 * With no usable emergency contact the safeguard is inert and revocation
	 * proceeds unchanged, without an override.
	 *
	 * @return void
	 */
	public function testRevokeProceedsWhenNoUsableEmergencyContact(): void {
		$revoked = new EncryptionSuite();
		$revoked->setId('suite-1');
		$revoked->setStatus('revoked');

		$this->suiteService->method('getSuite')->willReturn($this->ownedActiveSuite());
		$this->emergencyService->method('countUsableForGrantorSuite')->willReturn(0);
		$this->suiteService->expects($this->once())->method('revokeSuite')->willReturn($revoked);

		$response = $this->controller->revoke('suite-1', 'housekeeping');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
	}//end testRevokeProceedsWhenNoUsableEmergencyContact()

	/**
	 * Test reinstate returns reinstated suite.
	 *
	 * @return void
	 */
	public function testReinstateReturnsSuite(): void {
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setStatus('active');

		$this->suiteService->method('reinstateSuite')
			->with('suite-1', 'testuser')
			->willReturn($suite);

		$response = $this->controller->reinstate('suite-1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: 'active', actual: $response->getData()['status']);
	}//end testReinstateReturnsSuite()

	/**
	 * Test reinstate returns 400 when suite is not revoked.
	 *
	 * @return void
	 */
	public function testReinstateReturns400WhenNotRevoked(): void {
		$this->suiteService->method('reinstateSuite')
			->willThrowException(new InvalidArgumentException('Only revoked suites can be reinstated'));

		$response = $this->controller->reinstate('suite-1');

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
	}//end testReinstateReturns400WhenNotRevoked()

	/**
	 * Test compromiseRecovery creates new suite and migration.
	 *
	 * @return void
	 */
	public function testCompromiseRecoverySuccess(): void {
		$oldSuite = new EncryptionSuite();
		$oldSuite->setId('old-suite');
		$oldSuite->setPrivateKey('old-encrypted-pk');

		$newSuite = new EncryptionSuite();
		$newSuite->setId('new-suite');
		$newSuite->setStatus('active');

		$migration = new SuiteMigration();
		$migration->setId('migr-1');
		$migration->setOldSuiteId('old-suite');
		$migration->setNewSuiteId('new-suite');
		$migration->setStatus('in_progress');

		$this->suiteService->method('getActiveSuite')
			->with('user', 'testuser')
			->willReturn($oldSuite);
		$this->suiteService->method('createSuccessorSuite')
			->willReturn($newSuite);
		$this->migrationService->method('initiateCompromiseRecovery')
			->with('old-suite', 'new-suite')
			->willReturn($migration);

		$response = $this->controller->compromiseRecovery('pub-key', 'encrypted-pk');

		$this->assertSame(expected: Http::STATUS_CREATED, actual: $response->getStatus());
		$data = $response->getData();
		$this->assertSame(expected: 'new-suite', actual: $data['newSuite']['id']);
		$this->assertSame(expected: 'migr-1', actual: $data['migration']['id']);
		$this->assertSame(expected: 'old-encrypted-pk', actual: $data['oldEncryptedPrivateKey']);
	}//end testCompromiseRecoverySuccess()

	/**
	 * Test a second rotation is refused while one is still in progress.
	 *
	 * Without this guard, rotation 2 starts B→C while A→B is still open, and
	 * whatever was still on A becomes unreachable by any resume: resuming only
	 * ever walks its own migration's suite pair, so nothing will ever ask for
	 * the master password that opens A. The refusal must happen before any
	 * suite is created — a third suite is the damage.
	 *
	 * @return void
	 */
	public function testCompromiseRecoveryRefusedWhileMigrationInProgress(): void {
		$this->migrationService->method('isWriteLocked')
			->with('user', 'testuser')
			->willReturn(true);

		// Nothing may be created and no migration started.
		$this->suiteService->expects($this->never())->method('createSuite');
		$this->migrationService->expects($this->never())->method('initiateCompromiseRecovery');

		$response = $this->controller->compromiseRecovery('pub-key', 'encrypted-pk');

		$this->assertSame(expected: Http::STATUS_CONFLICT, actual: $response->getStatus());
		$this->assertSame(expected: 'migration_in_progress', actual: $response->getData()['error']);
		// The message must point the user at the way out, not just say "no".
		$this->assertStringContainsString('Resume or abort', $response->getData()['message']);
	}//end testCompromiseRecoveryRefusedWhileMigrationInProgress()

	/**
	 * Test compromiseRecovery returns 500 on failure.
	 *
	 * @return void
	 */
	public function testCompromiseRecoveryReturns500OnFailure(): void {
		$this->suiteService->method('getActiveSuite')
			->willThrowException(new DoesNotExistException('No active suite'));

		$response = $this->controller->compromiseRecovery('pub-key', 'encrypted-pk');

		$this->assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $response->getStatus());
	}//end testCompromiseRecoveryReturns500OnFailure()

	/**
	 * Test a certificate that does not carry the submitted key aborts with a
	 * DISTINCT error, not a generic fault.
	 *
	 * The distinction is the point: nothing was written, so the user's vault is
	 * provably untouched and retrying is safe — whereas a 500 tells them nothing
	 * and invites a support ticket. If this ever regressed to a generic error the
	 * user would be told their vault might be damaged when it is not.
	 *
	 * This also pins a coupling that is easy to break from a distance. The
	 * controller recognises the condition by matching a SUBSTRING of the message
	 * CertificateIssuanceService throws. Rewording that message upstream — which
	 * has already happened once, when the signing body was extracted out of
	 * CertificateAuthorityService — silently downgrades this to a 500 with no
	 * test failing anywhere. Asserting it here makes that rewording loud.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-refuses-to-start-on-an-unusable-new-suite
	 */
	public function testCompromiseRecoveryReportsCertificateKeyMismatchDistinctly(): void {
		$oldSuite = new EncryptionSuite();
		$oldSuite->setId('old-suite');

		$this->suiteService->method('getActiveSuite')->willReturn($oldSuite);

		// Verbatim from CertificateIssuanceService, which throws when the issued
		// certificate does not carry the key that was submitted for it.
		$this->suiteService->method('createSuccessorSuite')->willThrowException(
			new \RuntimeException(
				'Refusing to issue a certificate that does not carry the submitted public key'
			)
		);

		// The vault must be left alone: no migration, so no write lock either.
		$this->migrationService->expects($this->never())->method('initiateCompromiseRecovery');

		$response = $this->controller->compromiseRecovery('pub-key', 'encrypted-pk');
		$data = $response->getData();

		$this->assertSame(expected: Http::STATUS_CONFLICT, actual: $response->getStatus());
		$this->assertSame(expected: 'certificate_key_mismatch', actual: $data['error']);
		// And the message must tell the user their data is safe to retry.
		$this->assertStringContainsString('vault is unchanged', $data['message']);
	}//end testCompromiseRecoveryReportsCertificateKeyMismatchDistinctly()

	/**
	 * Test compromiseRecovery defers all terminal work to migration completion.
	 *
	 * Every outstanding link share signed against the now-compromised public
	 * key must be invalidated so a holder cannot decrypt the snapshot after
	 * the user reports the breach. The cascade fires *after* markCompromised
	 * (so the old suite is already locked) and *before* createSuite (so the
	 * new suite never receives leaked references).
	 *
	 * @return void
	 *
	 * @spec openspec/changes/implement-link-sharing/tasks.md#5.2
	 */
	public function testCompromiseRecoveryLeavesTerminalWorkToCompletion(): void {
		$oldSuite = new EncryptionSuite();
		$oldSuite->setId('old-suite');
		$oldSuite->setPrivateKey('old-encrypted-pk');

		$newSuite = new EncryptionSuite();
		$newSuite->setId('new-suite');
		$newSuite->setStatus('active');

		$migration = new SuiteMigration();
		$migration->setId('migr-1');
		$migration->setOldSuiteId('old-suite');
		$migration->setNewSuiteId('new-suite');
		$migration->setStatus('in_progress');

		$this->suiteService->method('getActiveSuite')
			->with('user', 'testuser')
			->willReturn($oldSuite);
		$this->suiteService->method('createSuccessorSuite')
			->willReturn($newSuite);
		$this->migrationService->method('initiateCompromiseRecovery')
			->willReturn($migration);

		// The controller must NOT perform any terminal work. Marking the old
		// suite compromised (or revoking its link shares) before the migration
		// has run is what locked the user out of their whole vault: every read
		// then threw SuiteBlockedException, including the reads the migration
		// itself depends on. All of it now happens in
		// MigrationService::completeMigration once every store is migrated.
		$this->suiteService->expects($this->never())->method('markCompromised');

		$response = $this->controller->compromiseRecovery('pub-key', 'encrypted-pk');

		$this->assertSame(expected: Http::STATUS_CREATED, actual: $response->getStatus());
	}//end testCompromiseRecoveryLeavesTerminalWorkToCompletion()

	/**
	 * proofChallenge issues a challenge for a valid purpose on an owned suite.
	 *
	 * @return void
	 */
	public function testProofChallengeIssuesForAValidPurpose(): void {
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suite->setOwnerType('user');
		$suite->setOwnerId('testuser');
		$this->suiteService->method('getSuite')->with('suite-1')->willReturn($suite);

		$this->proofService->method('issueChallenge')
			->with('testuser', VaultKeyProofService::PURPOSE_COMPROMISE_RECOVERY)
			->willReturn(['nonce' => 'n.mac', 'expiresAt' => 123]);

		$response = $this->controller->proofChallenge('suite-1', VaultKeyProofService::PURPOSE_COMPROMISE_RECOVERY);

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(expected: 'n.mac', actual: $response->getData()['nonce']);
	}//end testProofChallengeIssuesForAValidPurpose()

	/**
	 * proofChallenge rejects a missing or unknown purpose with 400 and never
	 * issues a challenge.
	 *
	 * @return void
	 */
	public function testProofChallengeRejectsAnUnknownPurpose(): void {
		$this->proofService->expects($this->never())->method('issueChallenge');

		$response = $this->controller->proofChallenge('suite-1', 'not-a-real-purpose');

		$this->assertSame(expected: Http::STATUS_BAD_REQUEST, actual: $response->getStatus());
	}//end testProofChallengeRejectsAnUnknownPurpose()

	/**
	 * proofChallenge refuses to issue against a suite the caller does not own.
	 *
	 * @return void
	 */
	public function testProofChallengeRefusesAForeignSuite(): void {
		$foreign = new EncryptionSuite();
		$foreign->setId('suite-1');
		$foreign->setOwnerType('user');
		$foreign->setOwnerId('someoneelse');
		$this->suiteService->method('getSuite')->with('suite-1')->willReturn($foreign);
		$this->proofService->expects($this->never())->method('issueChallenge');

		$response = $this->controller->proofChallenge('suite-1', VaultKeyProofService::PURPOSE_UPDATE_PRIVATE_KEY);

		$this->assertSame(expected: Http::STATUS_NOT_FOUND, actual: $response->getStatus());
	}//end testProofChallengeRefusesAForeignSuite()
}//end class
