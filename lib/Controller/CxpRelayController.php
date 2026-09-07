<?php

/**
 * Keepiq CxpRelayController
 *
 * A minimal, OPAQUE relay for the CXP (FIDO Credential Exchange Protocol)
 * browser-session handshake (cxp-transfer §2.2). Two cooperating Keepiq
 * sessions that cannot reach each other directly exchange the CXP request and
 * the HPKE-sealed envelope through this mailbox.
 *
 * The relay is deliberately blind: it stores and returns an opaque string
 * (public keys + HPKE ciphertext only — NEVER plaintext, never key material the
 * server could open with, per ADR-003). It never parses the payload. Slots live
 * in the distributed cache with a short TTL and are one-shot (consumed on read).
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

use OCA\Keepiq\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;

/**
 * Opaque mailbox for the CXP browser-session handshake.
 */
class CxpRelayController extends Controller {
	/**
	 * The relay slots. `request` carries the importer's CXP request; `response`
	 * carries the exporter's HPKE-sealed envelope.
	 *
	 * @var string[]
	 */
	private const SLOTS = ['request', 'response'];

	/**
	 * Slot time-to-live in seconds — a handshake is short-lived.
	 */
	private const TTL = 300;

	/**
	 * Maximum opaque payload size (bytes). A sealed CXF envelope is bounded; a
	 * generous cap prevents the relay being used as arbitrary storage.
	 */
	private const MAX_PAYLOAD = 4194304;

	/**
	 * The distributed cache backing the mailbox.
	 *
	 * @var ICache
	 */
	private ICache $cache;

	/**
	 * Constructor for CxpRelayController.
	 *
	 * @param IRequest $request The request
	 * @param ICacheFactory $cacheFactory The cache factory
	 * @param ISecureRandom $secureRandom The secure random generator
	 * @param IUserSession $userSession The user session
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		ICacheFactory $cacheFactory,
		private ISecureRandom $secureRandom,
		private IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		// Moved to the `keepiq_` prefix with the rest of the rename. The cost
		// is one-off and bounded: relay entries live TTL seconds and are
		// consumed on read, so only handshakes in flight across the deploy are
		// dropped, and an upgrade runs under maintenance mode anyway.
		$this->cache = $cacheFactory->createDistributed('keepiq_cxp_relay');
	}//end __construct()

	/**
	 * Build the cache key for a pairing/slot.
	 *
	 * @param string $pairingId The pairing id
	 * @param string $slot The slot name
	 *
	 * @return string
	 */
	private function key(string $pairingId, string $slot): string {
		return $pairingId . ':' . $slot;
	}//end key()

	/**
	 * Build the cache key that records who minted a pairing.
	 *
	 * @param string $pairingId The pairing id
	 *
	 * @return string
	 */
	private function pairingKey(string $pairingId): string {
		return 'pairing:' . $pairingId;
	}//end pairingKey()

	/**
	 * The UID that minted this pairing, or null if the relay never issued it
	 * (or it has expired).
	 *
	 * @param string $pairingId The pairing id
	 *
	 * @return string|null
	 */
	private function pairingOwner(string $pairingId): ?string {
		$owner = $this->cache->get($this->pairingKey(pairingId: $pairingId));

		if (is_string($owner) === true && $owner !== '') {
			return $owner;
		}

		return null;
	}//end pairingOwner()

