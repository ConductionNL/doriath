<?php

/**
 * Keepiq — machine secret-request surface tests
 *
 * What these lock down, from the secret-store-api delta:
 *
 *  - create returns 201 with the token and the derived fill-link
 *  - the vault comes from the AUTHENTICATED principal, so a `userId` (or an
 *    `applicationId`) in the body cannot redirect it — the property that makes
 *    cross-vault creation structurally impossible rather than merely checked
 *  - index lists only the caller's own pending requests
 *  - guard refusals (non-approved application, revoked suite, rotation in
 *    progress) surface as a refusal, not a partially-created request
 *  - a missing principal is 401
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
use InvalidArgumentException;
use OCA\Keepiq\Controller\ApplicationSecretRequestsController;
use OCA\Keepiq\Db\Application;
use OCA\Keepiq\Db\Folder;
use OCA\Keepiq\Db\FolderMapper;
use OCA\Keepiq\Db\SecretRequest;
use OCA\Keepiq\Service\ApplicationSecretRequestService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for the JWT-Bearer secret-request surface.
 */
class ApplicationSecretRequestsControllerTest extends TestCase {
	/**
	 * @var IRequest&MockObject
	 */
	private IRequest&MockObject $request;

	/**
	 * @var ApplicationSecretRequestService&MockObject
	 */
	private ApplicationSecretRequestService&MockObject $service;

	/**
	 * @var FolderMapper&MockObject
	 */
	private FolderMapper&MockObject $folderMapper;

	/**
	 * @var ApplicationSecretRequestsController
	 */
	private ApplicationSecretRequestsController $controller;

	/**
	 * @var array<string,mixed> Body overrides for the mock request.
	 */
	private array $params = [];

	/**
	 * Wire the controller with an authenticated application.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(ApplicationSecretRequestService::class);
		$this->folderMapper = $this->createMock(FolderMapper::class);

		$this->request->method('getParam')->willReturnCallback(
			fn (string $k, $d = null) => ($this->params[$k] ?? $d)
		);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		// Route-aware on purpose. The previous stub returned the API path for
		// EVERY route name, so it could not tell the human fill page from the
		// JSON endpoint — which is exactly the confusion that shipped a
		// fillLinkUrl pointing at raw JSON.
		$urlGenerator->method('linkToRoute')->willReturnCallback(
			static function (string $route, array $args = []): string {
				if (str_ends_with($route, '.publicShell.page') === true) {
					return '/index.php/apps/keepiq/public';
				}

				return '/index.php/apps/keepiq/api/v1/public/secret-requests/' . ($args['token'] ?? '');
			}
		);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(
			static fn (string $path): string => 'https://nc.example' . $path
		);

		$this->controller = new ApplicationSecretRequestsController(
			request: $this->request,
			secretRequestService: $this->service,
			folderMapper: $this->folderMapper,
			urlGenerator: $urlGenerator,
		);

		$app = new Application();
		$app->setId('app-1');
		$app->setName('Connector');
		$this->controller->setApplication($app);
	}//end setUp()

	/**
	 * Build a pending request row.
	 *
	 * @param string $id The request id
	 * @param string $token The fill token
	 *
	 * @return SecretRequest
	 */
	private function pending(string $id = 'req-1', string $token = 'tok-abc'): SecretRequest {
		$entity = new SecretRequest();
		$entity->setId($id);
		$entity->setSecretId('sec-1');
		$entity->setEncryptionSuiteId('suite-app-1');
		$entity->setRequestedFields((string)json_encode(['key', 'url']));
		$entity->setStatus(SecretRequest::STATUS_PENDING);
		$entity->setToken($token);
		$entity->setCreatedBy('application:app-1');
		$entity->setCreatedAt(new DateTime('2026-08-14T10:00:00+00:00'));
		return $entity;
	}//end pending()

