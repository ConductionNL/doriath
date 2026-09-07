<?php

/**
 * Keepiq Migration - SIEM audit export
 *
 * Adds `keepiq_siem_sinks` (delivery targets with per-sink state) and
 * `keepiq_siem_queue` (bounded forwarding queue) — siem-audit-export
 * §1. Forwarded payloads are strict subsets of sanitized audit entries;
 * no secret material ever reaches these tables.
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
 * Creates the SIEM sink + queue tables.
 */
class Version000028Date20260718180000 extends SimpleMigrationStep {
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

		if ($schema->hasTable('keepiq_siem_sinks') === false) {
			$table = $schema->createTable('keepiq_siem_sinks');
			$table->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('name', Types::STRING, ['notnull' => true, 'length' => 128]);
			$table->addColumn('type', Types::STRING, ['notnull' => true, 'length' => 16]);
			$table->addColumn('enabled', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$table->addColumn('endpoint', Types::STRING, ['notnull' => true, 'length' => 512]);
			$table->addColumn('tls', Types::BOOLEAN, ['notnull' => false, 'default' => true]);
			$table->addColumn('hmac_secret_enc', Types::TEXT, ['notnull' => false]);
			$table->addColumn('category_filter', Types::TEXT, ['notnull' => false]);
			$table->addColumn('queue_cap', Types::INTEGER, ['notnull' => true, 'default' => 1000]);
			$table->addColumn('last_delivery_status', Types::STRING, ['notnull' => false, 'length' => 16]);
			$table->addColumn('last_success_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('last_attempt_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('last_error', Types::STRING, ['notnull' => false, 'length' => 512]);
			$table->addColumn('consecutive_failures', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('dropped_count', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('created_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('created_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('updated_at', Types::DATETIME, ['notnull' => false]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['enabled'], 'keepiq_ss_enabled');
		}//end if

		if ($schema->hasTable('keepiq_siem_queue') === false) {
			$table = $schema->createTable('keepiq_siem_queue');
			$table->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('sink_id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('payload', Types::TEXT, ['notnull' => true]);
			$table->addColumn('enqueued_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('status', Types::STRING, ['notnull' => true, 'length' => 16, 'default' => 'pending']);
			$table->addColumn('attempts', Types::INTEGER, ['notnull' => true, 'default' => 0]);
			$table->addColumn('next_attempt_at', Types::DATETIME, ['notnull' => false]);
			$table->addColumn('last_error', Types::STRING, ['notnull' => false, 'length' => 512]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['sink_id', 'status', 'next_attempt_at'], 'keepiq_sq_due');
		}

		return $schema;
	}//end changeSchema()
}//end class
