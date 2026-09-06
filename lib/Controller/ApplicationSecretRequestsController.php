<?php

/**
 * Keepiq Application Secret Requests Controller
 *
 * The machine surface for secret requests: an application asks a human to fill
 * in a credential it must never handle itself.
 *
 * Every other creation path is user-session bound, which an unattended
 * application does not have — that is the gap this closes. Authentication is
 * the same posture as the sibling `/api/v1/app/*` routes: handlers are
 * `#[PublicPage]` so the request reaches the controller at all, and
 * `JwtAuthMiddleware` resolves the `Application` principal from a validated
 * RS256 Bearer assertion before any handler body runs. `getApplication()`
 * returning null therefore means the token was absent or invalid, never that a
 * user is missing.
 *
 * Own-vault scoping is structural, not filtered: the vault is taken from the
 * authenticated principal and no body parameter can redirect it. A `userId` in
 * the body is inert — there is no code path that reads one.
 *
 * @category Controller
 * @package  OCA\Keepiq\Controller
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

namespace OCA\Keepiq\Controller;

use DateTime;
use Exception;
use InvalidArgumentException;
use OCA\Keepiq\AppInfo\Application as KeepiqApp;
use OCA\Keepiq\Db\FolderMapper;
use OCA\Keepiq\Db\SecretRequest;
use OCA\Keepiq\Service\ApplicationSecretRequestService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IURLGenerator;

/**
 * Session-less secret-request creation and listing for applications.
 */
