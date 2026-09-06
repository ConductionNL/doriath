<?php

/**
 * Tests that nothing authenticated can ever be reached under /public.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\AppInfo
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

namespace OCA\Keepiq\Tests\Unit\AppInfo;

use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The `/public` URL space belongs to the anonymous recipient shell, and
 * `publicShell#pageCatchAll` (`/public/{path}`, `path => .+`) hands EVERY GET
 * under it to a `#[PublicPage]` controller.
 *
 * That catch-all is what makes the space dangerous to share. A future
 * authenticated sub-route mistakenly placed under `/public/…` would be
 * shadowed by it — the router matches the catch-all first and serves the
 * anonymous shell, so the intended `#[NoAdminRequired]`/session-gated handler
 * is never reached and its author sees a working page rather than a 404. No
 * data leaks through the shell itself (it renders the HTML template and
 * nothing else), so this is not a live vulnerability; it is a shape that turns
 * a routing mistake into a silent auth bypass instead of an error.
 *
 * A comment on the route entry cannot enforce that. This test can: it reads
 * the route table as source text and asserts that every `/public` route is
 * served by a method actually declaring `#[PublicPage]`. Adding an
 * authenticated endpoint under `/public/` now fails the unit suite, naming the
 * route.
 *
 * Deliberately NOT done instead: rejecting requests that carry a valid
 * Nextcloud session cookie. A logged-in user opening a recipient link is an
 * ordinary case — the sharer checking their own link, or a colleague who has
 * an account but no vault suite — and a session-based reject would break it.
 *
 * @see \OCA\Keepiq\Controller\PublicShellController
 */
class PublicRouteSurfaceContractTest extends TestCase {

	/**
	 * URL prefix that must be anonymous-only, matched on a segment boundary.
	 *
	 * @var string
	 */
	private const PUBLIC_URL_PREFIX = '/public';

	/**
	 * Parse `appinfo/routes.php` into `name => url` pairs.
	 *
	 * Source text rather than `require`: the file returns
	 * `\OCA\OpenRegister\AppHost\Routes::standard(...)` and the unit suite runs
	 * against the `nextcloud/ocp` stubs with no OpenRegister on the autoload
	 * path (see CanonicalRouteMethodContractTest, which reads it the same way).
	 * The canonical routes that call adds live at `/` and `/{path}`, so nothing
	 * it contributes falls inside the `/public` space this test guards.
	 *
	 * @return array<int, array{name: string, url: string}> Every parsed entry.
	 */
	private function parseRouteTable(): array {
		$source = file_get_contents(__DIR__ . '/../../../appinfo/routes.php');
		$this->assertIsString($source, 'appinfo/routes.php must be readable');

		$matches = [];
		preg_match_all(
			"/'name'\s*=>\s*'([^']+)'\s*,\s*'url'\s*=>\s*'([^']+)'/",
			$source,
			$matches,
			PREG_SET_ORDER
		);

		$routes = [];
		foreach ($matches as $match) {
			$routes[] = ['name' => $match[1], 'url' => $match[2]];
		}

		return $routes;
	}//end parseRouteTable()

	/**
	 * Resolve a `controller#method` route name to a reflected method.
	 *
	 * @param string $name The route name, e.g. `publicShell#pageCatchAll`.
	 *
	 * @return \ReflectionMethod|null The routed method, or null when unresolvable.
	 */
	private function reflectRoutedMethod(string $name): ?\ReflectionMethod {
		if (str_contains($name, '#') === false) {
			return null;
		}

		[$prefix, $method] = explode('#', $name, 2);
		$class = 'OCA\\Keepiq\\Controller\\' . ucfirst($prefix) . 'Controller';

		if (class_exists($class) === false) {
			return null;
		}

		$reflection = new ReflectionClass($class);
		if ($reflection->hasMethod($method) === false) {
			return null;
		}

		return $reflection->getMethod($method);
	}//end reflectRoutedMethod()

