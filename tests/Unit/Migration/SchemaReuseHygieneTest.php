<?php

/**
 * Source-level and behavioural invariants for migration schema reuse.
 *
 * `OC\DB\MigrationService::migrateSchemaOnly()` threads a single schema
 * snapshot through every migration in one run:
 *
 * ```php
 * $toSchema = null;
 * foreach ($toBeExecuted as $version) {
 *     $toSchema = $instance->changeSchema($output, function () use ($toSchema) {
 *         return $toSchema ?: new SchemaWrapper($this->connection);
 *     }, $options) ?: $toSchema;
 * }
 * ```
 *
 * The reuse only happens when each step hands the snapshot back. A step that
 * returns `null` leaves `$toSchema` unset, so the next step's closure
 * introspects the WHOLE database again — every table, column, index and
 * foreign key, not just this app's. Doctrine's schema graph is cyclic, so
 * those snapshots are not reclaimed promptly and the run accumulates one per
 * step until PHP's memory_limit is hit.
 *
 * That is not theoretical: on an instance carrying ~360 tables a snapshot
 * costs ~21 MB, and `occ app:enable keepiq` died of memory exhaustion partway
 * through the migration list. Returning `$schema` from the idempotency guards
 * instead of `null` keeps the whole run on one snapshot.
 *
 * The trap is that `return null;` looks tidier and is what most Nextcloud
 * migration examples show, so it invites being "cleaned up" back. This suite
 * makes that revert fail.
 *
 * It scans source as well as exercising behaviour, the same shape as
 * {@see \OCA\Keepiq\Tests\Unit\SuppressionHygieneTest}.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Migration
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

namespace OCA\Keepiq\Tests\Unit\Migration;

use OCA\Keepiq\Migration\Version000018Date20260707000000;
use OCA\Keepiq\Migration\Version000033Date20260817120000;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * Tests that every migration step hands its schema snapshot back.
 */
class SchemaReuseHygieneTest extends TestCase {

	/**
	 * Absolute path to the app's lib/Migration/ directory.
	 *
	 * @return string The migration path.
	 */
	private function migrationPath(): string {
		return dirname(__DIR__, 3) . '/lib/Migration';
	}//end migrationPath()

	/**
	 * Every migration file, as absolute paths.
	 *
	 * @return string[] The file paths.
	 */
	private function migrationFiles(): array {
		$files = glob($this->migrationPath() . '/Version*.php');
		if ($files === false) {
			return [];
		}

		sort($files);

		return $files;
	}//end migrationFiles()

	/**
	 * The body of a file's changeSchema() method, as numbered lines.
	 *
	 * Keyed by 1-based line number in the original file so offenders can be
	 * reported at a location a reader can jump to.
	 *
	 * @param string $file Absolute path to the migration file.
	 *
	 * @return array<int,string> The method body lines.
	 */
	private function changeSchemaBody(string $file): array {
		$lines = (array)file($file, (FILE_IGNORE_NEW_LINES));
		$body = [];
		$inside = false;

		foreach ($lines as $index => $line) {
			if (preg_match('/function\s+([A-Za-z_]\w*)\s*\(/', (string)$line, $matches) === 1) {
				$inside = ($matches[1] === 'changeSchema');
				continue;
			}

			if ($inside === true) {
				$body[($index + 1)] = (string)$line;
			}
		}

		return $body;
	}//end changeSchemaBody()

	/**
	 * The scan must actually reach the migration tree.
	 *
	 * Without this, a wrong path would make every assertion below pass
	 * vacuously — an empty scan and a clean one look identical.
	 *
	 * @return void
	 */
	public function testScanReachesTheMigrationTree(): void {
		$files = $this->migrationFiles();

		$this->assertGreaterThan(10, count($files), 'lib/Migration/ scan returned an implausibly small file list');

		$withGuards = 0;
		foreach ($files as $file) {
			$body = $this->changeSchemaBody($file);
			if ($body === []) {
				continue;
			}

			if (preg_match('/^\s*return \$schema;\s*$/m', implode("\n", $body)) === 1) {
				$withGuards++;
			}
		}

		// Nearly every step carries at least one idempotency guard, so a zero
		// here means the body extraction is broken, not that the guards went away.
		$this->assertGreaterThan(0, $withGuards, 'no schema-returning guards found at all — the scan is not working');

	}//end testScanReachesTheMigrationTree()

