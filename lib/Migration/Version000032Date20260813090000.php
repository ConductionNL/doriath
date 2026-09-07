<?php

/**
 * Keepiq Migration - Per-record migration failure accounting
 *
 * Adds `keepiq_migration_failures`: one row per RECORD that a
 * compromise-recovery migration could not carry across.
 *
 * Before this, the single `migration_error` column on `keepiq_secrets` was
 * the accounting flag for the secret head PLUS every one of its versions and
 * attachment grants — up to `1 + N + M` independent records sharing one slot.
 * Three consequences, all key-loss or denial-of-termination:
 *
 *   - A version or grant whose secret head had already migrated was invisible
 *     to both completion gates: the unaccounted queries excluded it (its
 *     secret carried an error) while `findFailedBySuiteForOwner` did not
 *     return it (its secret was on the NEW suite), so the migration
 *     terminated and locked the old suite over readable-but-unmigrated rows.
 *   - Committing any sibling record cleared the column, erasing a failure
 *     that had already been recorded.
 *   - The acknowledgement compared a client-side per-RECORD count against a
 *     server-side distinct-SECRET count, so "Finish anyway" could never be
 *     satisfied and the vault stayed write-locked.
 *
 * Failures are scoped to a migration_id and deleted with it, so this table
 * holds only in-flight accounting and never grows unbounded. The unique index
 * over (migration_id, store, record_id) is what makes a retry idempotent
 * rather than accumulating a second entry for the same record.
 *
 * @category Migration
 * @package  OCA\Keepiq\Migration
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

namespace OCA\Keepiq\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates the per-record migration-failure table.
 */
class Version000032Date20260813090000 extends SimpleMigrationStep {
	/**
	 * Apply the schema changes.
	 *
	 * @param IOutput $output The migration output
	 * @param Closure(): ISchemaWrapper $schemaClosure The schema closure
	 * @param array<string,mixed> $options The migration options
	 *
	 * @return ISchemaWrapper|null
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if ($schema->hasTable('keepiq_migration_failures') === false) {
			$table = $schema->createTable('keepiq_migration_failures');
			$table->addColumn('id', Types::BIGINT, [
				'notnull' => true,
				'autoincrement' => true,
				'length' => 20,
			]);
			$table->addColumn('migration_id', Types::STRING, ['notnull' => true, 'length' => 36]);
			// One of the MigrationWorkService::STORE_* values: secrets,
			// versions, attachmentGrants.
			$table->addColumn('store', Types::STRING, ['notnull' => true, 'length' => 32]);
			// The failing record's own id, which is what makes the accounting
			// per-record rather than per-secret.
			$table->addColumn('record_id', Types::STRING, ['notnull' => true, 'length' => 36]);
			// The owning secret, carried so the acknowledgement list can name a
			// failed version or grant instead of rendering a blank row.
			$table->addColumn('secret_id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('message', Types::STRING, ['notnull' => false, 'length' => 1000]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			// Retrying a record must UPDATE its failure, not add a second one:
			// duplicate rows are what made the acknowledgement count drift.
			$table->addUniqueIndex(
				['migration_id', 'store', 'record_id'],
				'keepiq_mf_record'
			);
			// The completion gates count and list by migration.
			$table->addIndex(['migration_id'], 'keepiq_mf_migration');
			// Naming the affected secrets for the acknowledgement list.
			$table->addIndex(['migration_id', 'secret_id'], 'keepiq_mf_secret');
		}//end if

		return $schema;
	}//end changeSchema()
}//end class