	/**
	 * Authorize a relay operation on one pairing slot.
	 *
	 * THE PER-OBJECT AUTHORIZATION FOR THIS CONTROLLER, and it has to be
	 * written to the shape of the protocol rather than the usual
	 * "caller owns the row" test. Read `CxpTransferDialog.vue`: the IMPORTER
	 * posts with no pairing id, so the relay mints one and stores the
	 * importer's CXP request in the `request` slot; the pairing code is then
	 * read out to a DIFFERENT person, whose vault fetches `request` and
	 * writes the HPKE-sealed reply into `response`; the importer polls
	 * `response`. Two different Nextcloud accounts touch one pairing on
	 * purpose, so "the caller must be the owner" would break the feature.
	 *
	 * That leaves two questions that CAN be asked, and both were missing:
	 *
	 *  1. Did this relay ever issue this pairing id? Before this change
	 *     `put()` accepted any caller-chosen id, so any authenticated user
	 *     could write unlimited 4 MiB blobs under unlimited keys of their own
	 *     choosing — arbitrary attacker-keyed storage in the SHARED
	 *     distributed cache, with no handshake ever started.
	 *
	 *  2. For the `response` slot specifically: is the caller the importer
	 *     who started this transfer? Only they have the ephemeral private key
	 *     that can open the envelope, and only they ever read that slot. So
	 *     binding it costs the protocol nothing and means that someone who
	 *     merely overhears the pairing code — it travels by voice, chat or
	 *     ticket, out of band by design — can no longer race the importer and
	 *     CONSUME the sealed envelope, which the relay deletes on read. That
	 *     turns an eavesdropped code from a denial-of-service into nothing.
	 *
	 * Everything else stays capability-only, and that is deliberate rather
	 * than an omission. `$requireMinter` is passed by DIRECTION, not by slot
	 * name, because the peer writes `response` and reads `request` — binding
	 * either of those to the minter would break the handshake outright. Only
	 * ONE operation in the protocol is performed by the minter and by nobody
	 * else, and that is the read of `response`.
	 *
	 * @param string $pairingId The pairing id
	 * @param bool $requireMinter Whether the caller must be the minter
	 *
	 * @return JSONResponse|null A denial response, or null when authorized.
	 */
	private function denyUnauthorizedPairing(string $pairingId, bool $requireMinter): ?JSONResponse {
		$owner = $this->pairingOwner(pairingId: $pairingId);

		if ($owner === null) {
			// Not minted here, or expired. Answered 404 rather than 403 so the
			// status cannot be used to tell live pairing ids from dead ones.
			return new JSONResponse(data: ['error' => 'not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		if ($requireMinter === false) {
			return null;
		}

		$user = $this->userSession->getUser();
		if ($user === null || $user->getUID() !== $owner) {
			return new JSONResponse(
				data: ['error' => 'forbidden'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		return null;
	}//end denyUnauthorizedPairing()

	/**
	 * Store an opaque payload in a relay slot. If no pairing id is supplied a
	 * fresh one is minted (the importer's first request).
	 *
	 * @param string|null $pairingId The pairing id (null mints a new one)
	 * @param string $slot The slot (request|response)
	 * @param string $payload The opaque payload (public keys + sealed bytes)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/cxp-transfer/spec.md#requirement-keepiq-as-importing-provider
	 * @spec openspec/specs/cxp-transfer/spec.md#requirement-keepiq-as-exporting-provider
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function put(?string $pairingId = null, string $slot = '', string $payload = ''): JSONResponse {
		if (in_array($slot, self::SLOTS, true) === false) {
			return new JSONResponse(data: ['error' => 'invalid slot'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		if ($payload === '' || strlen($payload) > self::MAX_PAYLOAD) {
			return new JSONResponse(data: ['error' => 'invalid payload'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		$resolved = $this->resolvePairingForWrite(pairingId: $pairingId);
		if ($resolved instanceof JSONResponse) {
			return $resolved;
		}

		// Store the payload verbatim — the relay never inspects it.
		$this->cache->set($this->key(pairingId: $resolved, slot: $slot), $payload, self::TTL);

		return new JSONResponse(data: ['pairingId' => $resolved, 'slot' => $slot]);
	}//end put()

	/**
	 * Resolve the pairing a write should land in: mint a new one, or
	 * authorize the caller-supplied one.
	 *
	 * Split out of `put()` so the write has exactly one decision point and
	 * one happy path. Returns the pairing id to write into, or the
	 * JSONResponse that refuses the write.
	 *
	 * @param string|null $pairingId The caller-supplied pairing id, if any
	 *
	 * @return JSONResponse|string The refusal, or the pairing id to use.
	 */
	private function resolvePairingForWrite(?string $pairingId): JSONResponse|string {
		if ($pairingId === null || $pairingId === '') {
			return $this->mintPairing();
		}

		if (ctype_alnum($pairingId) === false || strlen($pairingId) > 64) {
			return new JSONResponse(data: ['error' => 'invalid pairing id'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		// A write into an EXISTING pairing is the peer's sealed reply, so it is
		// authorized by the pairing code alone — the relay cannot know the
		// peer's UID in advance. The mint check is what stops the shared cache
		// being writable without a handshake having started at all.
		$denial = $this->denyUnauthorizedPairing(pairingId: $pairingId, requireMinter: false);
		if ($denial !== null) {
			return $denial;
		}

		return $pairingId;
	}//end resolvePairingForWrite()

	/**
	 * Mint a fresh pairing for the importer's first request, recording WHO
	 * started the transfer so the sealed reply can be bound back to them.
	 *
	 * @return JSONResponse|string The refusal, or the new pairing id.
	 */
	private function mintPairing(): JSONResponse|string {
		$minter = $this->userSession->getUser();
		if ($minter === null) {
			return new JSONResponse(
				data: ['error' => 'unauthorized'],
				statusCode: Http::STATUS_UNAUTHORIZED
			);
		}

		$pairingId = $this->secureRandom->generate(24, ISecureRandom::CHAR_ALPHANUMERIC);
		$this->cache->set($this->pairingKey(pairingId: $pairingId), $minter->getUID(), self::TTL);

		return $pairingId;
	}//end mintPairing()

	/**
	 * Fetch and consume an opaque payload from a relay slot (one-shot).
	 *
	 * @param string $pairingId The pairing id
	 * @param string $slot The slot (request|response)
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/cxp-transfer/spec.md#requirement-keepiq-as-importing-provider
	 * @spec openspec/specs/cxp-transfer/spec.md#requirement-keepiq-as-exporting-provider
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function get(string $pairingId = '', string $slot = ''): JSONResponse {
		if (in_array($slot, self::SLOTS, true) === false || ctype_alnum($pairingId) === false) {
			return new JSONResponse(data: ['error' => 'invalid request'], statusCode: Http::STATUS_BAD_REQUEST);
		}

		// Reading `response` CONSUMES the sealed envelope, and only the
		// importer who minted this pairing can open it — so only they may take
		// it. `request` is read by the peer, whose UID is unknowable here.
		$denial = $this->denyUnauthorizedPairing(
			pairingId: $pairingId,
			requireMinter: ($slot === 'response')
		);
		if ($denial !== null) {
			return $denial;
		}

		$key = $this->key(pairingId: $pairingId, slot: $slot);
		$payload = $this->cache->get($key);
		if ($payload === null) {
			return new JSONResponse(data: ['error' => 'not found'], statusCode: Http::STATUS_NOT_FOUND);
		}

		// One-shot: remove on read so a sealed envelope is not left lingering.
		$this->cache->remove($key);

		return new JSONResponse(data: ['payload' => $payload]);
	}//end get()
}//end class
