<?php

/**
 * Keepiq Encryption Suite Controller
 *
 * API controller for EncryptionSuite CRUD operations.
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

use Exception;
use InvalidArgumentException;
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Exception\ConflictException;
use OCA\Keepiq\Attribute\VaultKeyProofRequired;
use OCA\Keepiq\Service\EmergencyEnvelopeInvalidationService;
use OCA\Keepiq\Service\EncryptionSuiteService;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\VaultKeyProofService;
use OCA\Keepiq\Settings\AdminSettings;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

/**
 * API controller for EncryptionSuite CRUD operations.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The suite lifecycle this
 *   controller owns — create, show, revoke, reinstate, routine re-key,
 *   compromise recovery and now vault-key-proof challenge issuance — legitimately
 *   coordinates several services and the guard attribute. Adding
 *   VaultKeyProofService for the challenge endpoint pushed it to 13; splitting
 *   the challenge onto its own controller would add a route surface for one
 *   trivial method without reducing the domain coupling that the rest carries.
 */
class EncryptionSuiteController extends OCSController {
	/**
	 * Constructor for EncryptionSuiteController.
	 *
	 * @param IRequest $request The request object
	 * @param EncryptionSuiteService $suiteService The suite service
	 * @param MigrationService $migrationService The migration service
	 * @param IUserSession $userSession The user session
	 * @param VaultKeyProofService $proofService The vault-key-proof service (issues challenges)
	 * @param EmergencyEnvelopeInvalidationService $emergencyService The emergency-envelope service (revoke safeguard)
	 * @param \OCA\Keepiq\Service\PasskeyService|null $passkeyService The passkey service (passkey vault login; null when unwired)
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private EncryptionSuiteService $suiteService,
		private MigrationService $migrationService,
		private IUserSession $userSession,
		private VaultKeyProofService $proofService,
		private EmergencyEnvelopeInvalidationService $emergencyService,
		private ?\OCA\Keepiq\Service\PasskeyService $passkeyService = null,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * List suites for the current user.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-2
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$userId = $user->getUID();
		$suites = $this->suiteService->getSuitesByOwner(ownerType: 'user', ownerId: $userId);

		return new JSONResponse(
			data: array_map(
				static fn ($suite) => $suite->jsonSerialize(),
				$suites
			)
		);
	}//end index()

	/**
	 * Get a specific suite.
	 *
	 * @param string $id The suite ID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-2
	 */
	#[NoAdminRequired]
	public function show(string $id): JSONResponse {
		if ($this->userSession->getUser() === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$suite = $this->suiteService->getSuite($id);
			$this->validateOwnership(suite: $suite);
			return new JSONResponse(data: $suite->jsonSerialize());
		} catch (Exception $e) {
			return new JSONResponse(
				data: ['message' => $e->getMessage()],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}
	}//end show()

	/**
	 * Whether both halves of the submitted key material are present.
	 *
	 * Extracted from create() because these four comparisons were three of that
	 * method's ten branches, and an endpoint's parameter contract reads better as a
	 * named question than as a chain inside the flow it guards.
	 *
	 * @param string|null $publicKey The submitted public key
	 * @param string|null $encryptedPrivateKey The submitted private-key envelope
	 *
	 * @return bool True when both are present and non-empty
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-suite-creation-on-first-login
	 */
	private function hasKeyMaterial(?string $publicKey, ?string $encryptedPrivateKey): bool {
		if ($publicKey === null || $publicKey === '') {
			return false;
		}

		if ($encryptedPrivateKey === null || $encryptedPrivateKey === '') {
			return false;
		}

		return true;
	}//end hasKeyMaterial()

	/**
	 * Create a new EncryptionSuite for the current user.
	 *
	 * @param string $publicKey The PEM-encoded public key
	 * @param string $encryptedPrivateKey The encrypted private key
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-2
	 */
	#[NoAdminRequired]
	public function create(
		?string $publicKey = null,
		?string $encryptedPrivateKey = null,
	): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		// Validate required params HERE so a missing body returns 400, not a 500
		// from the framework dispatcher failing to bind non-nullable arguments.
		if ($this->hasKeyMaterial(publicKey: $publicKey, encryptedPrivateKey: $encryptedPrivateKey) === false) {
			return new JSONResponse(
				data: ['message' => 'Missing required parameters: publicKey and encryptedPrivateKey are required'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$userId = $user->getUID();

		// Reject suite creation while a key-compromise migration is in progress —
		// the old suite must finish re-encrypting data before a new one is registered.
		if ($this->migrationService->isWriteLocked(ownerType: 'user', ownerId: $userId) === true) {
			return new JSONResponse(
				data: ['message' => 'Write locked: an active key migration is in progress'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		try {
			$suite = $this->suiteService->createSuite(
				ownerType: 'user',
				ownerId: $userId,
				publicKeyPem: $publicKey,
				encryptedPrivateKey: $encryptedPrivateKey
			);
			return new JSONResponse(data: $suite->jsonSerialize(), statusCode: Http::STATUS_CREATED);
		} catch (ConflictException $e) {
			// BEFORE the RuntimeException arm below, which this extends — caught after
			// it, a duplicate-suite refusal would surface as 503 "service unavailable"
			// and read as a server fault the client should retry. It is neither.
			return new JSONResponse(
				data: ['error' => 'suite_already_exists', 'message' => $e->getMessage()],
				statusCode: Http::STATUS_CONFLICT
			);
		} catch (InvalidArgumentException $e) {
			// Malformed key material (e.g. a non-PEM publicKey) is a client error,
			// not a server fault — surface it as a 400 instead of a 500.
			return new JSONResponse(
				data: ['message' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		} catch (RuntimeException $e) {
			return new JSONResponse(
				data: ['message' => $e->getMessage()],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		}//end try
	}//end create()

	/**
	 * Update the encrypted private key (routine password change).
	 *
	 * @param string $id The suite ID
	 * @param string $encryptedPrivateKey The new encrypted private key
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-2
	 */
	#[NoAdminRequired]
	#[VaultKeyProofRequired(
		binds: ['encryptedPrivateKey'],
		subject: 'routeParam:id',
		purpose: VaultKeyProofService::PURPOSE_UPDATE_PRIVATE_KEY
	)]
	public function updatePrivateKey(string $id, string $encryptedPrivateKey): JSONResponse {
		try {
			$suite = $this->suiteService->getSuite($id);
			$this->validateOwnership(suite: $suite);

			$suite->setPrivateKey($encryptedPrivateKey);
			// A routine master-password change re-wraps the private key under a
			// new AES key, so every stored passkey unlock envelope now wraps a
			// dead key. Advance the epoch and mark those envelopes stale
			// (passkey-vault-login §D4).
			$suite->setUnlockKeyEpoch($suite->getUnlockKeyEpoch() + 1);
			$this->suiteService->updateSuite($suite);
			$this->passkeyService?->markStaleOnPasswordChange($suite->getOwnerId());

			return new JSONResponse(data: $suite->jsonSerialize());
		} catch (Exception $e) {
			return new JSONResponse(
				data: ['message' => $e->getMessage()],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}
	}//end updatePrivateKey()

	/**
	 * Revoke an EncryptionSuite.
	 *
	 * Revoking a user suite deletes its emergency-access recovery envelopes
	 * outright (the revocation listener runs clearForGrantorRevocation), and
	 * revocation is the last-resort route for an owner who lost their master
	 * password — exactly the owner most likely to still need their emergency
	 * contact. So the destruction is made a knowing choice: while a usable
	 * (non-invalidated) emergency contact exists, revocation is refused unless the
	 * caller passes $acceptEmergencyLoss, and the refusal surfaces the COUNT
	 * of usable contacts (never their identities, which stay grantor-private) so
	 * the administrator can decide. An emergency accessor must retrieve the
	 * secrets first, while the suite is still active.
	 *
	 * @param string $id The suite ID
	 * @param string $reason The revocation reason
	 * @param bool $acceptEmergencyLoss Proceed even though emergency access will be deleted
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) $acceptEmergencyLoss is a
	 *   knowing-consent flag carried in the POST body and bound by name by the
	 *   Nextcloud router, not a mode switch the caller toggles between two
	 *   behaviours: it only lifts the safeguard refusal. Splitting the method
	 *   would split the route and change the HTTP contract.
	 *
	 * @spec openspec/changes/migrate-emergency-access-on-rotation/specs/emergency-access/spec.md#requirement-envelope-invalidation-on-key-change
	 */
	#[NoAdminRequired]
	public function revoke(string $id, string $reason, bool $acceptEmergencyLoss = false): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$userId = $user->getUID();

		try {
			// AUTHORISATION, not authentication. The `$user === null` check
			// above only asks whether ANYONE is logged in; it does not ask
			// whether THIS user owns THIS suite. `revokeSuite()` records
			// `$revokedBy` as the audit actor and never compares it to the
			// suite's owner, so without the line below any authenticated user
			// could revoke any other user's suite by id — and revocation is
			// destructive: EncryptionSuiteRevokedListener hard-deletes every
			// ShareTarget where the owner is the recipient and promotes their
			// delegations to permanent. `show()` and `updatePrivateKey()`
			// already call this same helper; revoke() did not.
			$this->validateOwnership(suite: $this->suiteService->getSuite($id));

			// Refuse to silently destroy a still-usable break-glass path. The
			// envelope clear runs asynchronously in EmergencyAccessSuiteRevocation-
			// Listener, downstream of the event revokeSuite dispatches, so the
			// safeguard must gate HERE, before that call. Only the count crosses
			// the wire — the contacts' identities stay grantor-private.
			if ($acceptEmergencyLoss === false) {
				$usableContacts = $this->emergencyService->countUsableForGrantorSuite($id);
				if ($usableContacts > 0) {
					return new JSONResponse(
						data: [
							'error' => 'emergency_access_present',
							'usableEmergencyContacts' => $usableContacts,
							'message' => 'Revoking this suite permanently deletes its emergency access. '
								. 'Any emergency accessor must retrieve the secrets first, while the suite is still active. '
								. 'Confirm to proceed.',
						],
						statusCode: Http::STATUS_CONFLICT
					);
				}
			}

			$suite = $this->suiteService->revokeSuite(id: $id, reason: $reason, revokedBy: $userId);
			return new JSONResponse(data: $suite->jsonSerialize());
		} catch (RuntimeException $e) {
			return new JSONResponse(
				data: ['message' => $e->getMessage()],
				statusCode: Http::STATUS_FORBIDDEN
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(
				data: ['message' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}//end try
	}//end revoke()

	/**
	 * Reinstate a revoked EncryptionSuite (admin only).
	 *
	 * @param string $id The suite ID
	 *
	 * @AuthorizedAdminSetting(AdminSettings::class)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-2
	 */
	#[AuthorizedAdminSetting(AdminSettings::class)]
	public function reinstate(string $id): JSONResponse {
		$userId = $this->userSession->getUser()->getUID();

		try {
			$suite = $this->suiteService->reinstateSuite(id: $id, reinstatedBy: $userId);
			return new JSONResponse(data: $suite->jsonSerialize());
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(
				data: ['message' => $e->getMessage()],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}
	}//end reinstate()

	/**
	 * Initiate compromise recovery: create new suite and migration record.
	 *
	 * @param string $publicKey The PEM-encoded public key
	 * @param string $encryptedPrivateKey The encrypted private key
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-2
	 * @spec openspec/changes/implement-link-sharing/tasks.md#5.2
	 */
	#[NoAdminRequired]
	#[VaultKeyProofRequired(
		binds: ['publicKey', 'encryptedPrivateKey'],
		subject: 'active',
		purpose: VaultKeyProofService::PURPOSE_COMPROMISE_RECOVERY
	)]
	public function compromiseRecovery(
		string $publicKey,
		string $encryptedPrivateKey,
	): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$userId = $user->getUID();

		// Refuse a second rotation while one is still running. `create()` has
		// always had this guard; this method did not, and the gap is how a vault
		// ends up with secrets stranded on a suite that is neither endpoint of
		// the current migration: rotation 1 leaves A→B in progress, rotation 2
		// starts B→C, and whatever was still on A is now unreachable by any
		// resume, because resuming only ever walks the migration's own suite
		// pair. Those secrets cannot be recovered without the master password
		// of a generation the UI no longer asks for.
		if ($this->migrationService->isWriteLocked(ownerType: 'user', ownerId: $userId) === true) {
			return new JSONResponse(
				data: [
					'error' => 'migration_in_progress',
					'message' => 'A key rotation is already in progress. Resume or abort that migration '
						. 'before starting another — starting a second rotation now would leave the '
						. 'secrets it has not reached yet unrecoverable.',
				],
				statusCode: Http::STATUS_CONFLICT
			);
		}

		try {
			$oldSuite = $this->suiteService->getActiveSuite(ownerType: 'user', ownerId: $userId);

			// The old suite stays ACTIVE for the duration of the migration.
			// Marking it compromised here — as this method used to — made every
			// subsequent read of every secret throw SuiteBlockedException, which
			// locked the user out of the whole vault at exactly the moment they
			// needed to open it and change every password, and left the browser
			// unable to read the ciphertext it has to migrate. The terminal work
			// (markCompromised, link-share revocation, passkey deletion) now runs
			// in MigrationService::completeMigration once every store is migrated.
			//
			// createSuite() signs the certificate BEFORE inserting the suite row,
			// and signPublicKey() asserts the issued certificate carries the
			// submitted public key. So a certificate that does not carry the
			// browser's key aborts here with nothing written: no new suite, no
			// migration, and the old suite still active.
			$newSuite = $this->suiteService->createSuccessorSuite(
				ownerType: 'user',
				ownerId: $userId,
				publicKeyPem: $publicKey,
				encryptedPrivateKey: $encryptedPrivateKey
			);

			// Creating the migration IS the write lock: isWriteLocked() derives
			// from an in-progress migration. It also dispatches
			// SuiteMigrationStartedEvent, which locks pending SecretRequests via
			// SuiteMigrationStartedListener.
			$migration = $this->migrationService->initiateCompromiseRecovery(
				oldSuiteId: $oldSuite->getId(),
				newSuiteId: $newSuite->getId()
			);

			return new JSONResponse(
				data: [
					'newSuite' => $newSuite->jsonSerialize(),
					'migration' => $migration->jsonSerialize(),
					'oldEncryptedPrivateKey' => $oldSuite->getPrivateKey(),
				],
				statusCode: Http::STATUS_CREATED
			);
		} catch (RuntimeException $e) {
			// Certificate precondition failure. Distinct from a generic fault
			// because the vault is provably untouched — nothing was written, so
			// the client can safely retry — and because proceeding would have
			// sealed every migrated record to a key nobody holds.
			if (str_contains($e->getMessage(), 'does not carry the submitted public key') === true) {
				return new JSONResponse(
					data: [
						'error' => 'certificate_key_mismatch',
						'message' => 'The issued certificate does not carry your public key. '
							. 'Key rotation was aborted and your vault is unchanged.',
					],
					statusCode: Http::STATUS_CONFLICT
				);
			}

			return new JSONResponse(
				data: ['message' => $e->getMessage()],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		} catch (Exception $e) {
			return new JSONResponse(
				data: ['message' => $e->getMessage()],
				statusCode: Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try
	}//end compromiseRecovery()

	/**
	 * Issue a vault-key-proof challenge for one of the guarded operations.
	 *
	 * Returns a stateless, expiring nonce the client signs with its suite
	 * private key to authorise a destructive operation. Requires only a session
	 * and that the caller own the named suite; it is NOT itself guarded, since a
	 * challenge grants nothing on its own.
	 *
	 * @param string $id The caller's suite the proof will be made with
	 * @param string|null $purpose The operation the challenge authorises
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/harden-vault-key-material-guards/specs/vault-key-proof/spec.md#requirement-challenges-are-stateless-and-expiring
	 */
	#[NoAdminRequired]
	public function proofChallenge(string $id, ?string $purpose = null): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		if ($purpose === null || in_array($purpose, VaultKeyProofService::ALLOWED_PURPOSES, true) === false) {
			return new JSONResponse(
				data: ['message' => 'Unknown or missing proof purpose'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$suite = $this->suiteService->getSuite($id);
			$this->validateOwnership(suite: $suite);
		} catch (Exception $e) {
			return new JSONResponse(
				data: ['message' => $e->getMessage()],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		return new JSONResponse(
			data: $this->proofService->issueChallenge(userId: $user->getUID(), purpose: $purpose)
		);
	}//end proofChallenge()

	/**
	 * Validate that the current user owns the suite (or is admin).
	 *
	 * @param mixed $suite The encryption suite entity
	 *
	 * @return void
	 */
	private function validateOwnership($suite): void {
		$userId = $this->userSession->getUser()->getUID();
		if ($suite->getOwnerType() === 'user' && $suite->getOwnerId() !== $userId) {
			throw new RuntimeException('Access denied: suite belongs to another user');
		}
	}//end validateOwnership()
}//end class