	/**
	 * Create returns 201 with the token and the derived fill-link URL.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/application-secret-request-creation/specs/secret-store-api/spec.md#requirement-machine-secret-request-creation
	 */
	public function testCreateReturnsTokenAndFillLink(): void {
		$this->params = ['requestedFields' => ['key', 'url']];

		$this->service->expects($this->once())
			->method('createForApplicationVault')
			->with('app-1', ['key', 'url'], null, null, null)
			->willReturn($this->pending());

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('tok-abc', $data['token']);
		// fillLinkUrl is what an application hands to a PERSON, so it must be
		// the anonymous SPA shell carrying the route as a PATH. It previously
		// resolved to the JSON endpoint (which would have sent the recipient a
		// blob of JSON including the vault's public certificate), then to the
		// retired hash form, which the createWebHistory router never reads —
		// recipients got the lock screen.
		$this->assertSame(
			'https://nc.example/index.php/apps/keepiq/public/share/request/tok-abc',
			$data['fillLinkUrl']
		);
		// The machine-readable endpoint stays available for polling.
		$this->assertSame(
			'https://nc.example/index.php/apps/keepiq/api/v1/public/secret-requests/tok-abc',
			$data['fillApiUrl']
		);
		$this->assertSame(['key', 'url'], $data['requestedFields']);
	}//end testCreateReturnsTokenAndFillLink()

	/**
	 * The typed requestedFields form the discovery contract documents is accepted.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/application-secret-request-creation/specs/secret-store-api/spec.md#requirement-machine-secret-request-creation
	 */
	public function testCreateAcceptsTypedRequestedFields(): void {
		$this->params = [
			'requestedFields' => [
				['field' => 'url', 'visibility' => 'public'],
				['field' => 'api-key', 'visibility' => 'secret'],
			],
		];

		$this->service->expects($this->once())
			->method('createForApplicationVault')
			->with('app-1', ['url', 'api-key'], null, null, null)
			->willReturn($this->pending());

		$this->assertSame(Http::STATUS_CREATED, $this->controller->create()->getStatus());
	}//end testCreateAcceptsTypedRequestedFields()

	/**
	 * A userId or applicationId in the body cannot redirect the vault.
	 *
	 * This is the cross-vault property, and it holds structurally rather than by
	 * a check: the controller passes the principal's id and never reads an actor
	 * from the body, so there is no parameter to abuse.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/application-secret-request-creation/specs/secret-store-api/spec.md#requirement-machine-request-creation-own-vault-scoping
	 */
	public function testUserIdInBodyCannotRedirectTheVault(): void {
		$this->params = [
			'requestedFields' => ['key'],
			'userId' => 'victim-user',
			'applicationId' => 'app-2',
		];

		$this->service->expects($this->once())
			->method('createForApplicationVault')
			// 'app-1' is the authenticated principal; nothing from the body.
			->with('app-1', ['key'], null, null, null)
			->willReturn($this->pending());

		$this->assertSame(Http::STATUS_CREATED, $this->controller->create()->getStatus());
	}//end testUserIdInBodyCannotRedirectTheVault()

	/**
	 * Index lists the caller's own pending requests, each with its fill-link.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/application-secret-request-creation/specs/secret-store-api/spec.md#requirement-machine-pending-request-listing
	 */
	public function testIndexReturnsOwnPendingRequests(): void {
		$this->service->expects($this->once())
			->method('listPendingForApplicationVault')
			->with('app-1')
			->willReturn([$this->pending('req-1', 'tok-1'), $this->pending('req-2', 'tok-2')]);

		$data = $this->controller->index()->getData();

		$this->assertCount(2, $data);
		$this->assertSame('tok-1', $data[0]['token']);
		$this->assertStringEndsWith('tok-2', $data[1]['fillLinkUrl']);
	}//end testIndexReturnsOwnPendingRequests()

