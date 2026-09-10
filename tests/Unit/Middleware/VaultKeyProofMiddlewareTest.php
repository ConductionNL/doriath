<?php

/**
 * Unit tests for VaultKeyProofMiddleware.
 *
 * Covers attribute dispatch (absent -> pass-through, present -> verify),
 * subject resolution (active vs routeParam, foreign suite refused), and the
 * afterException mapping (guard exception -> 403 key_proof_required; foreign
 * exception re-thrown). The signature crypto itself lives in
 * VaultKeyProofServiceTest; here the service is mocked.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Middleware
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

namespace OCA\Keepiq\Tests\Unit\Middleware;

use OCA\Keepiq\Attribute\VaultKeyProofRequired;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\SuiteMigration;
use OCA\Keepiq\Db\SuiteMigrationMapper;
use OCA\Keepiq\Exception\KeyProofRequiredException;
use OCA\Keepiq\Middleware\VaultKeyProofMiddleware;
use OCA\Keepiq\Service\EncryptionSuiteService;
use OCA\Keepiq\Service\VaultKeyProofService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A controller fixture exposing guarded and unguarded methods.
 */
class GuardFixtureController extends Controller {
	#[VaultKeyProofRequired(binds: ['encryptedPrivateKey'], subject: 'active', purpose: 'compromise-recovery')]
	public function guardedActive(): void {
	}

	#[VaultKeyProofRequired(subject: 'routeParam:id', purpose: 'update-private-key')]
	public function guardedRouteParam(): void {
	}

	#[VaultKeyProofRequired(binds: ['id'], subject: 'migrationOldSuite', purpose: 'complete-migration')]
	public function guardedMigrationOldSuite(): void {
	}

	public function unguarded(): void {
	}
}//end class

/**
 * Tests for VaultKeyProofMiddleware.
 */
class VaultKeyProofMiddlewareTest extends TestCase {
	private IRequest $request;
	private IUserSession $userSession;
	private EncryptionSuiteService $suiteService;
	private VaultKeyProofService $proofService;
	private SuiteMigrationMapper $migrationMapper;
	private VaultKeyProofMiddleware $middleware;
	private GuardFixtureController $controller;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->suiteService = $this->createMock(EncryptionSuiteService::class);
		$this->proofService = $this->createMock(VaultKeyProofService::class);
		$this->migrationMapper = $this->createMock(SuiteMigrationMapper::class);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$this->middleware = new VaultKeyProofMiddleware(
			request: $this->request,
			userSession: $this->userSession,
			suiteService: $this->suiteService,
			proofService: $this->proofService,
			migrationMapper: $this->migrationMapper,
		);

		$this->controller = new GuardFixtureController('keepiq', $this->request);
	}//end setUp()

	public function testAnUnguardedMethodIsPassedThrough(): void {
		$this->proofService->expects($this->never())->method('verify');
		$this->middleware->beforeController($this->controller, 'unguarded');
		$this->addToAssertionCount(1);
	}//end testAnUnguardedMethodIsPassedThrough()

	public function testActiveSubjectResolvesAndTheProofIsVerifiedWithTheBoundValues(): void {
		$this->suiteService->method('getActiveSuite')
			->with('user', 'alice')
			->willReturn($this->suiteWithCertificate('CERT-PEM'));

		$this->request->method('getHeader')->willReturnMap([
			['X-Keepiq-Key-Proof-Nonce', 'the-nonce'],
			['X-Keepiq-Key-Proof', 'the-sig'],
		]);
		$this->request->method('getParam')->willReturnMap([
			['encryptedPrivateKey', '', 'ENVELOPE'],
		]);

		$this->proofService->expects($this->once())->method('verify')
			->with(
				'the-nonce',
				'the-sig',
				'CERT-PEM',
				'alice',
				'compromise-recovery',
				['ENVELOPE'],
			);

		$this->middleware->beforeController($this->controller, 'guardedActive');
	}//end testActiveSubjectResolvesAndTheProofIsVerifiedWithTheBoundValues()

	public function testMigrationOldSuiteSubjectVerifiesAgainstTheOldSuiteKey(): void {
		$migration = new SuiteMigration();
		$migration->setOldSuiteId('old-suite');
		$this->migrationMapper->method('findById')->with('migr-1')->willReturn($migration);
		$this->suiteService->method('getSuite')
			->with('old-suite')
			->willReturn($this->suiteWithCertificate('OLD-CERT'));

		$this->request->method('getHeader')->willReturnMap([
			['X-Keepiq-Key-Proof-Nonce', 'the-nonce'],
			['X-Keepiq-Key-Proof', 'the-sig'],
		]);
		$this->request->method('getParam')->willReturnMap([['id', '', 'migr-1']]);

		$this->proofService->expects($this->once())->method('verify')
			->with('the-nonce', 'the-sig', 'OLD-CERT', 'alice', 'complete-migration', ['migr-1']);

		$this->middleware->beforeController($this->controller, 'guardedMigrationOldSuite');
	}//end testMigrationOldSuiteSubjectVerifiesAgainstTheOldSuiteKey()

	public function testRouteParamSubjectRefusesAForeignSuite(): void {
		$foreign = $this->suiteWithCertificate('CERT-PEM');
		$foreign->setOwnerType('user');
		$foreign->setOwnerId('bob');
		$this->suiteService->method('getSuite')->willReturn($foreign);
		$this->request->method('getParam')->willReturnMap([['id', '', 'suite-x']]);

		$this->proofService->expects($this->never())->method('verify');
		$this->expectException(KeyProofRequiredException::class);
		$this->middleware->beforeController($this->controller, 'guardedRouteParam');
	}//end testRouteParamSubjectRefusesAForeignSuite()

	public function testAFailedProofPropagatesAsTheGuardException(): void {
		$this->suiteService->method('getActiveSuite')->willReturn($this->suiteWithCertificate('CERT-PEM'));
		$this->request->method('getHeader')->willReturn('');
		$this->request->method('getParam')->willReturn('');
		$this->proofService->method('verify')
			->willThrowException(new KeyProofRequiredException('nope'));

		$this->expectException(KeyProofRequiredException::class);
		$this->middleware->beforeController($this->controller, 'guardedActive');
	}//end testAFailedProofPropagatesAsTheGuardException()

	public function testAfterExceptionMapsTheGuardExceptionTo403(): void {
		$response = $this->middleware->afterException(
			$this->controller,
			'guardedActive',
			new KeyProofRequiredException('need a proof')
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame('key_proof_required', $response->getData()['error']);
	}//end testAfterExceptionMapsTheGuardExceptionTo403()

	public function testAfterExceptionRethrowsAForeignException(): void {
		$this->expectException(RuntimeException::class);
		$this->middleware->afterException(
			$this->controller,
			'guardedActive',
			new RuntimeException('something else')
		);
	}//end testAfterExceptionRethrowsAForeignException()

	/**
	 * A user-owned suite carrying the given certificate.
	 *
	 * @param string $certificate The certificate PEM
	 *
	 * @return EncryptionSuite
	 */
	private function suiteWithCertificate(string $certificate): EncryptionSuite {
		$suite = new EncryptionSuite();
		$suite->setId('suite-alice');
		$suite->setOwnerType('user');
		$suite->setOwnerId('alice');
		$suite->setCertificate($certificate);
		return $suite;
	}//end suiteWithCertificate()
}//end class
