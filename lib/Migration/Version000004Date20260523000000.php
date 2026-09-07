<?php

/**
 * Keepiq Migration Version 4
 *
 * Convert the `is_active` column on `keepiq_ca_certs` from SMALLINT
 * to BOOLEAN. The original column was declared as SMALLINT in
 * Version000002Date20260331000001 as a workaround for a perceived
 * "false maps to NULL on BOOLEAN NOT NULL" issue, but the CACertificate
 * entity declares `$isActive` as a PHP `bool` with `addType('isActive',
 * 'boolean')`. On PostgreSQL, the Doctrine boolean-type mapping then
 * sends the string `'t'` / `'f'` when persisting, which fails type
 * coercion on a SMALLINT column with:
 *   SQLSTATE[22P02]: Invalid text representation: invalid input
 *   syntax for type smallint: "t"
 *
 * This migration aligns the column type with the entity declaration so
 * `CertificateAuthorityService::generateIntermediate()` can successfully
 * insert an active intermediate certificate and the CA bootstrap can
 * complete on PostgreSQL.
 *
 * The same Version000002 migration has been updated to use BOOLEAN
 * directly for fresh installs; this migration handles existing
 * deployments where the column already exists as SMALLINT.
 *
 * PostgreSQL requires an explicit USING clause to cast SMALLINT to
 * BOOLEAN, and the Doctrine schema diff doesn't emit one — so the
 * conversion is performed via raw SQL in `preSchemaChange`, dialect
 * aware. MySQL/MariaDB are no-ops (TINYINT(1) is already the BOOLEAN
 * storage shape and Doctrine accepts both). SQLite is a no-op (the
 * column is INTEGER under both type aliases and Doctrine accepts 0/1
 * or 't'/'f' interchangeably). After the raw cast, the standard
 * schema diff in `changeSchema()` updates the column metadata to
 * match the new Version000002 declaration.
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
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Convert is_active column on keepiq_ca_certs from SMALLINT to BOOLEAN.
 */
class Version000004Date20260523000000 extends SimpleMigrationStep {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $connection The database connection
	 *
	 * @return void
	 */
	public function __construct(
		private IDBConnection $connection,
	) {
	}//end __construct()

	/**
	 * Perform a dialect-aware raw SQL conversion of the is_active column
	 * from SMALLINT to BOOLEAN before the schema diff runs. PostgreSQL
	 * requires an explicit USING clause; other dialects accept the
	 * schema-level diff in changeSchema() directly.
	 *
	 * @param IOutput $output The output interface
	 * @param Closure $schemaClosure The schema closure
	 * @param array<string,mixed> $options Migration options
	 *
	 * @return void
	 */
	public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// @var ISchemaWrapper $schema
		$schema = $schemaClosure();

		if ($schema->hasTable('keepiq_ca_certs') === false) {
			return;
		}

		$table = $schema->getTable('keepiq_ca_certs');
		if ($table->hasColumn('is_active') === false) {
			return;
		}

		// Skip when the column has already been migrated to BOOLEAN.
		if ($table->getColumn('is_active')->getType()->getName() === Types::BOOLEAN) {
			return;
		}

		$platform = $this->connection->getDatabasePlatform()->getName();
		if ($platform !== 'postgresql') {
			// MySQL/MariaDB TINYINT(1) ↔ BOOLEAN and SQLite INTEGER ↔
			// BOOLEAN are accepted by Doctrine without an explicit cast,
			// so the schema-level diff in changeSchema() handles them.
			return;
		}

		// PostgreSQL needs an explicit USING clause to cast smallint to
		// boolean. Run the conversion as raw SQL so the subsequent
		// schema diff finds the column already at BOOLEAN and emits a
		// no-op.
		$this->connection->executeStatement(
			'ALTER TABLE "*PREFIX*keepiq_ca_certs" '
			. 'ALTER COLUMN "is_active" DROP DEFAULT, '
			. 'ALTER COLUMN "is_active" TYPE BOOLEAN USING ("is_active" <> 0), '
			. 'ALTER COLUMN "is_active" SET DEFAULT FALSE'
		);

		$output->info('Keepiq: converted keepiq_ca_certs.is_active from SMALLINT to BOOLEAN');
	}//end preSchemaChange()

	/**
	 * Update the column metadata to match the new Version000002
	 * declaration. For PostgreSQL the column has already been converted
	 * in preSchemaChange so this emits a no-op; for MySQL/SQLite it
	 * runs the type change directly.
	 *
	 * @param IOutput $output The output interface
	 * @param Closure $schemaClosure The schema closure
	 * @param array<string,mixed> $options Migration options
	 *
	 * @return null|ISchemaWrapper
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) Doctrine\DBAL\Types\Type::getType() is a static
	 *   factory required by the DBAL API for type resolution — no instance-based alternative
	 *   exists.
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		// @var ISchemaWrapper $schema
		$schema = $schemaClosure();

		if ($schema->hasTable('keepiq_ca_certs') === false) {
			return null;
		}

		$table = $schema->getTable('keepiq_ca_certs');
		if ($table->hasColumn('is_active') === false) {
			return null;
		}

		$column = $table->getColumn('is_active');
		if ($column->getType()->getName() === Types::BOOLEAN) {
			return null;
		}

		$column->setType(\Doctrine\DBAL\Types\Type::getType(Types::BOOLEAN));
		$column->setDefault(false);

		return $schema;
	}//end changeSchema()
}//end class
