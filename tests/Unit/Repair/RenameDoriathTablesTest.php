<?php

/**
 * Unit tests for the doriath_* -> keepiq_* rename repair step.
 *
 * The step issues raw DDL, runs before the migrations, and cannot be allowed
 * to throw: an escaping exception in a pre-migration step aborts the upgrade
 * and leaves the app half-migrated. These tests pin the three behaviours that
 * matter — it renames what exists, it refuses where both names exist, and it
 * survives a failing statement.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Repair
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

namespace OCA\Keepiq\Tests\Unit\Repair;

use OCA\Keepiq\Repair\RenameDoriathTables;
use OCP\DB\IResult;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests the rename repair step.
 */
class RenameDoriathTablesTest extends TestCase {

	/**
	 * Mocked database connection.
	 *
	 * @var IDBConnection&MockObject
	 */
	private $db;

	/**
	 * Mocked system configuration.
	 *
	 * @var IConfig&MockObject
	 */
	private $config;

	/**
	 * Statements the step issued, in order.
	 *
	 * @var string[]
	 */
	private array $statements = [];

	/**
	 * Warnings the step emitted.
	 *
	 * @var string[]
	 */
	private array $warnings = [];

	/**
	 * Build the mocks shared by every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->statements = [];
		$this->warnings = [];

		$this->config = $this->createMock(IConfig::class);
		$this->config->method('getSystemValueString')->willReturn('oc_');

		$this->db = $this->createMock(IDBConnection::class);
		$this->db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_POSTGRES);

		// No index or sequence rows: index/sequence renaming has its own
		// platform-specific paths and is not what these tests are pinning.
		$result = $this->createMock(IResult::class);
		$result->method('fetchFirstColumn')->willReturn([]);
		$this->db->method('executeQuery')->willReturn($result);

	}//end setUp()

	/**
	 * Build an IOutput that records what the step reports.
	 *
	 * @return IOutput&MockObject The recording output.
	 */
	private function recordingOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('warning')->willReturnCallback(function (string $m): void {
			$this->warnings[] = $m;
		});

		return $output;
	}//end recordingOutput()

	/**
	 * Record every statement the step issues.
	 *
	 * @return void
	 */
	private function recordStatements(): void {
		$this->db->method('executeStatement')->willReturnCallback(function (string $sql): int {
			$this->statements[] = $sql;

			return 0;
		});
	}//end recordStatements()

	/**
	 * A table that exists under the old name is renamed.
	 *
	 * @return void
	 */
	public function testRenamesATableThatStillCarriesTheOldName(): void {
		$this->db->method('tableExists')->willReturnCallback(
			static fn (string $t): bool => $t === 'doriath_secrets'
		);
		$this->recordStatements();

		(new RenameDoriathTables($this->db, $this->config))->run($this->recordingOutput());

		$this->assertSame(
			['ALTER TABLE "oc_doriath_secrets" RENAME TO "oc_keepiq_secrets"'],
			$this->statements
		);
		$this->assertSame([], $this->warnings);

	}//end testRenamesATableThatStillCarriesTheOldName()

	/**
	 * Nothing happens when no old table is present.
	 *
	 * This is the fresh-install and already-migrated case, and it must be a
	 * silent no-op rather than an error.
	 *
	 * @return void
	 */
	public function testDoesNothingWhenNoOldTablesExist(): void {
		$this->db->method('tableExists')->willReturn(false);
		$this->recordStatements();

		(new RenameDoriathTables($this->db, $this->config))->run($this->recordingOutput());

		$this->assertSame([], $this->statements);
		$this->assertSame([], $this->warnings);

	}//end testDoesNothingWhenNoOldTablesExist()

	/**
	 * Where both names exist the step refuses and reports.
	 *
	 * Renaming over an existing table would need a decision about which rows
	 * win, which is a data question, not a rename.
	 *
	 * @return void
	 */
	public function testRefusesWhenBothOldAndNewTablesExist(): void {
		$this->db->method('tableExists')->willReturnCallback(
			static fn (string $t): bool => in_array($t, ['doriath_secrets', 'keepiq_secrets'], true)
		);
		$this->recordStatements();

		(new RenameDoriathTables($this->db, $this->config))->run($this->recordingOutput());

		$this->assertSame([], $this->statements, 'no DDL may be issued for a collision');
		$this->assertCount(1, $this->warnings);
		$this->assertStringContainsString('doriath_secrets', $this->warnings[0]);

	}//end testRefusesWhenBothOldAndNewTablesExist()

	/**
	 * A failing statement is reported, not thrown.
	 *
	 * The step runs pre-migration, where an escaping exception aborts the
	 * whole upgrade.
	 *
	 * @return void
	 */
	public function testReportsRatherThanThrowsWhenAStatementFails(): void {
		$this->db->method('tableExists')->willReturnCallback(
			static fn (string $t): bool => $t === 'doriath_secrets'
		);
		$this->db->method('executeStatement')->willThrowException(new RuntimeException('permission denied'));

		(new RenameDoriathTables($this->db, $this->config))->run($this->recordingOutput());

		$this->assertCount(1, $this->warnings);
		$this->assertStringContainsString('permission denied', $this->warnings[0]);

	}//end testReportsRatherThanThrowsWhenAStatementFails()

	/**
	 * MySQL and MariaDB get backtick-quoted identifiers.
	 *
	 * Double quotes are only an identifier quote there under ANSI_QUOTES,
	 * which Nextcloud does not guarantee, so a double-quoted name would be
	 * parsed as a string literal and the DDL would fail.
	 *
	 * @return void
	 */
	public function testQuotesIdentifiersWithBackticksOnMysql(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_MYSQL);
		$db->method('tableExists')->willReturnCallback(
			static fn (string $t): bool => $t === 'doriath_secrets'
		);
		$result = $this->createMock(IResult::class);
		$result->method('fetchFirstColumn')->willReturn([]);
		$db->method('executeQuery')->willReturn($result);
		$db->method('executeStatement')->willReturnCallback(function (string $sql): int {
			$this->statements[] = $sql;

			return 0;
		});

		(new RenameDoriathTables($db, $this->config))->run($this->recordingOutput());

		$this->assertSame(
			['ALTER TABLE `oc_doriath_secrets` RENAME TO `oc_keepiq_secrets`'],
			$this->statements
		);

	}//end testQuotesIdentifiersWithBackticksOnMysql()
}//end class
