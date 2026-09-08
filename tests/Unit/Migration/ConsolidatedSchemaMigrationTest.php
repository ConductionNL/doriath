<?php

/**
 * Unit tests for the consolidated schema migration.
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

use InvalidArgumentException;
use OCA\Keepiq\Migration\Version001000Date20260908000000;
use OCP\DB\ISchemaWrapper;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * A stand-in for the Doctrine table object the schema hands back.
 *
 * Doctrine is only on the autoloader when this app sits inside a server
 * checkout, so a test that built a real Table would pass in CI and fail
 * on a standalone clone. ISchemaWrapper::getTable() declares no return
 * type, so a double keeps these tests hermetic in both places.
 */
final class FakeTable {
	/** @var array<int,string> */
	public array $columns = [];

	/** @var array<int,string> */
	public array $indexes = [];

	/** @var array<int,string> */
	public array $uniqueIndexes = [];

	/** @var array<int,string> */
	public array $primary = [];

	public bool $hasEverything = false;

	public function hasColumn(string $name): bool {
		return $this->hasEverything;
	}

	/**
	 * @param array<string,mixed> $options
	 */
	public function addColumn(string $name, string $type, array $options = []): void {
		$this->columns[] = $name;
	}

	public function hasPrimaryKey(): bool {
		return $this->hasEverything;
	}

	/**
	 * @param array<int,string> $columns
	 */
	public function setPrimaryKey(array $columns): void {
		$this->primary = $columns;
	}

	public function hasIndex(string $name): bool {
		return $this->hasEverything;
	}

	/**
	 * @param array<int,string> $columns
	 */
	public function addIndex(array $columns, string $name): void {
		$this->indexes[] = $name;
	}

	/**
	 * @param array<int,string> $columns
	 */
	public function addUniqueIndex(array $columns, string $name): void {
		$this->uniqueIndexes[] = $name;
	}
}

/**
 * Tests for Version001000Date20260908000000.
 */
class ConsolidatedSchemaMigrationTest extends TestCase {
	/**
	 * Read a private constant off the migration.
	 *
	 * @param string $name Constant name.
	 *
	 * @return mixed
	 */
	private function constant(string $name): mixed {
		return (new ReflectionClass(Version001000Date20260908000000::class))->getConstant($name);
	}

	/**
	 * Build the migration with the two doubles it needs.
	 *
	 * @param IDBConnection $db     Connection double.
	 * @param string        $prefix Table prefix the config reports.
	 *
	 * @return Version001000Date20260908000000
	 */
	private function migration(IDBConnection $db, string $prefix = 'oc_'): Version001000Date20260908000000 {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->willReturn($prefix);

		return new Version001000Date20260908000000($db, $config);
	}

	/**
	 * A fresh install gets every declared table, column and index.
	 *
	 * @return void
	 */
	public function testChangeSchemaBuildsTheWholeSchemaOnAFreshInstall(): void {
		$tables = [];
		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(false);
		$schema->method('createTable')->willReturnCallback(
			static function (string $name) use (&$tables): FakeTable {
				return $tables[$name] = new FakeTable();
			}
		);
		$schema->method('getTable')->willReturnCallback(
			static function (string $name) use (&$tables): FakeTable {
				return $tables[$name];
			}
		);

		$result = $this->migration($this->createMock(IDBConnection::class))
			->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);

		$this->assertSame($schema, $result);

		$declared = $this->constant('SCHEMA');
		$this->assertCount(count($declared), $tables, 'every declared table must be created');

		$columns = array_sum(array_map(static fn (FakeTable $t): int => count($t->columns), $tables));
		$indexes = array_sum(
			array_map(static fn (FakeTable $t): int => count($t->indexes) + count($t->uniqueIndexes), $tables)
		);
		$expectedColumns = array_sum(array_map(static fn (array $d): int => count($d['columns']), $declared));
		$expectedIndexes = array_sum(
			array_map(static fn (array $d): int => count($d['indexes']) + count($d['uniqueIndexes']), $declared)
		);

		$this->assertSame($expectedColumns, $columns);
		$this->assertSame($expectedIndexes, $indexes);