class ApplicationSecretRequestsController extends ApplicationApiController {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request
	 * @param ApplicationSecretRequestService $secretRequestService The machine request service
	 * @param FolderMapper $folderMapper Resolves an optional folder path
	 * @param IURLGenerator $urlGenerator Builds the absolute fill-link URL
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private ApplicationSecretRequestService $secretRequestService,
		private FolderMapper $folderMapper,
		private IURLGenerator $urlGenerator,
	) {
		parent::__construct(appName: KeepiqApp::APP_ID, request: $request);
	}//end __construct()

	/**
	 * List the calling application's own pending fill-links.
	 *
	 * The token is returned once at creation, so an application that lost the
	 * response has no other route back to its own fill-link.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/application-secret-request-creation/specs/secret-store-api/spec.md#requirement-machine-pending-request-listing
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function index(): JSONResponse {
		$application = $this->getApplication();
		if ($application === null) {
			return $this->unauthorized();
		}

		try {
			$requests = $this->secretRequestService->listPendingForApplicationVault(
				applicationId: $application->getId()
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(
				data: ['message' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		return new JSONResponse(
			data: array_map([$this, 'serialize'], $requests)
		);
	}//end index()

	/**
	 * Create a request in the calling application's own vault.
	 *
	 * The Secret shell is created for the caller, so no pre-existing secret id
	 * is needed — an application has nothing to point at yet.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/application-secret-request-creation/specs/secret-store-api/spec.md#requirement-machine-secret-request-creation
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	public function create(): JSONResponse {
		$application = $this->getApplication();
		if ($application === null) {
			return $this->unauthorized();
		}

		try {
			$request = $this->secretRequestService->createForApplicationVault(
				// Taken from the verified principal. A `userId` in the body is
				// never read, so it cannot redirect the vault.
				applicationId: $application->getId(),
				requestedFields: $this->requestedFields(),
				name: $this->optionalString(key: 'name'),
				folderId: $this->resolveFolderId(applicationId: $application->getId()),
				expiresAt: $this->optionalExpiry()
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(
				data: ['message' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		} catch (Exception $e) {
			// Guard refusals (pending/rejected application, revoked or
			// compromised suite, a rotation in progress) land here.
			return new JSONResponse(
				data: ['message' => $e->getMessage()],
				statusCode: Http::STATUS_UNPROCESSABLE_ENTITY
			);
		}//end try

		return new JSONResponse(
			data: $this->serialize(request: $request),
			statusCode: Http::STATUS_CREATED
		);
	}//end create()

	/**
	 * Normalise the requested-field list.
	 *
	 * Accepts either the typed form the discovery contract documents
	 * (`[{"field": "api-key", "visibility": "secret"}]`) or a bare list of
	 * names, and stores names only. Which column a name lands on is decided at
	 * fill time by the secret-requests field model, not here.
	 *
	 * @return array<int,string>
	 *
	 * @throws InvalidArgumentException When the list is absent, empty or malformed
	 *
	 * @spec openspec/changes/application-secret-request-creation/specs/secret-store-api/spec.md#requirement-machine-secret-request-creation
	 */
	private function requestedFields(): array {
		$raw = $this->request->getParam('requestedFields');

		if (is_array($raw) === false || $raw === []) {
			throw new InvalidArgumentException(message: 'requestedFields must be a non-empty array');
		}

		$names = [];
		foreach ($raw as $entry) {
			if (is_string($entry) === true) {
				$names[] = $entry;
				continue;
			}

			if (is_array($entry) === true && is_string($entry['field'] ?? null) === true) {
				$names[] = $entry['field'];
				continue;
			}

			throw new InvalidArgumentException(
				message: 'Each requestedFields entry must be a name or an object carrying "field"'
			);
		}

		$names = array_values(array_unique(array_filter($names, static fn (string $n): bool => trim($n) !== '')));

		if ($names === []) {
			throw new InvalidArgumentException(message: 'requestedFields must name at least one field');
		}

		return $names;
	}//end requestedFields()

	/**
	 * An optional trimmed string body parameter, or null.
	 *
	 * @param string $key The body key
	 *
	 * @return string|null
	 */
	private function optionalString(string $key): ?string {
		$value = $this->request->getParam($key);

		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end optionalString()

	/**
	 * The optional fill-link expiry.
	 *
	 * @return DateTime|null
	 *
	 * @throws InvalidArgumentException When present but unparseable
	 */
	private function optionalExpiry(): ?DateTime {
		$raw = $this->optionalString(key: 'expiresAt');
		if ($raw === null) {
			return null;
		}

		try {
			return new DateTime($raw);
		} catch (Exception) {
			throw new InvalidArgumentException(message: 'expiresAt must be an ISO 8601 timestamp');
		}
	}//end optionalExpiry()

	/**
	 * Resolve an optional `folderPath` to a folder id in the caller's vault.
	 *
	 * Walks the path segment by segment through the application's own folders,
	 * so a path can never resolve into another vault. An unknown path is an
	 * error rather than a silent placement at the root: quietly filing a
	 * credential somewhere the caller did not ask for is worse than refusing.
	 *
	 * @param string $applicationId The authenticated application
	 *
	 * @return string|null The folder id, or null when no path was supplied
	 *
	 * @throws InvalidArgumentException When a segment does not exist
	 */
	private function resolveFolderId(string $applicationId): ?string {
		$path = $this->optionalString(key: 'folderPath');
		if ($path === null) {
			return null;
		}

		$segments = array_values(
			array_filter(
				array_map('trim', explode('/', $path)),
				static fn (string $segment): bool => $segment !== ''
			)
		);

		if ($segments === []) {
			return null;
		}

		$candidates = $this->folderMapper->findRootFolders('application', $applicationId);
		$currentId = null;

		foreach ($segments as $segment) {
			$match = null;
			foreach ($candidates as $folder) {
				if ($folder->getName() === $segment) {
					$match = $folder;
					break;
				}
			}

			if ($match === null) {
				throw new InvalidArgumentException(
					message: 'No such folder in this vault: ' . $path
				);
			}

			$currentId = $match->getId();
			$candidates = $this->folderMapper->findChildren($currentId);
		}

		return $currentId;
	}//end resolveFolderId()

	/**
	 * 401 for a missing or invalid Bearer token.
	 *
	 * Defence in depth: JwtAuthMiddleware normally rejects first, so reaching
	 * this means the principal was not injected.
	 *
	 * @return JSONResponse
	 */
	private function unauthorized(): JSONResponse {
		return new JSONResponse(
			data: ['message' => 'Bearer token required'],
			statusCode: Http::STATUS_UNAUTHORIZED
		);
	}//end unauthorized()

	/**
	 * Serialise a request with its token and derived fill-link.
	 *
	 * The token is the fill-link's only credential, so it is returned to the
	 * OWNING application and nowhere else. Nothing here exposes a submitted
	 * value: the write-without-read property is preserved.
	 *
	 * @param SecretRequest $request The request
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/application-secret-request-creation/specs/secret-store-api/spec.md#requirement-machine-secret-request-creation
	 */
	private function serialize(SecretRequest $request): array {
		$decoded = json_decode(json: $request->getRequestedFields(), associative: true);

		$requestedFields = [];
		if (is_array($decoded) === true) {
			$requestedFields = $decoded;
		}

		return [
			'id' => $request->getId(),
			'secretId' => $request->getSecretId(),
			'status' => $request->getStatus(),
			'requestedFields' => $requestedFields,
			'token' => $request->getToken(),
			// The HUMAN-facing page, not the JSON endpoint. This previously
			// pointed at `secretRequestFill.show`
			// (/api/v1/public/secret-requests/{token}), so an application that
			// did what the field name says — hand the link to a person — sent
			// them raw JSON including the vault's public certificate.
			//
			// The recipient has no Nextcloud account, so the URL must be the
			// ANONYMOUS SPA shell carrying the route as a PATH
			// (`publicShell.pageCatchAll` serves it): the authenticated
			// /apps/keepiq/share/request/{token} form answers 401 for them,
			// and the retired '#/share/request/' hash form is never read by
			// the createWebHistory router — it showed recipients the lock
			// screen.
			'fillLinkUrl' => $this->urlGenerator->getAbsoluteURL(
				$this->urlGenerator->linkToRoute(KeepiqApp::APP_ID . '.publicShell.page')
				. '/share/request/' . $request->getToken()
			),
			// Kept alongside it so a machine polling for fulfilment does not
			// have to parse the human URL.
			'fillApiUrl' => $this->urlGenerator->getAbsoluteURL(
				$this->urlGenerator->linkToRoute(
					KeepiqApp::APP_ID . '.secretRequestFill.show',
					['token' => $request->getToken()]
				)
			),
			'expiresAt' => $request->getExpiresAt()?->format('c'),
			'createdAt' => $request->getCreatedAt()?->format('c'),
		];
	}//end serialize()
}//end class
