<?php

/**
 * Keepiq Migration Version 21
 *
 * Secret version history (secret-version-history §1.1). Creates
 * `keepiq_secret_versions`: immutable pre-update snapshots of a secret's
 * ciphertext fields. The canonical history lives with the owner's copy;
 * all sensitive fields remain ciphertext under the suite that wrapped
 * them (ADR-003) — the server never decrypts to snapshot.
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
 * Create the secret-versions table.
 */
class Version000021Date20260718100000 extends SimpleMigrationStep {
	/**
	 * Create keepiq_secret_versions.
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

		if ($schema->hasTable('keepiq_secret_versions') === true) {
			return null;
		}

		$table = $schema->createTable('keepiq_secret_versions');

		$table->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('secret_id', Types::STRING, ['notnull' => true, 'length' => 36]);
		$table->addColumn('version_number', Types::INTEGER, ['notnull' => true, 'default' => 0]);
		$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('url', Types::STRING, ['notnull' => false, 'length' => 2048]);
		$table->addColumn('key', Types::TEXT, ['notnull' => true]);
		$table->addColumn('login', Types::TEXT, ['notnull' => false]);
		$table->addColumn('additional_fields', Types::TEXT, ['notnull' => false]);
		$table->addColumn('encryption_suite_id', Types::STRING, ['notnull' => false, 'length' => 36]);
		$table->addColumn('actor_type', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('actor_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('created_at', Types::DATETIME, ['notnull' => false]);

		$table->setPrimaryKey(['id']);
		$table->addIndex(['secret_id'], 'keepiq_sv_secret_idx');
		$table->addUniqueIndex(['secret_id', 'version_number'], 'keepiq_sv_version_uniq');

		return $schema;
	}//end changeSchema()
}//end class