		foreach ($tables as $name => $table) {
			$this->assertNotSame([], $table->primary, $name.' must get a primary key');
			$this->assertStringStartsWith('keepiq_', $name, 'every table must carry the new prefix');
		}
	}

	/**
	 * A table that already carries everything is left untouched.
	 *
	 * @return void
	 */
	public function testChangeSchemaAddsNothingToATableThatIsAlreadyComplete(): void {
		$table = new FakeTable();
		$table->hasEverything = true;

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);
		$schema->expects($this->never())->method('createTable');
		$schema->method('getTable')->willReturn($table);

		$this->migration($this->createMock(IDBConnection::class))
			->changeSchema($this->createMock(IOutput::class), static fn () => $schema, []);

		$this->assertSame([], $table->columns);
		$this->assertSame([], $table->indexes);
		$this->assertSame([], $table->uniqueIndexes);
		$this->assertSame([], $table->primary);
	}

	/**
	 * Every legacy table still carrying the old prefix is renamed once.
	 *
	 * @return void
	 */
	public function testPreSchemaChangeRenamesEveryLegacyTable(): void {
		$statements = [];
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_SQLITE);
		$db->method('executeStatement')->willReturnCallback(
			static function (string $sql) use (&$statements): int {
				$statements[] = $sql;
				return 0;
			}
		);

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(
			static fn (string $name): bool => str_starts_with($name, 'doriath_')
		);

		$this->migration($db)->preSchemaChange($this->createMock(IOutput::class), static fn () => $schema, []);

		$this->assertCount(count($this->constant('TABLES')), $statements);
		foreach ($statements as $sql) {
			$this->assertStringContainsString('ALTER TABLE', $sql);
			$this->assertStringContainsString('*PREFIX*doriath_', $sql);
			$this->assertStringContainsString('*PREFIX*keepiq_', $sql);
		}
	}

	/**
	 * An install that has already been renamed is not renamed again.
	 *
	 * @return void
	 */
	public function testPreSchemaChangeIsANoopWhenTheNewNamesAlreadyExist(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->expects($this->never())->method('executeStatement');

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturn(true);

		$this->migration($db)->preSchemaChange($this->createMock(IOutput::class), static fn () => $schema, []);
	}

	/**
	 * MySQL takes RENAME TABLE, which is the one platform that does not take
	 * the ALTER TABLE form used everywhere else.
	 *
	 * @return void
	 */
	public function testMysqlUsesRenameTable(): void {
		$statements = [];
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_MYSQL);
		$db->method('executeStatement')->willReturnCallback(
			static function (string $sql) use (&$statements): int {
				$statements[] = $sql;
				return 0;
			}
		);
		$db->expects($this->never())->method('executeQuery');

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(
			static fn (string $name): bool => str_starts_with($name, 'doriath_')
		);

		$this->migration($db)->preSchemaChange($this->createMock(IOutput::class), static fn () => $schema, []);

		$this->assertNotSame([], $statements);
		foreach ($statements as $sql) {
			$this->assertStringStartsWith('RENAME TABLE', $sql);
		}
	}

	/**
	 * PostgreSQL also moves the sequence and the primary-key constraint, which
	 * a table rename leaves behind and the schema comparison does not fix.
	 *
	 * @return void
	 */
	public function testPostgresAlsoRenamesSequencesAndPrimaryKeys(): void {
		$statements = [];
		$db = $this->createMock(IDBConnection::class);
		$db->method('getDatabaseProvider')->willReturn(IDBConnection::PLATFORM_POSTGRES);
		$db->method('executeStatement')->willReturnCallback(
			static function (string $sql) use (&$statements): int {
				$statements[] = $sql;
				return 0;
			}
		);
		$db->method('executeQuery')->willReturnCallback(
			function (string $sql, array $params) {
				$rows = str_contains($sql, 'pg_sequences')
					? [['name' => 'oc_doriath_audit_log_id_seq']]
					: [['name' => 'oc_doriath_audit_log_pkey']];
				$result = $this->createMock(\OCP\DB\IResult::class);
				$result->method('fetchAll')->willReturn($rows);
				return $result;
			}
		);

		$schema = $this->createMock(ISchemaWrapper::class);
		$schema->method('hasTable')->willReturnCallback(
			static fn (string $name): bool => $name === 'doriath_audit_log'
		);

		$this->migration($db)->preSchemaChange($this->createMock(IOutput::class), static fn () => $schema, []);

		$joined = implode("\n", $statements);
		$this->assertStringContainsString('ALTER TABLE "*PREFIX*doriath_audit_log" RENAME TO "*PREFIX*keepiq_audit_log"', $joined);
		$this->assertStringContainsString('ALTER SEQUENCE "oc_doriath_audit_log_id_seq" RENAME TO "oc_keepiq_audit_log_id_seq"', $joined);
		$this->assertStringContainsString('RENAME CONSTRAINT "oc_doriath_audit_log_pkey" TO "oc_keepiq_audit_log_pkey"', $joined);
	}

	/**
	 * The identifier guard refuses a name that is not a bare identifier.
	 *
	 * @return void
	 */
	public function testANonIdentifierTableNameIsRefused(): void {
		$migration = $this->migration($this->createMock(IDBConnection::class));
		$rename = (new ReflectionClass($migration))->getMethod('renameTable');
		$rename->setAccessible(true);

		$this->expectException(InvalidArgumentException::class);
		$rename->invoke($migration, 'ok_name', 'bad"; DROP TABLE x; --');
	}

	/**
	 * The declared tables and the mapper table names are the same set.
	 *
	 * These are two independently maintained lists. A table renamed in the
	 * migration but not in its mapper leaves the mapper querying a table that
	 * does not exist, which is a runtime error rather than a failing build.
	 *
	 * @return void
	 */
	public function testTheDeclaredTablesMatchTheMapperTableNames(): void {
		$declared = array_map(
			static fn (string $suffix): string => 'keepiq_'.$suffix,
			array_keys($this->constant('SCHEMA'))
		);

		$fromMappers = [];
		foreach (glob(__DIR__.'/../../../lib/Db/*.php') ?: [] as $file) {
			if (preg_match_all("/tableName:\s*'([a-z0-9_]+)'/", (string) file_get_contents($file), $m) === 0) {
				continue;
			}

			$fromMappers = array_merge($fromMappers, $m[1]);
		}

		$fromMappers = array_values(array_unique($fromMappers));
		sort($declared);
		sort($fromMappers);

		$this->assertNotSame([], $fromMappers, 'the mappers must declare table names for this test to mean anything');
		$this->assertSame($declared, $fromMappers);
	}
}
