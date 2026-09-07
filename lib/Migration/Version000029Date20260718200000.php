<?php

/**
 * Keepiq Migration - Certificate lifecycle
 *
 * Adds `keepiq_certificate_metadata` — client-parsed, NON-SECRET X.509
 * display metadata for encrypted certificate-type secrets
 * (certificate-lifecycle §1). Populated only by the owner's browser
 * after it decrypts and parses the PEM; the server never derives these
 * fields itself (ADR-003). No key material or ciphertext ever reaches
 * this table.
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
 * Creates the certificate-metadata table.
 */
class Version000029Date20260718200000 extends SimpleMigrationStep {
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

		if ($schema->hasTable('keepiq_certificate_metadata') === false) {
			$table = $schema->createTable('keepiq_certificate_metadata');
			$table->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('secret_id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('owner_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('subject', Types::TEXT, ['notnull' => false]);
			$table->addColumn('issuer', Types::TEXT, ['notnull' => false]);
			$table->addColumn('serial', Types::STRING, ['notnull' => false, 'length' => 128]);
			$table->addColumn('fingerprint_sha256', Types::STRING, ['notnull' => false, 'length' => 128]);
			$table->addColumn('not_before', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('not_after', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('parsed_at', Types::DATETIME, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addUniqueIndex(['secret_id'], 'keepiq_cm_secret');
			$table->addIndex(['owner_id'], 'keepiq_cm_owner');
		}

		return $schema;
	}//end changeSchema()
}//end class
