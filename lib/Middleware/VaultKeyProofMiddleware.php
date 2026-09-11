<?php

/**
 * Keepiq Vault Key Proof Middleware
 *
 * Enforces the #[VaultKeyProofRequired] attribute. Before a guarded controller
 * method runs, it resolves the subject suite, collects the bound request
 * parameters, reads the proof headers, and delegates verification to
 * VaultKeyProofService. A method without the attribute is passed straight
 * through, so the guard is opt-in per route and cannot be satisfied by
 * controller code that forgets to call it.
 *
 * Deliberately unlike PasswordConfirmationMiddleware: it consults no
 * authentication backend and honours no token scope, so it behaves identically
 * on SSO, app-password and ordinary sessions. Its authority is the suite's key
 * material, not how the session was established — a destructive operation must
 * not be waived merely because of the login method.
 *
 * @category Middleware
 * @package  OCA\Keepiq\Middleware
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

namespace OCA\Keepiq\Middleware;

use OCA\Keepiq\Attribute\VaultKeyProofRequired;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\SuiteMigrationMapper;
use OCA\Keepiq\Exception\KeyProofRequiredException;
use OCA\Keepiq\Service\EncryptionSuiteService;
use OCA\Keepiq\Service\VaultKeyProofService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use OCP\IUserSession;
use ReflectionMethod;
use Throwable;

/**
 * Enforce #[VaultKeyProofRequired] on the annotated controller methods.
 */
class VaultKeyProofMiddleware extends Middleware {
	/**
	 * The header carrying the base64 signature.
	 */
	private const HEADER_PROOF = 'X-Keepiq-Key-Proof';

	/**
	 * The header echoing the challenge the proof was made over.
	 */
	private const HEADER_NONCE = 'X-Keepiq-Key-Proof-Nonce';

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The HTTP request
	 * @param IUserSession $userSession The session, for the acting user
	 * @param EncryptionSuiteService $suiteService Resolves the subject suite
	 * @param VaultKeyProofService $proofService Verifies the proof
	 * @param SuiteMigrationMapper $migrationMapper Resolves a migration's old suite
	 *
	 * @return void
	 */
	public function __construct(
		private IRequest $request,
		private IUserSession $userSession,
		private EncryptionSuiteService $suiteService,
		private VaultKeyProofService $proofService,
		private SuiteMigrationMapper $migrationMapper,
	) {
	}//end __construct()

	/**
	 * Verify the proof before a guarded method runs.
	 *
	 * @param Controller $controller The controller about to run
	 * @param string $methodName The method about to run
	 *
	 * @return void
	 *
	 * @throws KeyProofRequiredException When the guard is not satisfied
	 */
	public function beforeController($controller, $methodName): void {
		$attribute = $this->attributeFor(controller: $controller, methodName: $methodName);
		if ($attribute === null) {
			return;
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			// No session at all is an authentication problem, not a proof one;
			// the framework's own auth handling has already refused, but guard
			// against a null here rather than dereferencing it.
			throw new KeyProofRequiredException(message: 'Not authenticated');
		}

		$userId = $user->getUID();
		$certificate = $this->subjectCertificate(attribute: $attribute, userId: $userId);

		$boundValues = [];
		foreach ($attribute->getBinds() as $name) {
			$boundValues[] = (string)$this->request->getParam($name, '');
		}

		$this->proofService->verify(
			nonce: $this->request->getHeader(self::HEADER_NONCE),
			signatureB64: $this->request->getHeader(self::HEADER_PROOF),
			certificatePem: $certificate,
			userId: $userId,
			purpose: $attribute->getPurpose(),
			boundValues: $boundValues,
		);
	}//end beforeController()

