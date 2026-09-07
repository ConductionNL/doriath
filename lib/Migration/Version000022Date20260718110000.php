<?php

/**
 * Keepiq Migration Version 22
 *
 * Credential rotation policies + expiry reminders
 * (rotation-expiry-policies §1.1): nullable `expires_at` on
 * `keepiq_secrets`, per-type/per-folder expiry policies, and idempotent
 * one-open-flag-per-secret rotation flags. All server-visible metadata —
 * never ciphertext (ADR-003).
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
 * Create expiry-policy + rotation-flag tables and the expires_at column.
 */
class Version000022Date20260718110000 extends SimpleMigrationStep {
	/**
	 * Apply the schema changes.
	 *
	 * @param IOutput $output The output interface
	 * @param Closure $schemaClosure The schema closure
	 * @param array<string,mixed> $options Migration options
	 *
	 * @return null|ISchemaWrapper
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		// @var ISchemaWrapper $schema
		$schema = $schemaClosure();
		$changed = false;

		if ($schema->hasTable('keepiq_secrets') === true) {
			$table = $schema->getTable('keepiq_secrets');
			if ($table->hasColumn('expires_at') === false) {
				$table->addColumn('expires_at', Types::DATETIME, ['notnull' => false]);
				$table->addIndex(['expires_at'], 'keepiq_sec_expires_idx');
				$changed = true;
			}
		}

		if ($schema->hasTable('keepiq_expiry_policies') === false) {
			$table = $schema->createTable('keepiq_expiry_policies');

			$table->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('owner_id', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('scope', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('scope_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('max_age_days', Types::INTEGER, ['notnull' => false]);
			$table->addColumn('reminder_days', Types::TEXT, ['notnull' => false]);
			$table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['owner_id'], 'keepiq_ep_owner_idx');
			$table->addUniqueIndex(['owner_id', 'scope', 'scope_id'], 'keepiq_ep_scope_uniq');
			$changed = true;
		}

		if ($schema->hasTable('keepiq_rotation_flags') === false) {
			$table = $schema->createTable('keepiq_rotation_flags');

			$table->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('secret_id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('reason', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('flagged_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('flagged_by', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('resolved_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('key_updated_at_at_flag', Types::DATETIME, ['notnull' => false]);

			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['secret_id'], 'keepiq_rf_secret_uniq');
			$table->addIndex(['status'], 'keepiq_rf_status_idx');
			$changed = true;
		}

		if ($changed === false) {
			return null;
		}

		return $schema;
	}//end changeSchema()
}//end class
