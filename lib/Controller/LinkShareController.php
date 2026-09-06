<?php

/**
 * Keepiq Link Share Controller
 *
 * Authenticated API controller for link share CRUD operations performed
 * by the secret owner.
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
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Service\EncryptionSuiteService;
use OCA\Keepiq\Service\LinkShareService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * Authenticated API controller for link share CRUD.
 */
class LinkShareController extends OCSController {
	/**
	 * Constructor for LinkShareController.
	 *
	 * @param IRequest $request The request object
	 * @param LinkShareService $linkShareService The link share service
	 * @param EncryptionSuiteService $suiteService The encryption suite service
	 * @param IUserSession $userSession The user session
	 * @param IURLGenerator $urlGenerator The URL generator
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private LinkShareService $linkShareService,
		private EncryptionSuiteService $suiteService,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * List link shares for a secret owned by the current user.
	 *
	 * @param string $secretId The secret ID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-link-sharing/tasks.md#task-4.1
	 */
	#[NoAdminRequired]
	public function index(string $secretId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$shares = $this->linkShareService->listBySecret(secretId: $secretId, userId: $user->getUID());

		return new JSONResponse(
			data: array_map(
				static fn ($share) => $share->jsonSerialize(),
				$shares
			)
		);
	}//end index()

	/**
	 * Create a link share for a secret owned by the current user.
	 *
	 * The browser performs all cryptography: it decrypts the secret, derives
	 * the AES key from a generated password via Argon2id, encrypts the
	 * snapshot, and posts the encrypted blob and salt here. The password is
	 * never transmitted.
	 *
	 * @param string $secretId The secret ID
	 * @param string $encryptedSecretSnapshot The AES-256-GCM blob (base64)
	 * @param string $argon2idSalt The base64-encoded Argon2id salt
	 * @param int $usageLimit The usage limit (1-10)
	 * @param string|null $expiresAt Optional ISO-8601 expiry
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-link-sharing/tasks.md#task-4.1
	 *
	 * @SuppressWarnings(PHPMD.LongVariable) Parameter names mirror the API payload fields.
	 */
	#[NoAdminRequired]
	public function create(
		string $secretId,
		string $encryptedSecretSnapshot,
		string $argon2idSalt,
		int $usageLimit = 1,
		?string $expiresAt = null,
	): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$userId = $user->getUID();

		try {
			$suite = $this->suiteService->getActiveSuite(ownerType: 'user', ownerId: $userId);
			$encryptionSuiteId = $suite->getId();
		} catch (DoesNotExistException) {
			return new JSONResponse(
				data: ['message' => 'No active encryption suite for the current user'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$expiry = null;
		if ($expiresAt !== null && $expiresAt !== '') {
			try {
				$expiry = new DateTime($expiresAt);
			} catch (Exception) {
				return new JSONResponse(
					data: ['message' => 'Invalid expiry timestamp'],
					statusCode: Http::STATUS_BAD_REQUEST
				);
			}
		}

		try {
			$linkShare = $this->linkShareService->create(
				secretId: $secretId,
				encryptedSnapshot: $encryptedSecretSnapshot,
				salt: $argon2idSalt,
				encryptionSuiteId: $encryptionSuiteId,
				usageLimit: $usageLimit,
				expiresAt: $expiry,
				userId: $userId
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(
				data: ['message' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		// The /public shell serves the SPA as #[PublicPage] — the
		// authenticated dashboard.page route bounced account-less
		// recipients to the Nextcloud login (fixed with ephemeral-send).
		// A PATH under the shell (publicShell.pageCatchAll serves it), not
		// the retired '#/share/link/' hash form: the SPA routes with
		// createWebHistory, which never reads the fragment, so the hash
		// form showed recipients the lock screen.
		$linkUrl = $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->linkToRoute('keepiq.publicShell.page') . '/share/link/' . $linkShare->getToken()
		);

		$payload = $linkShare->jsonSerialize();
		$payload['linkUrl'] = $linkUrl;

		return new JSONResponse(data: $payload, statusCode: Http::STATUS_CREATED);
	}//end create()

	/**
	 * Revoke (delete) a link share owned by the current user.
	 *
	 * @param string $id The link share ID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-link-sharing/tasks.md#task-4.1
	 */
	#[NoAdminRequired]
	public function destroy(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$this->linkShareService->delete(id: $id, userId: $user->getUID());
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(
				data: ['message' => $e->getMessage()],
				statusCode: Http::STATUS_FORBIDDEN
			);
		} catch (Exception $e) {
			return new JSONResponse(
				data: ['message' => $e->getMessage()],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		return new JSONResponse(data: ['status' => 'deleted']);
	}//end destroy()
}//end class
