<?php

/**
 * Keepiq Migration - Ephemeral sends
 *
 * Adds `keepiq_ephemeral_sends` (ephemeral-send §1.1): ad-hoc one-time
 * shares that never touch the vault tables. The server stores only the
 * AES-256-GCM ciphertext; with a password only the Argon2id-wrapped
 * content key + salt are stored, and with no password the content key
 * lives in the URL fragment and never reaches the server (ADR-003).
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
 * Creates the ephemeral-sends table.
 */
class Version000025Date20260718150000 extends SimpleMigrationStep {
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

		if ($schema->hasTable('keepiq_ephemeral_sends') === false) {
			$table = $schema->createTable('keepiq_ephemeral_sends');
			$table->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('owner_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('token', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('encrypted_payload', Types::TEXT, ['notnull' => true]);
			$table->addColumn('payload_type', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'text']);
			$table->addColumn('has_password', Types::BOOLEAN, ['notnull' => false, 'default' => false]);
			$table->addColumn('wrapped_key', Types::TEXT, ['notnull' => false]);
			$table->addColumn('argon2id_salt', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('max_views', Types::INTEGER, ['notnull' => true, 'default' => 1]);
			$table->addColumn('view_count', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('expires_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('failed_attempts', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['token'], 'keepiq_es_token');
			$table->addIndex(['owner_id'], 'keepiq_es_owner');
			$table->addIndex(['expires_at'], 'keepiq_es_expires');
		}

		return $schema;
	}//end changeSchema()
}//end class
