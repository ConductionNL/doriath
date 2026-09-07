<?php

/**
 * Keepiq Migration - Passkey vault login
 *
 * Adds `keepiq_passkey_credentials` (WebAuthn PRF unlock envelopes,
 * passkey-vault-login §1.1) and an `unlock_key_epoch` column on
 * `keepiq_enc_suites` so a routine master-password change can mark
 * stored passkey envelopes stale (§D4). The wrapped unlock key is only
 * openable with the authenticator-held PRF secret the server never sees.
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
 * Creates the passkey-credential table + suite unlock-key epoch.
 */
class Version000031Date20260720060000 extends SimpleMigrationStep {
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

		if ($schema->hasTable('keepiq_passkey_credentials') === false) {
			$table = $schema->createTable('keepiq_passkey_credentials');
			$table->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('owner_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('credential_id', Types::TEXT, ['notnull' => true]);
			$table->addColumn('public_key', Types::TEXT, ['notnull' => false]);
			$table->addColumn('prf_salt', Types::TEXT, ['notnull' => true]);
			$table->addColumn('wrapped_unlock_key', Types::TEXT, ['notnull' => true]);
			$table->addColumn('unlock_key_epoch', Types::INTEGER, ['notnull' => true, 'default' => 1]);
			$table->addColumn('label', Types::STRING, ['notnull' => false, 'length' => 128]);
			$table->addColumn('transports', Types::STRING, ['notnull' => false, 'length' => 128]);
			$table->addColumn('aaguid', Types::STRING, ['notnull' => false, 'length' => 64]);
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'active']);
			$table->addColumn('last_used_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['owner_id', 'status'], 'keepiq_pk_owner_status');
			// The credential_id column is TEXT (base64url, variable length) — a plain
			// index prefix suffices for the per-owner uniqueness check done
			// in the mapper; a unique index over TEXT is not portable.
			$table->addIndex(['owner_id'], 'keepiq_pk_owner');
		}//end if

		if ($schema->hasTable('keepiq_enc_suites') === true) {
			$suites = $schema->getTable('keepiq_enc_suites');
			if ($suites->hasColumn('unlock_key_epoch') === false) {
				$suites->addColumn('unlock_key_epoch', Types::INTEGER, ['notnull' => true, 'default' => 1]);
			}
		}

		return $schema;
	}//end changeSchema()
}//end class