	/**
	 * An empty or malformed requestedFields is a 400, and nothing is created.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/application-secret-request-creation/specs/secret-store-api/spec.md#requirement-machine-secret-request-creation
	 */
	public function testCreateRejectsMissingRequestedFields(): void {
		$this->params = [];

		$this->service->expects($this->never())->method('createForApplicationVault');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->create()->getStatus());
	}//end testCreateRejectsMissingRequestedFields()

	/**
	 * A guard refusal surfaces as a refusal, not a partial creation.
	 *
	 * Covers a pending/rejected application and a revoked or compromised suite:
	 * both reach the controller as an exception from the guarded service call.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/application-secret-request-creation/specs/secret-store-api/spec.md#requirement-machine-request-creation-hardening-and-audit
	 */
	/**
	 * A guard refusal from the service becomes 422, not 500.
	 *
	 * Named for the revoked-suite case ONLY, which is what it asserts. The
	 * previous name also claimed the non-approved-application case, which this
	 * body never exercised and cannot: a pending, rejected or deleted
	 * application is refused at AUTHENTICATION (JwtAuthService::loadActiveIssuer
	 * allow-lists STATUS_ACTIVE), so it never obtains a token and never reaches
	 * this controller. That half is covered by
	 * JwtAuthServiceTest::testInactiveApplicationRejected.
	 */
	public function testCreationRefusedForRevokedSuite(): void {
		$this->params = ['requestedFields' => ['key']];

		$this->service->method('createForApplicationVault')
			->willThrowException(new RuntimeException('No active EncryptionSuite for this application'));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}//end testCreationRefusedForRevokedSuite()

	/**
	 * An unknown folderPath is refused rather than silently filed at the root.
	 *
	 * Quietly placing a credential somewhere the caller did not ask for is worse
	 * than refusing, and the walk only ever traverses the caller's own folders.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/application-secret-request-creation/specs/secret-store-api/spec.md#requirement-machine-request-creation-own-vault-scoping
	 */
	public function testCreateRefusesAnUnknownFolderPath(): void {
		$this->params = ['requestedFields' => ['key'], 'folderPath' => 'infra/nope'];

		$root = new Folder();
		$root->setId('folder-infra');
		$root->setName('infra');
		$this->folderMapper->method('findRootFolders')->willReturn([$root]);
		$this->folderMapper->method('findChildren')->willReturn([]);

		$this->service->expects($this->never())->method('createForApplicationVault');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->create()->getStatus());
	}//end testCreateRefusesAnUnknownFolderPath()

	/**
	 * A known folderPath resolves to its id, walked from the caller's own roots.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/application-secret-request-creation/specs/secret-store-api/spec.md#requirement-machine-secret-request-creation
	 */
	public function testCreateResolvesAKnownFolderPath(): void {
		$this->params = ['requestedFields' => ['key'], 'folderPath' => 'infra/zgw'];

		$root = new Folder();
		$root->setId('folder-infra');
		$root->setName('infra');
		$child = new Folder();
		$child->setId('folder-zgw');
		$child->setName('zgw');

		$this->folderMapper->method('findRootFolders')
			->with('application', 'app-1')
			->willReturn([$root]);
		$this->folderMapper->method('findChildren')->willReturn([$child]);

		$this->service->expects($this->once())
			->method('createForApplicationVault')
			->with('app-1', ['key'], null, 'folder-zgw', null)
			->willReturn($this->pending());

		$this->assertSame(Http::STATUS_CREATED, $this->controller->create()->getStatus());
	}//end testCreateResolvesAKnownFolderPath()

	/**
	 * An unparseable expiresAt is a 400, not a silently-dropped expiry.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/application-secret-request-creation/specs/secret-store-api/spec.md#requirement-machine-secret-request-creation
	 */
	public function testCreateRejectsAnUnparseableExpiry(): void {
		$this->params = ['requestedFields' => ['key'], 'expiresAt' => 'next tuesday-ish'];

		$this->service->expects($this->never())->method('createForApplicationVault');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->create()->getStatus());
	}//end testCreateRejectsAnUnparseableExpiry()

	/**
	 * Without an injected principal both endpoints are 401.
	 *
	 * Defence in depth: JwtAuthMiddleware normally rejects first, so this is the
	 * behaviour if it ever does not.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/application-secret-request-creation/specs/secret-store-api/spec.md#requirement-machine-secret-request-creation
	 */
	public function testUnauthenticatedCallsAreRefused(): void {
		$controller = new ApplicationSecretRequestsController(
			request: $this->request,
			secretRequestService: $this->service,
			folderMapper: $this->folderMapper,
			urlGenerator: $this->createMock(IURLGenerator::class),
		);

		$this->service->expects($this->never())->method('createForApplicationVault');
		$this->service->expects($this->never())->method('listPendingForApplicationVault');

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->create()->getStatus());
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $controller->index()->getStatus());
	}//end testUnauthenticatedCallsAreRefused()

	/**
	 * A bad applicationId reaching the list surfaces as a 400.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/application-secret-request-creation/specs/secret-store-api/spec.md#requirement-machine-pending-request-listing
	 */
	public function testIndexSurfacesAnInvalidArgumentAs400(): void {
		$this->service->method('listPendingForApplicationVault')
			->willThrowException(new InvalidArgumentException(message: 'applicationId is required'));

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->index()->getStatus());
	}//end testIndexSurfacesAnInvalidArgumentAs400()
}//end class
