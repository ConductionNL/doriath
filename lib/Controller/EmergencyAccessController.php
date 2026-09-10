<?php

/**
 * Keepiq Emergency Access Controller
 *
 * Authenticated API for the break-glass emergency-access lifecycle
 * (add-emergency-access §2.3). Every route is session-authenticated
 * (`#[NoAdminRequired]`); per-relationship authorization (grantor-only /
 * grantee-only) is enforced in EmergencyAccessService, which throws
 * ForbiddenException for a caller who is neither the grantor nor the grantee.
 * The fetch-envelope route returns the recovery envelope ONLY when the request
 * is approved and the caller is the named grantee — wrong-state and wrong-caller
 * are refused identically (no oracle).
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

use InvalidArgumentException;
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Attribute\VaultKeyProofRequired;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\EmergencyAccessService;
use OCA\Keepiq\Service\VaultKeyProofService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Authenticated API controller for emergency-access lifecycle operations.
 */
class EmergencyAccessController extends OCSController {
	/**
	 * Constructor for EmergencyAccessController.
	 *
	 * @param IRequest $request The request object
	 * @param EmergencyAccessService $service The emergency-access service
	 * @param IUserSession $userSession The user session
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private EmergencyAccessService $service,
		private IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * List the current user's designated emergency contacts (as grantor).
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-designate-emergency-contact
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$userId = $this->requireUserId();
		if ($userId === null) {
			return $this->unauthorized();
		}

		return new JSONResponse(
			data: array_map(
				static fn ($c) => $c->jsonSerialize(),
				$this->service->listForGrantor(grantorUserId: $userId)
			)
		);
	}//end index()

	/**
	 * List the relationships where the current user is the grantee (incoming).
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec     openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-break-glass-request-and-wait-timer
	 * @contract exclude Thin read delegate; unit-covered by EmergencyAccessServiceTest; live Newman contract test deferred (worktree not deployed).
	 */
	#[NoAdminRequired]
	public function incoming(): JSONResponse {
		$userId = $this->requireUserId();
		if ($userId === null) {
			return $this->unauthorized();
		}

		return new JSONResponse(
			data: array_map(
				static fn ($c) => $c->jsonSerialize(),
				$this->service->listForGrantee(granteeUserId: $userId)
			)
		);
	}//end incoming()

	/**
	 * Return a grantee's active-suite certificate so the grantor's browser can
	 * build the recovery envelope.
	 *
	 * @param string $granteeUserId The grantee Nextcloud user ID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec     openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-designate-emergency-contact
	 * @contract exclude Thin read delegate; no-active-suite rejection unit-covered by
	 *                    EmergencyAccessServiceTest; live Newman contract test deferred (worktree not deployed).
	 */
	#[NoAdminRequired]
	public function granteeCertificate(string $granteeUserId): JSONResponse {
		$userId = $this->requireUserId();
		if ($userId === null) {
			return $this->unauthorized();
		}

		try {
			return new JSONResponse(data: $this->service->getGranteeCertificate(granteeUserId: $granteeUserId));
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}
	}//end granteeCertificate()

	/**
	 * Designate (or re-establish) an emergency contact. The recovery envelope is
	 * built in the grantor's browser and supplied as opaque ciphertext.
	 *
	 * @param string $granteeUserId The grantee Nextcloud user ID
	 * @param int $waitPeriodDays The wait period (1|3|7|30)
	 * @param string $recoveryEnvelope The grantee-encrypted recovery envelope
	 * @param string $accessLevel The access level (v1: 'view')
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-designate-emergency-contact
	 */
	#[NoAdminRequired]
	public function create(
		string $granteeUserId,
		int $waitPeriodDays,
		string $recoveryEnvelope,
		string $accessLevel = 'view',
	): JSONResponse {
		$userId = $this->requireUserId();
		if ($userId === null) {
			return $this->unauthorized();
		}

		try {
			$contact = $this->service->designate(
				grantorUserId: $userId,
				granteeUserId: $granteeUserId,
				waitPeriodDays: $waitPeriodDays,
				accessLevel: $accessLevel,
				recoveryEnvelope: $recoveryEnvelope,
			);
			return new JSONResponse(data: $contact->jsonSerialize(), statusCode: Http::STATUS_CREATED);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}
	}//end create()

	/**
	 * Revoke an emergency contact (grantor only) — deletes the envelope and
	 * cancels any pending request.
	 *
	 * @param string $id The relationship ID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-revoke-emergency-contact
	 */
	#[NoAdminRequired]
	#[VaultKeyProofRequired(
		binds: ['id'],
		subject: 'active',
		purpose: VaultKeyProofService::PURPOSE_EMERGENCY_DESTROY
	)]
	public function destroy(string $id): JSONResponse {
		$userId = $this->requireUserId();
		if ($userId === null) {
			return $this->unauthorized();
		}

		try {
			$this->service->revoke(grantorUserId: $userId, id: $id);
			return new JSONResponse(data: ['status' => 'revoked']);
		} catch (NotFoundException) {
			return new JSONResponse(data: ['message' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		}
	}//end destroy()

	/**
	 * Initiate a break-glass request (grantee only). Starts the wait timer and
	 * notifies the grantor; releases nothing.
	 *
	 * @param string $id The relationship ID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-break-glass-request-and-wait-timer
	 */
	#[NoAdminRequired]
	public function request(string $id): JSONResponse {
		$userId = $this->requireUserId();
		if ($userId === null) {
			return $this->unauthorized();
		}

		try {
			$contact = $this->service->request(granteeUserId: $userId, id: $id);
			return new JSONResponse(data: $contact->jsonSerialize());
		} catch (NotFoundException) {
			return new JSONResponse(data: ['message' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		}
	}//end request()

	/**
	 * Decline (veto) a pending break-glass request (grantor only).
	 *
	 * @param string $id The relationship ID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-grantor-decline-veto
	 */
	#[NoAdminRequired]
	public function decline(string $id): JSONResponse {
		$userId = $this->requireUserId();
		if ($userId === null) {
			return $this->unauthorized();
		}

		try {
			$contact = $this->service->decline(grantorUserId: $userId, id: $id);
			return new JSONResponse(data: $contact->jsonSerialize());
		} catch (NotFoundException) {
			return new JSONResponse(data: ['message' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		}
	}//end decline()

	/**
	 * Fetch the recovery envelope (grantee only, approved only). Refuses wrong
	 * state and wrong caller identically.
	 *
	 * @param string $id The relationship ID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/add-emergency-access/specs/emergency-access/spec.md#requirement-approval-by-timeout-and-grantee-view-access
	 */
	#[NoAdminRequired]
	public function envelope(string $id): JSONResponse {
		$userId = $this->requireUserId();
		if ($userId === null) {
			return $this->unauthorized();
		}

		try {
			$envelope = $this->service->fetchEnvelope(granteeUserId: $userId, id: $id);
			return new JSONResponse(data: ['recoveryEnvelope' => $envelope]);
		} catch (ForbiddenException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		}
	}//end envelope()

	/**
	 * Resolve the current session user id, or null when unauthenticated.
	 *
	 * @return string|null
	 */
	private function requireUserId(): ?string {
		$user = $this->userSession->getUser();
		return $user?->getUID();
	}//end requireUserId()

	/**
	 * Build a 401 Unauthorized response.
	 *
	 * @return JSONResponse
	 */
	private function unauthorized(): JSONResponse {
		return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
	}//end unauthorized()
}//end class
