<?php

/**
 * Contract tests for the ShareController endpoints that carry no wire proof:
 * `share#createBatch`, `share#sync`, `share#registerBatch`,
 * `share#recipientCertificate` and `share#writeContext`.
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\Keepiq\Controller\ShareController;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Service\ShareService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * These tests assert the ITEM for each endpoint: that the request's own
 * parameters reach ShareService unchanged, and that the service's answer —
 * not a fabricated one — is what the caller receives. A test that only
 * checked for a 200, or only that a JSONResponse came back, would pass
 * against a controller that silently forwarded nothing.
 *
 */
class ShareControllerTest extends TestCase {

	/**
	 * The mocked request.
	 *
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * The mocked share service.
	 *
	 * @var ShareService&MockObject
	 */
	private ShareService&MockObject $shareService;

	/**
	 * The mocked user session.
	 *
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Set up the mocks shared by every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->shareService = $this->createMock(ShareService::class);
		$this->userSession = $this->createMock(IUserSession::class);
	}//end setUp()

	/**
	 * Build the controller with a signed-in or an anonymous session.
	 *
	 * @param string|null $userId The session UID, or null for an anonymous caller.
	 *
	 * @return ShareController The controller under test.
	 */
	private function controller(?string $userId = 'alice'): ShareController {
		if ($userId === null) {
			$this->userSession->method('getUser')->willReturn(null);
		} else {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
			$this->userSession->method('getUser')->willReturn($user);
		}

		return new ShareController(
			request: $this->request,
			shareService: $this->shareService,
			userSession: $this->userSession
		);
	}//end controller()

	/**
	 * Build a ShareTarget whose serialization is known.
	 *
	 * @param array<string,mixed> $row The serialized row the entity reports.
	 *
	 * @return ShareTarget&MockObject The stubbed entity.
	 */
	private function shareTarget(array $row): ShareTarget&MockObject {
		$entity = $this->createMock(ShareTarget::class);
		$entity->method('jsonSerialize')->willReturn($row);

		return $entity;
	}//end shareTarget()

	/**
	 * POST /api/v1/secrets/{secretId}/shares/batch must forward the URL's
	 * secret id, the submitted per-recipient rows and the group-share id,
	 * bound to the session UID, and answer 201 with every created row.
	 *
	 * @return void
	 */
	public function testCreateBatchForwardsTheWholeBatchAndReturnsTheCreatedRows(): void {
		$shares = [
			[
				'targetUserId' => 'bob',
				'recipientSecretId' => 'copy-bob',
			],
			[
				'targetUserId' => 'carol',
				'recipientSecretId' => 'copy-carol',
			],
		];

		$created = [
			$this->shareTarget(
				[
					'id' => 'st-1',
					'targetUserId' => 'bob',
				]
			),
			$this->shareTarget(
				[
					'id' => 'st-2',
					'targetUserId' => 'carol',
				]
			),
		];

		// The ITEM: the batch reaches the service with the request's own arguments.
		$this->shareService->expects($this->once())
			->method('createBatchShares')
			->with('secret-1', $shares, 'group-9', 'alice')
			->willReturn($created);

		$response = $this->controller('alice')->createBatch(
			secretId: 'secret-1',
			shares: $shares,
			groupShareId: 'group-9'
		);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(
			[
				[
					'id' => 'st-1',
					'targetUserId' => 'bob',
				],
				[
					'id' => 'st-2',
					'targetUserId' => 'carol',
				],
			],
			$response->getData(),
			'createBatch() must return the rows the service actually created'
		);
	}//end testCreateBatchForwardsTheWholeBatchAndReturnsTheCreatedRows()

	/**
	 * An anonymous caller is refused with 401 and never reaches the service.
	 *
	 * @return void
	 */
	public function testCreateBatchRejectsAnAnonymousCallerBeforeTheService(): void {
		$this->shareService->expects($this->never())->method('createBatchShares');

		$response = $this->controller(null)->createBatch(
			secretId: 'secret-1',
			shares: [['targetUserId' => 'bob']],
			groupShareId: 'group-9'
		);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Unauthorized'], $response->getData());
	}//end testCreateBatchRejectsAnAnonymousCallerBeforeTheService()

