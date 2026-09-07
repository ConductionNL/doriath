<?php

/**
 * Keepiq Migration Version 14
 *
 * Create the doriath_secret_delegations table — backs the implement-user-sharing
 * §1.3 + §2.5/2.6 SecretDelegation entity.
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
 * Create the doriath_secret_delegations table.
 *
 * Smallest scaffold for the BLOCKED implement-user-sharing change §1.3.
 * Backs the SecretDelegation entity that records an original_owner
 * temporarily (or permanently) handing share/revoke authority over a
 * Secret to a delegated_to user. The DelegationService that drives the
 * lifecycle is gated behind the cross-cutting SecretDelegation +
 * DelegationService coordinated build.
 */
class Version000014Date20260612000000 extends SimpleMigrationStep {
	/**
	 * Change the database schema to add the secret_delegations table.
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

		if ($schema->hasTable('doriath_secret_delegations') === true) {
			return $schema;
		}

		$table = $schema->createTable('doriath_secret_delegations');

		$table->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('secret_id', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('original_owner_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('delegated_to', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('delegated_at', Types::DATETIME, ['notnull' => true]);
		$table->addColumn('initiated_by', Types::STRING, ['notnull' => true, 'length' => 64]);
		// NC 32's MigrationService forbids a NOT NULL BOOLEAN column (it cannot
		// safely store 0/false), so the flag is nullable with a false default.
		$table->addColumn('is_permanent', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
		$table->addColumn('made_permanent_at', Types::DATETIME, ['notnull' => false]);

		$table->setPrimaryKey(['id']);
		$table->addIndex(['secret_id'], 'doriath_sd_secret_idx');
		$table->addIndex(['original_owner_id'], 'doriath_sd_orig_owner_idx');
		$table->addIndex(['delegated_to'], 'doriath_sd_delegate_idx');

		return $schema;
	}//end changeSchema()
}//end class
