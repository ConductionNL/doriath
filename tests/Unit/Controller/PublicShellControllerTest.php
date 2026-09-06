<?php

/**
 * Unit tests for PublicShellController (ephemeral-send §5.3).
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

use OCA\Keepiq\Controller\PublicShellController;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * The anonymous SPA shell served to account-less recipients.
 *
 * Both methods render the SAME shell, and that identity is the thing under
 * test rather than an implementation detail. The recipient links are PATHS
 * (`/public/share/link/{token}`, `/public/send/{token}`,
 * `/public/share/request/{token}`) because the SPA routes with
 * `createWebHistory` and never reads a fragment, so every one of those paths —
 * on first load and on refresh — has to come back as the shell for the
 * client-side router to resolve. `page()` answers `/public` and
 * `pageCatchAll()` answers everything below it; a divergence between the two
 * would strand recipients on whichever half regressed, and nothing in the
 * route table would look wrong.
 *
 * @see \OCA\Keepiq\Tests\Unit\AppInfo\PublicRouteSurfaceContractTest for the
 *      companion assertion that nothing authenticated can live under /public.
 */
class PublicShellControllerTest extends TestCase {
	/**
	 * Build the controller with a mocked request.
	 *
	 * @return PublicShellController
	 */
	private function controller(): PublicShellController {
		return new PublicShellController(
			request: $this->createMock(originalClassName: IRequest::class)
		);
	}//end controller()

	/**
	 * page(): renders keepiq's `index` template under the BASE layout.
	 *
	 * RENDER_AS_BASE, not RENDER_AS_USER, is what makes the page account-less:
	 * the user layout renders the Nextcloud navigation and expects a session.
	 *
	 * @return void
	 */
	public function testPageRendersTheIndexTemplateAsBaseLayout(): void {
		$response = $this->controller()->page();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame(expected: 'keepiq', actual: $response->getApp());
		$this->assertSame(expected: 'index', actual: $response->getTemplateName());
		$this->assertSame(expected: TemplateResponse::RENDER_AS_BASE, actual: $response->getRenderAs());
		$this->assertSame(expected: [], actual: $response->getParams());
	}//end testPageRendersTheIndexTemplateAsBaseLayout()

	/**
	 * page(): the CSP relaxations the zero-knowledge flows actually need.
	 *
	 * The Argon2id key derivation runs as a WASM module, which is inert
	 * without 'wasm-unsafe-eval' — and it derives the key that decrypts the
	 * payload, so losing this directive breaks the recipient page completely
	 * rather than degrading it.
	 *
	 * @return void
	 */
	public function testPageAllowsTheArgon2idWasmModuleAndSelfWorkers(): void {
		$policy = $this->controller()->page()->getContentSecurityPolicy()->buildPolicy();

		$this->assertStringContainsString('wasm-unsafe-eval', $policy);
		$this->assertStringContainsString("worker-src 'self'", $policy);
	}//end testPageAllowsTheArgon2idWasmModuleAndSelfWorkers()

	/**
	 * The recipient subpaths that must each come back as the shell.
	 *
	 * These are the live link shapes built by LinkShareController::…link(),
	 * EphemeralSendController and
	 * ApplicationSecretRequestsController::fillLinkUrl — the values a
	 * recipient actually pastes into a browser.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function recipientPathProvider(): array {
		return [
			'link share'     => ['share/link/abc123'],
			'ephemeral send' => ['send/def456'],
			'fill request'   => ['share/request/ghi789'],
			'nested refresh' => ['send/def456/confirm'],
		];
	}//end recipientPathProvider()

	/**
	 * pageCatchAll(): every recipient subpath renders the identical shell.
	 *
	 * @param string $path The SPA subpath the recipient opened.
	 *
	 * @return void
	 *
	 * @dataProvider recipientPathProvider
	 */
	public function testPageCatchAllServesTheSameShellForEveryRecipientPath(string $path): void {
		$controller = $this->controller();
		$expected   = $controller->page();
		$actual     = $controller->pageCatchAll(path: $path);

		$this->assertInstanceOf(TemplateResponse::class, $actual);
		$this->assertSame(expected: $expected->getApp(), actual: $actual->getApp());
		$this->assertSame(expected: $expected->getTemplateName(), actual: $actual->getTemplateName());
		$this->assertSame(expected: $expected->getRenderAs(), actual: $actual->getRenderAs());
		$this->assertSame(
			expected: $expected->getContentSecurityPolicy()->buildPolicy(),
			actual: $actual->getContentSecurityPolicy()->buildPolicy()
		);
	}//end testPageCatchAllServesTheSameShellForEveryRecipientPath()

	/**
	 * pageCatchAll(): the subpath never reaches the template.
	 *
	 * The path is resolved client-side, so the shell is byte-identical for
	 * every recipient. Passing it through to the template would put
	 * attacker-supplied URL text into a rendered page for no gain — the
	 * `unset($path)` in the method is deliberate and this pins it.
	 *
	 * @return void
	 */
	public function testPageCatchAllKeepsTheSubpathOutOfTheTemplateParams(): void {
		$response = $this->controller()->pageCatchAll(path: 'share/link/<script>alert(1)</script>');

		$this->assertSame(expected: [], actual: $response->getParams());
	}//end testPageCatchAllKeepsTheSubpathOutOfTheTemplateParams()

	/**
	 * pageCatchAll(): the path argument is optional.
	 *
	 * The route requires `path => .+`, so an empty path should not be
	 * reachable through routing — but the default keeps a direct call (and any
	 * future route that relaxes the requirement) from being a TypeError.
	 *
	 * @return void
	 */
	public function testPageCatchAllDefaultsToTheBareShell(): void {
		$response = $this->controller()->pageCatchAll();

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame(expected: 'index', actual: $response->getTemplateName());
		$this->assertSame(expected: TemplateResponse::RENDER_AS_BASE, actual: $response->getRenderAs());
	}//end testPageCatchAllDefaultsToTheBareShell()
}//end class