	/**
	 * Translate a failed guard into a 403 the client can act on.
	 *
	 * @param Controller $controller The controller
	 * @param string $methodName The method
	 * @param Throwable $exception The raised exception
	 *
	 * @return JSONResponse
	 *
	 * @throws Throwable When the exception is not the guard's own (re-thrown)
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $controller and $methodName
	 *   are mandated by OCP\AppFramework\Middleware::afterException(), which this
	 *   overrides; only the exception is acted on.
	 */
	public function afterException($controller, $methodName, Throwable $exception): JSONResponse {
		if (($exception instanceof KeyProofRequiredException) === false) {
			throw $exception;
		}

		return new JSONResponse(
			data: [
				'error' => 'key_proof_required',
				'message' => $exception->getMessage(),
			],
			statusCode: Http::STATUS_FORBIDDEN
		);
	}//end afterException()

	/**
	 * The #[VaultKeyProofRequired] attribute on the method, or null.
	 *
	 * @param Controller $controller The controller
	 * @param string $methodName The method
	 *
	 * @return VaultKeyProofRequired|null
	 */
	private function attributeFor($controller, string $methodName): ?VaultKeyProofRequired {
		$reflection = new ReflectionMethod($controller, $methodName);
		$attributes = $reflection->getAttributes(VaultKeyProofRequired::class);
		if ($attributes === []) {
			return null;
		}

		return $attributes[0]->newInstance();
	}//end attributeFor()

	/**
	 * Resolve the certificate whose public key verifies the proof.
	 *
	 * @param VaultKeyProofRequired $attribute The guard declaration
	 * @param string $userId The acting user
	 *
	 * @return string The subject suite's certificate PEM
	 *
	 * @throws KeyProofRequiredException When the subject suite cannot be resolved
	 */
	private function subjectCertificate(VaultKeyProofRequired $attribute, string $userId): string {
		$subject = $attribute->getSubject();

		try {
			$suite = $this->resolveSubjectSuite(subject: $subject, userId: $userId);
		} catch (KeyProofRequiredException $e) {
			throw $e;
		} catch (Throwable $e) {
			throw new KeyProofRequiredException(message: 'No subject suite to verify against');
		}

		$certificate = $suite->getCertificate();
		if ($certificate === null || $certificate === '') {
			throw new KeyProofRequiredException(message: 'Subject suite has no certificate');
		}

		return $certificate;
	}//end subjectCertificate()

	/**
	 * Resolve the subject suite from the attribute's declaration.
	 *
	 * @param string $subject The subject declaration ('active' or 'routeParam:<name>')
	 * @param string $userId The acting user
	 *
	 * @return EncryptionSuite
	 *
	 * @throws KeyProofRequiredException When a named suite is not the caller's own
	 */
	private function resolveSubjectSuite(string $subject, string $userId): EncryptionSuite {
		if ($subject === 'migrationOldSuite') {
			// Completion proves the OLD key, not the new one: at completion both
			// suites are active so 'active' is ambiguous, and the old key is the
			// one both the initiate and resume clients already hold the password
			// for. Resolve it from the migration named by the route's `id`.
			$migration = $this->migrationMapper->findById((string)$this->request->getParam('id', ''));
			return $this->assertOwned(
				suite: $this->suiteService->getSuite($migration->getOldSuiteId()),
				userId: $userId
			);
		}

		if (str_starts_with($subject, 'routeParam:') === true) {
			$paramName = substr($subject, strlen('routeParam:'));
			return $this->assertOwned(
				suite: $this->suiteService->getSuite((string)$this->request->getParam($paramName, '')),
				userId: $userId
			);
		}

		return $this->assertOwned(
			suite: $this->suiteService->getActiveSuite(ownerType: 'user', ownerId: $userId),
			userId: $userId
		);
	}//end resolveSubjectSuite()

	/**
	 * Assert the resolved suite is the caller's own; a proof is always over the
	 * owner's key, never another user's or an application's.
	 *
	 * @param EncryptionSuite $suite The resolved suite
	 * @param string $userId The acting user
	 *
	 * @return EncryptionSuite
	 *
	 * @throws KeyProofRequiredException When the suite is not the caller's
	 */
	private function assertOwned(EncryptionSuite $suite, string $userId): EncryptionSuite {
		if ($suite->getOwnerType() !== 'user' || $suite->getOwnerId() !== $userId) {
			throw new KeyProofRequiredException(message: 'Subject suite is not yours');
		}

		return $suite;
	}//end assertOwned()
}//end class