	/**
	 * A batch the service refuses answers 400 carrying the refusal reason,
	 * not a partial-success envelope.
	 *
	 * @return void
	 */
	public function testCreateBatchSurfacesARefusedBatchAs400(): void {
		$this->shareService->method('createBatchShares')
			->willThrowException(new InvalidArgumentException('recipientSecretId is required'));

		$response = $this->controller('alice')->createBatch(
			secretId: 'secret-1',
			shares: [['targetUserId' => 'bob']],
			groupShareId: 'group-9'
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'recipientSecretId is required'], $response->getData());
	}//end testCreateBatchSurfacesARefusedBatchAs400()

	/**
	 * POST /api/v1/secrets/{secretId}/sync must forward the per-recipient
	 * blobs AND the optimistic-concurrency timestamp, and report the number
	 * of rows the service actually wrote.
	 *
	 * @return void
	 */
	public function testSyncForwardsTheUpdatesAndTheExpectedTimestamp(): void {
		$updates = [
			[
				'targetUserId' => 'bob',
				'encryptedKey' => 'CIPHERTEXT_BOB',
			],
		];

		$this->shareService->expects($this->once())
			->method('syncUpdate')
			->with('secret-1', $updates, '2026-08-09T10:00:00+00:00', 'alice')
			->willReturn(1);

		$response = $this->controller('alice')->sync(
			secretId: 'secret-1',
			updates: $updates,
			expectedUpdatedAt: '2026-08-09T10:00:00+00:00'
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['updated' => 1],
			$response->getData(),
			'sync() must report the write count the service returned'
		);
	}//end testSyncForwardsTheUpdatesAndTheExpectedTimestamp()

	/**
	 * A stale expected timestamp is a lost-update conflict: 409, never a
	 * success envelope that would let the browser believe it pushed.
	 *
	 * @return void
	 */
	public function testSyncAnswers409WhenTheSourceMovedUnderTheCaller(): void {
		$this->shareService->method('syncUpdate')
			->willThrowException(new InvalidArgumentException('Secret changed since it was loaded'));

		$response = $this->controller('alice')->sync(
			secretId: 'secret-1',
			updates: [],
			expectedUpdatedAt: '2026-08-09T09:00:00+00:00'
		);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame(['message' => 'Secret changed since it was loaded'], $response->getData());
	}//end testSyncAnswers409WhenTheSourceMovedUnderTheCaller()

	/**
	 * An anonymous caller may not push blobs at other users' copies.
	 *
	 * @return void
	 */
	public function testSyncRejectsAnAnonymousCallerBeforeTheService(): void {
		$this->shareService->expects($this->never())->method('syncUpdate');

		$response = $this->controller(null)->sync(secretId: 'secret-1', updates: []);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Unauthorized'], $response->getData());
	}//end testSyncRejectsAnAnonymousCallerBeforeTheService()

	/**
	 * POST /api/v1/shares/register-batch must hand the service the session
	 * UID together with the submitted rows, and return the per-item report
	 * verbatim — a skipped row must stay visible to the caller.
	 *
	 * @return void
	 */
	public function testRegisterBatchReturnsThePerItemReportIncludingSkips(): void {
		$shares = [
			[
				'sourceSecretId' => 'secret-1',
				'targetUserId' => 'bob',
				'encryptedKey' => 'CIPHERTEXT_BOB',
			],
			[
				'sourceSecretId' => 'secret-2',
				'targetUserId' => 'carol',
				'encryptedKey' => 'CIPHERTEXT_CAROL',
			],
		];

		$report = [
			[
				'sourceSecretId' => 'secret-1',
				'targetUserId' => 'bob',
				'status' => 'created',
			],
			[
				'sourceSecretId' => 'secret-2',
				'targetUserId' => 'carol',
				'status' => 'skipped',
				'reason' => 'already shared',
			],
		];

		$this->shareService->expects($this->once())
			->method('registerDirectShares')
			->with('alice', $shares)
			->willReturn($report);

		$response = $this->controller('alice')->registerBatch(shares: $shares);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['items' => $report],
			$response->getData(),
			'registerBatch() must surface every item report, skips included'
		);
	}//end testRegisterBatchReturnsThePerItemReportIncludingSkips()

	/**
	 * An anonymous caller is refused with 401 and registers nothing.
	 *
	 * @return void
	 */
	public function testRegisterBatchRejectsAnAnonymousCallerBeforeTheService(): void {
		$this->shareService->expects($this->never())->method('registerDirectShares');

		$response = $this->controller(null)->registerBatch(shares: [['targetUserId' => 'bob']]);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Unauthorized'], $response->getData());
	}//end testRegisterBatchRejectsAnAnonymousCallerBeforeTheService()

	/**
	 * GET /api/v1/shares/recipient-certificate must look the certificate up
	 * for the REQUESTED user (not the caller) and return the public key
	 * material keyed by that user id.
	 *
	 * @return void
	 */
	public function testRecipientCertificateReturnsTheRequestedUsersPublicMaterial(): void {
		$this->shareService->expects($this->once())
			->method('recipientCertificate')
			->with('bob')
			->willReturn('-----BEGIN CERTIFICATE-----BOB-----END CERTIFICATE-----');

		$response = $this->controller('alice')->recipientCertificate(userId: 'bob');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				'userId' => 'bob',
				'certificate' => '-----BEGIN CERTIFICATE-----BOB-----END CERTIFICATE-----',
			],
			$response->getData(),
			'the response must name the recipient the certificate belongs to'
		);
	}//end testRecipientCertificateReturnsTheRequestedUsersPublicMaterial()

	/**
	 * A recipient without an active encryption suite is a 404, not an empty
	 * 200 that the browser would encrypt against.
	 *
	 * @return void
	 */
	public function testRecipientCertificateAnswers404WhenTheRecipientHasNoActiveSuite(): void {
		$this->shareService->expects($this->once())
			->method('recipientCertificate')
			->with('mallory')
			->willReturn(null);

		$response = $this->controller('alice')->recipientCertificate(userId: 'mallory');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(
			['message' => 'Recipient has no active encryption suite'],
			$response->getData()
		);
	}//end testRecipientCertificateAnswers404WhenTheRecipientHasNoActiveSuite()

	/**
	 * The certificate directory is not open to anonymous callers.
	 *
	 * @return void
	 */
	public function testRecipientCertificateRejectsAnAnonymousCallerBeforeTheService(): void {
		$this->shareService->expects($this->never())->method('recipientCertificate');

		$response = $this->controller(null)->recipientCertificate(userId: 'bob');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Unauthorized'], $response->getData());
	}//end testRecipientCertificateRejectsAnAnonymousCallerBeforeTheService()

	/**
	 * GET /api/v1/secrets/{id}/write-context must resolve the context for
	 * the URL's secret AND the session user, and return the service's
	 * resolution — source pivot, effective grade and owner material.
	 *
	 * @return void
	 */
	public function testWriteContextResolvesTheGradeForTheSessionUser(): void {
		$context = [
			'sourceSecretId' => 'secret-source',
			'effectiveGrade' => 'write',
			'ownerCertificate' => '-----BEGIN CERTIFICATE-----OWNER-----END CERTIFICATE-----',
			'sourceUpdatedAt' => '2026-08-09T10:00:00+00:00',
		];

		$this->shareService->expects($this->once())
			->method('writeContext')
			->with('copy-of-secret', 'alice')
			->willReturn($context);

		$response = $this->controller('alice')->writeContext(id: 'copy-of-secret');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			$context,
			$response->getData(),
			'writeContext() must return the resolution the service computed, unaltered'
		);
	}//end testWriteContextResolvesTheGradeForTheSessionUser()

	/**
	 * An unknown or unreachable secret answers 404 — never a default grade
	 * that the editor would treat as permission.
	 *
	 * @return void
	 */
	public function testWriteContextAnswers404ForAnUnresolvableSecret(): void {
		$this->shareService->expects($this->once())
			->method('writeContext')
			->with('ghost', 'alice')
			->willThrowException(new InvalidArgumentException('Secret not found'));

		$response = $this->controller('alice')->writeContext(id: 'ghost');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'Not found'], $response->getData());
	}//end testWriteContextAnswers404ForAnUnresolvableSecret()

	/**
	 * An anonymous caller learns nothing about a secret's write context.
	 *
	 * @return void
	 */
	public function testWriteContextRejectsAnAnonymousCallerBeforeTheService(): void {
		$this->shareService->expects($this->never())->method('writeContext');

		$response = $this->controller(null)->writeContext(id: 'secret-1');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['message' => 'Unauthorized'], $response->getData());
	}//end testWriteContextRejectsAnAnonymousCallerBeforeTheService()

	/**
	 * Shareable and non-shareable recipients come back in one response.
	 *
	 * @return void
	 */
	public function testRecipientCertificatesReportsBothShareableAndNot(): void {
		$this->shareService->expects($this->once())
			->method('recipientCertificates')
			->with(['alice', 'bob'])
			->willReturn(['alice' => 'PEM-ALICE']);

		$response = $this->controller()->recipientCertificates(['alice', 'bob']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				['userId' => 'alice', 'shareable' => true, 'certificate' => 'PEM-ALICE'],
				['userId' => 'bob', 'shareable' => false, 'reason' => 'no_active_suite'],
			],
			$response->getData()['recipients']
		);
	}//end testRecipientCertificatesReportsBothShareableAndNot()

	/**
	 * Input order is preserved and duplicates collapse.
	 *
	 * The caller zips the response against the list it sent, so order is part
	 * of the contract, not an accident of the query.
	 *
	 * @return void
	 */
	public function testRecipientCertificatesPreservesOrderAndDeduplicates(): void {
		$this->shareService->expects($this->once())
			->method('recipientCertificates')
			->with(['carol', 'alice'])
			->willReturn(['alice' => 'PEM-ALICE', 'carol' => 'PEM-CAROL']);

		$response = $this->controller()->recipientCertificates(['carol', 'alice', 'carol', '', 'carol']);

		$this->assertSame(
			['carol', 'alice'],
			array_column($response->getData()['recipients'], 'userId')
		);
	}//end testRecipientCertificatesPreservesOrderAndDeduplicates()

	/**
	 * An empty list is refused before the service is touched.
	 *
	 * @return void
	 */
	public function testRecipientCertificatesRejectsAnEmptyList(): void {
		$this->shareService->expects($this->never())->method('recipientCertificates');

		$response = $this->controller()->recipientCertificates(['', 42, null]);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testRecipientCertificatesRejectsAnEmptyList()

	/**
	 * An oversized list is refused before the service is touched.
	 *
	 * Without the cap one request could ask for arbitrary work.
	 *
	 * @return void
	 */
	public function testRecipientCertificatesRejectsAnOversizedList(): void {
		$this->shareService->expects($this->never())->method('recipientCertificates');

		$ids = [];
		for ($i = 0; $i <= 100; $i++) {
			$ids[] = 'user-' . $i;
		}

		$response = $this->controller()->recipientCertificates($ids);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('101 given', $response->getData()['message']);
	}//end testRecipientCertificatesRejectsAnOversizedList()

	/**
	 * An anonymous caller is refused before the service is touched.
	 *
	 * @return void
	 */
	public function testRecipientCertificatesRejectsAnAnonymousCaller(): void {
		$this->shareService->expects($this->never())->method('recipientCertificates');

		$response = $this->controller(null)->recipientCertificates(['alice']);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testRecipientCertificatesRejectsAnAnonymousCaller()
}//end class
