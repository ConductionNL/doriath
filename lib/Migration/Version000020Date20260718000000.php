<?php

/**
 * Keepiq Migration Version 20
 *
 * Encrypted attachments (encrypted-attachments §1.1). Creates
 * `doriath_attachments` (one row per uploaded ciphertext blob, stored in
 * IAppData — filename/content-type ride ENCRYPTED in encrypted_metadata)
 * and `doriath_attachment_grants` (per-copy RSA-wrapped file key for the
 * owner and every recipient). The server never sees plaintext bytes, the
 * plaintext filename, or the file key (ADR-003).
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
 * Create the attachment + attachment-grant tables.
 */
class Version000020Date20260718000000 extends SimpleMigrationStep {
	/**
	 * Create doriath_attachments and doriath_attachment_grants.
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

		if ($schema->hasTable('doriath_attachments') === false) {
			$table = $schema->createTable('doriath_attachments');

			$table->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('source_secret_id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('blob_ref', Types::STRING, ['notnull' => true, 'length' => 128]);
			$table->addColumn('encrypted_metadata', Types::TEXT, ['notnull' => true]);
			$table->addColumn('size_bytes', Types::BIGINT, ['notnull' => true, 'default' => 0]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['source_secret_id'], 'doriath_att_secret_idx');
			$changed = true;
		}

		if ($schema->hasTable('doriath_attachment_grants') === false) {
			$table = $schema->createTable('doriath_attachment_grants');

			$table->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('attachment_id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('secret_id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('recipient_type', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('recipient_id', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('wrapped_file_key', Types::TEXT, ['notnull' => true]);
			$table->addColumn('encryption_suite_id', Types::STRING, ['notnull' => false, 'length' => 36]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => false]);

			$table->setPrimaryKey(['id']);
			$table->addIndex(['attachment_id'], 'doriath_attg_att_idx');
			$table->addIndex(['secret_id'], 'doriath_attg_secret_idx');
			$table->addIndex(['recipient_id'], 'doriath_attg_recipient_idx');
			$table->addUniqueIndex(['attachment_id', 'secret_id'], 'doriath_attg_copy_uniq');
			$changed = true;
		}

		if ($changed === false) {
			return $schema;
		}

		return $schema;
	}//end changeSchema()
}//end class
