<?php

/**
 * Keepiq Breach Proxy Controller
 *
 * A prefix-only proxy to the Have I Been Pwned (HIBP) range API, implementing
 * the server side of the k-anonymity breach-check flow (password-health §1.5,
 * design D5). The browser computes SHA-1 of a decrypted secret value, keeps the
 * 35-character suffix locally, and sends ONLY the first 5 hash characters here.
 * This controller forwards that 5-char prefix to HIBP (with response padding)
 * and returns the suffix list verbatim — the full hash and the plaintext value
 * never reach the server. The endpoint is double-gated: an instance-wide admin
 * toggle (`breach_check_enabled`, default off) and a per-user opt-in enforced
 * client-side. Responses are public breach data keyed by prefix and are cached
 * per prefix (no user association is ever logged).
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
use OCP\AppFramework\Http\DataResponse;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Prefix-only proxy to the HIBP range API (k-anonymity breach checking).
 */
class BreachProxyController extends Controller {
	/**
	 * The HIBP range API base URL. The 5-char prefix is appended.
	 *
	 * @var string
	 */
	private const HIBP_RANGE_URL = 'https://api.pwnedpasswords.com/range/';

	/**
	 * Cache TTL for a prefix response, in seconds (12 hours). Responses are
	 * public breach data keyed by prefix and carry no user association.
	 *
	 * @var int
	 */
	private const CACHE_TTL = 43200;

	/**
	 * HTTP request timeout to the upstream HIBP API, in seconds.
	 *
	 * @var int
	 */
	private const UPSTREAM_TIMEOUT = 5;

	/**
	 * The per-prefix response cache.
	 *
	 * NOT nullable. `ICacheFactory::createDistributed()` always returns an
	 * ICache — it hands back a no-op implementation when no distributed cache
	 * is configured rather than null — so the `?` this replaces described a
	 * state that could not occur, and the `null` case was never assigned.
	 *
	 * @var ICache
	 */
	private ICache $cache;

	/**
	 * Constructor for BreachProxyController.
	 *
	 * @param IRequest $request The HTTP request
	 * @param IAppConfig $appConfig The app config (admin gate)
	 * @param IClientService $clientService The HTTP client service
	 * @param ICacheFactory $cacheFactory The cache factory
	 * @param IUserSession $userSession The user session (auth posture)
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private IAppConfig $appConfig,
		private IClientService $clientService,
		ICacheFactory $cacheFactory,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		// Namespace deliberately still `keepiq_` after the doriath -> keepiq
		// rename: an ICache key prefix, not an app id. Renaming it would only
		// throw away a warm HIBP range cache. See appinfo/info.xml.
		$this->cache = $cacheFactory->createDistributed('keepiq_breach_range');
	}//end __construct()

	/**
	 * Forward a 5-character SHA-1 prefix to the HIBP range API and return the
	 * suffix list verbatim.
	 *
	 * Owner-scope: the endpoint is authenticated (`@NoAdminRequired` — any
	 * logged-in user) and operates only on the opaque 5-char prefix the caller
	 * supplies; it touches no per-user data, so there is no per-object record to
	 * guard. The admin gate is the access control: when `breach_check_enabled`
	 * is off the endpoint returns 403 for everyone.
	 *
	 * @param string $prefix The 5-character hexadecimal SHA-1 prefix
	 *
	 * @no-admin-idor-exempt `$prefix` is not an object reference. It is a
	 * 5-character SHA-1 prefix forwarded to HaveIBeenPwned's k-anonymity range
	 * API, chosen precisely so neither this app nor the upstream can tell which
	 * password was checked. There is no addressable record for a caller-scoping
	 * guard to compare against, so the "scope the object to the caller" fix
	 * gate-7's FAIL message prescribes has no subject here. The two refusals in
	 * the body are deliberately NOT authorization: the 401 is authentication
	 * (`.github#365`) and the 403 is an instance-wide `breach_check_enabled`
	 * feature flag that answers the same way for every caller — which is why
	 * gate-7 correctly stops treating that 403 as a guard once it requires a
	 * 403 to have consulted the caller.
	 *
	 * @NoAdminRequired
	 *
	 * @return DataResponse
	 *
	 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-opt-in-breach-checking-via-k-anonymity
	 */
	#[NoAdminRequired]
	public function range(string $prefix): DataResponse {
		if ($this->userSession->getUser() === null) {
			return new DataResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		// Gate 1 (instance-wide): the admin must have enabled breach checking.
		if ($this->appConfig->getValueBool(Application::APP_ID, 'breach_check_enabled', false) === false) {
			return new DataResponse(
				data: ['message' => 'Breach checking is disabled on this instance'],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		// Validate the prefix is EXACTLY 5 hexadecimal characters — never accept
		// a longer fragment that would narrow k-anonymity, and never a value.
		$prefix = strtoupper(trim($prefix));
		if (preg_match('/^[0-9A-F]{5}$/', $prefix) !== 1) {
			return new DataResponse(
				data: ['message' => 'prefix must be exactly 5 hexadecimal characters'],
				statusCode: Http::STATUS_BAD_REQUEST
			);
		}

		$cached = $this->cache->get($prefix);
		if (is_string($cached) === true) {
			return new DataResponse(data: ['suffixes' => $cached]);
		}

		try {
			$client = $this->clientService->newClient();
			$response = $client->get(
				self::HIBP_RANGE_URL . $prefix,
				[
					'timeout' => self::UPSTREAM_TIMEOUT,
					'headers' => ['Add-Padding' => 'true'],
				]
			);
			$body = (string)$response->getBody();
		} catch (Throwable $e) {
			// Soft-degrade: never log the prefix together with a user id (privacy).
			$this->logger->warning(
				'Keepiq: HIBP range lookup failed: ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return new DataResponse(
				data: ['message' => 'Breach service unavailable'],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		}//end try

		$this->cache->set($prefix, $body, self::CACHE_TTL);

		return new DataResponse(data: ['suffixes' => $body]);
	}//end range()
}//end class
