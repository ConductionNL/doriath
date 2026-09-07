<?php

/**
 * Keepiq Migration Version 18
 *
 * Create the `doriath_emergency_contacts` table backing the break-glass
 * emergency-access capability (add-emergency-access §1.2). One row per
 * (grantor, grantee) relationship: lifecycle state, the grantor-configured wait
 * period, the request timestamp, and the grantee-encrypted recovery envelope
 * (opaque ciphertext — the server never holds a usable key). No change to
 * `doriath_secrets`.
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
 * Create the emergency-contacts table.
 */
class Version000018Date20260707000000 extends SimpleMigrationStep {
	/**
	 * Create the doriath_emergency_contacts table if it does not exist.
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

		if ($schema->hasTable('doriath_emergency_contacts') === true) {
			return $schema;
		}

		$table = $schema->createTable('doriath_emergency_contacts');

		$table->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('grantor_user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('grantee_user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('access_level', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'view']);
		$table->addColumn('wait_period_days', Types::INTEGER, ['notnull' => true, 'default' => 7]);
		$table->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'granted']);
		$table->addColumn('requested_at', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('recovery_envelope', Types::TEXT, ['notnull' => false]);
		$table->addColumn('grantor_suite_id', Types::STRING, ['notnull' => false, 'length' => 36]);
		$table->addColumn('grantee_suite_id', Types::STRING, ['notnull' => false, 'length' => 36]);
		$table->addColumn('invalidated_reason', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
		$table->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);

		$table->setPrimaryKey(['id']);
		$table->addIndex(['grantor_user_id'], 'doriath_emc_grantor_idx');
		$table->addIndex(['grantee_user_id'], 'doriath_emc_grantee_idx');
		$table->addIndex(['state'], 'doriath_emc_state_idx');
		$table->addUniqueIndex(['grantor_user_id', 'grantee_user_id'], 'doriath_emc_pair_uniq');

		return $schema;
	}//end changeSchema()
}//end class