	/**
	 * The parse must actually see the route table, or every assertion below
	 * passes vacuously.
	 *
	 * @return void
	 */
	public function testTheRouteTableParses(): void {
		$routes = $this->parseRouteTable();

		$this->assertGreaterThan(
			100,
			count($routes),
			'The route-table parse found almost nothing, so the /public assertions '
			. 'below would pass without checking anything. The entry format in '
			. 'appinfo/routes.php probably changed — fix parseRouteTable().'
		);
	}//end testTheRouteTableParses()

	/**
	 * The `/public` space is not empty — the recipient shell lives there.
	 *
	 * @return void
	 */
	public function testThePublicShellIsRoutedUnderPublic(): void {
		$urls = [];
		foreach ($this->parseRouteTable() as $route) {
			if ($route['name'] === 'publicShell#page' || $route['name'] === 'publicShell#pageCatchAll') {
				$urls[$route['name']] = $route['url'];
			}
		}

		$this->assertSame(
			[
				'publicShell#page' => '/public',
				'publicShell#pageCatchAll' => '/public/{path}',
			],
			$urls,
			'The anonymous recipient shell must stay routed at /public and /public/{path}.'
		);
	}//end testThePublicShellIsRoutedUnderPublic()

	/**
	 * Every route under `/public` is served by a `#[PublicPage]` method.
	 *
	 * The catch-all shadows anything placed there, so an authenticated handler
	 * under `/public/` is served as the anonymous shell instead of erroring.
	 *
	 * @return void
	 */
	public function testEveryRouteUnderPublicIsDeclaredPublic(): void {
		$checked = 0;

		foreach ($this->parseRouteTable() as $route) {
			$url = $route['url'];
			$onPublicSurface = ($url === self::PUBLIC_URL_PREFIX
				|| str_starts_with($url, self::PUBLIC_URL_PREFIX . '/'));

			if ($onPublicSurface === false) {
				continue;
			}

			$checked++;
			$method = $this->reflectRoutedMethod($route['name']);

			$this->assertNotNull(
				$method,
				sprintf(
					'Route %s (%s) is in the /public space but its controller method could '
					. 'not be resolved, so its auth attributes cannot be verified.',
					$route['name'],
					$url
				)
			);

			$this->assertNotEmpty(
				$method->getAttributes(PublicPage::class),
				sprintf(
					'Route %s (%s) lives under %s, where publicShell#pageCatchAll matches '
					. 'first and serves the anonymous shell. An authenticated endpoint here '
					. 'is unreachable AND looks like it works — move it outside %s.',
					$route['name'],
					$url,
					self::PUBLIC_URL_PREFIX,
					self::PUBLIC_URL_PREFIX
				)
			);

			$this->assertNotEmpty(
				$method->getAttributes(NoCSRFRequired::class),
				sprintf(
					'Route %s (%s) is a #[PublicPage] with no #[NoCSRFRequired], so a '
					. 'recipient without a session cannot pass the CSRF check.',
					$route['name'],
					$url
				)
			);
		}

		$this->assertGreaterThanOrEqual(
			2,
			$checked,
			'Expected at least the shell page and its catch-all in the /public space.'
		);
	}//end testEveryRouteUnderPublicIsDeclaredPublic()

	/**
	 * Negative control: the assertion above must reject a route that is NOT
	 * `#[PublicPage]`, or it proves nothing about the ones that are.
	 *
	 * @return void
	 */
	public function testTheCheckActuallyRejectsANonPublicMethod(): void {
		$method = $this->reflectRoutedMethod('publicShell#page');
		$this->assertNotNull($method, 'publicShell#page must resolve for this control to mean anything.');
		$this->assertNotEmpty($method->getAttributes(PublicPage::class));

		// A session-gated method from the same app, as a stand-in for one
		// wrongly routed under /public.
		$authenticated = $this->reflectRoutedMethod('dashboard#summary');
		if ($authenticated === null) {
			$this->markTestSkipped('dashboard#summary is not leaf-owned in this build.');
		}

		$this->assertEmpty(
			$authenticated->getAttributes(PublicPage::class),
			'dashboard#summary must NOT be a #[PublicPage] — the control depends on it.'
		);
	}//end testTheCheckActuallyRejectsANonPublicMethod()
}//end class
