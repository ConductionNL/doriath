<?php

/**
 * Keepiq Migration - Machine secret leases
 *
 * Adds `keepiq_machine_leases` (short-lived access-grant records for the
 * bearer-authed machine API; machine-secret-leases §1.1) and
 * `keepiq_app_lease_policies` (per-application TTL/renewability
 * overrides). Leases govern access-grant LIFETIME only — the stored
 * ciphertext envelope is untouched.
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
 * Creates the machine-lease and lease-policy tables.
 */
class Version000023Date20260718120000 extends SimpleMigrationStep {
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

		if ($schema->hasTable('keepiq_machine_leases') === false) {
			$table = $schema->createTable('keepiq_machine_leases');
			$table->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('application_id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('secret_id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('scope', Types::STRING, ['notnull' => true, 'length' => 32, 'default' => 'read']);
			$table->addColumn('granted_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('expires_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('renewed_count', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('last_renewed_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'active']);
			$table->addColumn('revoked_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('revoked_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['application_id', 'status'], 'keepiq_ml_app_status');
			$table->addIndex(['secret_id'], 'keepiq_ml_secret');
			$table->addIndex(['expires_at'], 'keepiq_ml_expires');
		}

		if ($schema->hasTable('keepiq_app_lease_policies') === false) {
			$table = $schema->createTable('keepiq_app_lease_policies');
			$table->addColumn('application_id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('default_ttl_seconds', Types::INTEGER, ['notnull' => false]);
			$table->addColumn('max_ttl_seconds', Types::INTEGER, ['notnull' => false]);
			$table->addColumn('renewable', Types::BOOLEAN, ['notnull' => false]);
			$table->setPrimaryKey(['application_id']);
		}

		return $schema;
	}//end changeSchema()
}//end class
