<?php

/**
 * Unit tests for the declarative observability descriptors in src/manifest.json
 * (apphost-adoption; ADR-006 / ADR-040).
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Observability
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Observability;

use PHPUnit\Framework\TestCase;

/**
 * Pins the observability block that OpenRegister's AppHost engine reads at
 * request time to serve /api/health and /api/metrics.
 *
 * WHY THIS TEST EXISTS.
 *
 * The scenarios in openspec/specs/apphost-adoption/spec.md were waived with
 * "@e2e exclude API-only endpoint — covered by the OR AppHost Newman contract
 * collection". That collection is real and correct, but it lives in the
 * OPENREGISTER repository and keepiq's CI never executes it, so nothing in
 * this repo asserted the contract. The observable HTTP surface is now covered
 * by tests/integration/keepiq.postman_collection.json folder 0, which runs in
 * keepiq's own CI (enable-newman: true).
 *
 * One property cannot be asserted from that HTTP surface: that the
 * suites_total gauge EXCLUDES non-active suites. Both the dev instance and a
 * fresh CI instance contain only active rows, so an equality assertion over
 * the API would pass whether or not the status filter existed — it would
 * measure the fixture, not the filter. That property is therefore asserted
 * here, against the descriptor that drives the generated query.
 *
 * This test pins CONFIGURATION, not engine behaviour. Executing the query is
 * OpenRegister's responsibility and is tested there.
 */
class ManifestObservabilityTest extends TestCase {
	/**
	 * Decoded src/manifest.json.
	 *
	 * @var array<string, mixed>
	 */
	private array $manifest = [];

	/**
	 * Load and decode the shipped manifest.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$path = __DIR__ . '/../../../src/manifest.json';
		$this->assertFileExists($path, 'src/manifest.json is missing');

		$raw = file_get_contents($path);
		$this->assertIsString($raw, 'src/manifest.json could not be read');

		$decoded = json_decode($raw, true);
		$this->assertIsArray($decoded, 'src/manifest.json is not valid JSON');

		$this->manifest = $decoded;

	}//end setUp()

	/**
	 * The observability block must exist — without it the aliased generic
	 * controllers have nothing to serve and both endpoints degrade silently.
	 *
	 * @return void
	 */
	public function testObservabilityBlockIsDeclared(): void {
		$this->assertArrayHasKey(
			'observability',
			$this->manifest,
			'no observability block: /api/health and /api/metrics have no descriptors to serve'
		);

	}//end testObservabilityBlockIsDeclared()

	/**
	 * Health declares exactly the two checks the spec requires, at the
	 * severities it requires. The severities are the whole point of the
	 * "degraded filesystem does not mask database health" scenario: if
	 * filesystem were critical, an unwritable temp dir would report the app
	 * down rather than degraded.
	 *
	 * @return void
	 */
	public function testHealthDeclaresDatabaseCriticalAndFilesystemDegraded(): void {
		$health = ($this->manifest['observability']['health'] ?? []);

		$this->assertSame(
			'adr006',
			($health['statusCodePolicy'] ?? null),
			'health must use the ADR-006 status-code policy'
		);

		$bySeverity = [];
		foreach (($health['checks'] ?? []) as $check) {
			$bySeverity[$check['id']] = [
				'type' => ($check['type'] ?? null),
				'severity' => ($check['severity'] ?? null),
			];
		}

		$this->assertSame(
			['database', 'filesystem'],
			array_keys($bySeverity),
			'health must declare exactly the database and filesystem checks'
		);
		$this->assertSame('database', $bySeverity['database']['type']);
		$this->assertSame('critical', $bySeverity['database']['severity']);
		$this->assertSame('filesystem', $bySeverity['filesystem']['type']);
		$this->assertSame(
			'degraded',
			$bySeverity['filesystem']['severity'],
			'a filesystem failure must degrade, not mask, database health'
		);

	}//end testHealthDeclaresDatabaseCriticalAndFilesystemDegraded()

	/**
	 * The suites_total gauge must count the encryption-suite table filtered to
	 * status = active.
	 *
	 * This is the assertion the HTTP surface cannot make: with only active
	 * rows present, a gauge computed WITHOUT the filter returns the same
	 * number as one computed with it, so the API can never distinguish them.
	 *
	 * @return void
	 */
	public function testSuitesTotalCountsOnlyActiveSuites(): void {
		$metrics = ($this->manifest['observability']['metrics'] ?? []);

		$suites = null;
		foreach ($metrics as $metric) {
			if (($metric['name'] ?? null) === 'suites_total') {
				$suites = $metric;
				break;
			}
		}

		$this->assertNotNull($suites, 'no suites_total metric is declared');
		$this->assertSame('gauge', ($suites['type'] ?? null));

		$source = ($suites['source'] ?? []);
		$this->assertSame(
			'tableCount',
			($source['kind'] ?? null),
			'suites_total must be a SQL COUNT(*), not a PHP count() over a fetch-all'
		);
		$this->assertSame('keepiq_enc_suites', ($source['table'] ?? null));

		$this->assertSame(
			['status' => ['eq' => 'active']],
			($source['filter'] ?? []),
			'suites_total must be filtered to status = active; without this filter '
			. 'revoked suites are counted and the gauge overstates the active total'
		);

	}//end testSuitesTotalCountsOnlyActiveSuites()

	/**
	 * No metric may expose a per-suite identifier or any owner. Metrics are
	 * scraped by admins but stored and forwarded widely; only aggregates may
	 * leave the instance (ADR-003 zero-knowledge).
	 *
	 * @return void
	 */
	public function testNoMetricExposesIdentifiersOrOwners(): void {
		$metrics = ($this->manifest['observability']['metrics'] ?? []);
		$this->assertNotEmpty($metrics, 'no metrics declared');

		foreach ($metrics as $metric) {
			$kind = ($metric['source']['kind'] ?? null);
			$this->assertSame(
				'tableCount',
				$kind,
				sprintf(
					'metric "%s" uses source kind "%s"; only aggregate tableCount sources are '
					. 'permitted, a row-returning source can leak suite identifiers or owners',
					($metric['name'] ?? '?'),
					(string)$kind
				)
			);

			$labels = array_keys(($metric['labels'] ?? []));
			foreach (['id', 'uuid', 'owner', 'ownerId', 'suiteId', 'certificate'] as $forbidden) {
				$this->assertNotContains(
					$forbidden,
					$labels,
					sprintf('metric "%s" carries a "%s" label', ($metric['name'] ?? '?'), $forbidden)
				);
			}
		}

	}//end testNoMetricExposesIdentifiersOrOwners()
}//end class