	/**
	 * No changeSchema() returns null.
	 *
	 * A null return drops the shared snapshot and forces the next step to
	 * introspect the entire database again.
	 *
	 * @return void
	 */
	public function testNoChangeSchemaReturnsNull(): void {
		$offenders = [];

		foreach ($this->migrationFiles() as $file) {
			foreach ($this->changeSchemaBody($file) as $number => $line) {
				if (preg_match('/^\s*return\s+null\s*;\s*$/', $line) !== 1) {
					continue;
				}

				$offenders[] = 'lib/Migration/' . basename($file) . ':' . $number;
			}
		}

		$this->assertSame(
			[],
			$offenders,
			"changeSchema() must return \$schema, not null — a null return drops the shared\n"
			. "schema snapshot and makes the next migration re-introspect the whole database:\n"
			. implode("\n", $offenders)
		);

	}//end testNoChangeSchemaReturnsNull()

	/**
	 * Every return inside changeSchema() hands back the schema.
	 *
	 * Broader than the null check: it also catches a guard that returns some
	 * other value, or a bare `return;`.
	 *
	 * @return void
	 */
	public function testEveryChangeSchemaReturnHandsBackTheSchema(): void {
		$offenders = [];

		foreach ($this->migrationFiles() as $file) {
			foreach ($this->changeSchemaBody($file) as $number => $line) {
				if (preg_match('/^\s*return\b(.*);\s*$/', $line, $matches) !== 1) {
					continue;
				}

				if (trim((string)$matches[1]) === '$schema') {
					continue;
				}

				$offenders[] = 'lib/Migration/' . basename($file) . ':' . $number . ' — ' . trim($line);
			}
		}

		$this->assertSame(
			[],
			$offenders,
			"every return in changeSchema() must be `return \$schema;`:\n" . implode("\n", $offenders)
		);

	}//end testEveryChangeSchemaReturnHandsBackTheSchema()

	/**
	 * A guard that fires because the table already exists returns the schema.
	 *
	 * Migration 18 creates `doriath_emergency_contacts` and short-circuits when
	 * it is already there — the common re-run path.
	 *
	 * @return void
	 */
	public function testGuardOnExistingTableReturnsTheSameSchemaInstance(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);

		$migration = new Version000018Date20260707000000();

		$result = $migration->changeSchema(
			$this->createMock(IOutput::class),
			static fn (): ISchemaWrapper => $schema,
			['tablePrefix' => 'oc_']
		);

		$this->assertSame($schema, $result, 'the already-applied path must hand the snapshot back');

	}//end testGuardOnExistingTableReturnsTheSameSchemaInstance()

	/**
	 * A guard that fires because the table is absent returns the schema.
	 *
	 * Migration 33 alters `doriath_secrets` and short-circuits when that table
	 * is missing — the opposite guard direction from migration 18, so both
	 * early-exit shapes are covered.
	 *
	 * @return void
	 */
	public function testGuardOnMissingTableReturnsTheSameSchemaInstance(): void {
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(false);

		$migration = new Version000033Date20260817120000();

		$result = $migration->changeSchema(
			$this->createMock(IOutput::class),
			static fn (): ISchemaWrapper => $schema,
			['tablePrefix' => 'oc_']
		);

		$this->assertSame($schema, $result, 'the not-applicable path must hand the snapshot back');

	}//end testGuardOnMissingTableReturnsTheSameSchemaInstance()
}//end class
