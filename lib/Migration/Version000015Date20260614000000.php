<?php

/**
 * Keepiq Migration Version 15
 *
 * Create the keepiq_audit_log table — the append-only audit trail backing
 * the add-secret-audit-trail change (§1.1). One row per server-observable
 * secret operation: actor, event type, object reference, denormalized
 * non-sensitive object name, and a whitelisted metadata payload. Indexed for
 * the queries the AuditService actually runs (recent-by-actor, by-secret,
 * by-event-type, retention purge by occurred_at).
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
 * Create the keepiq_audit_log table.
 *
 * Append-only at the application surface (add-secret-audit-trail §1.1): the
 * mapper exposes insert + scoped query + purge + anonymize only, never a
 * generic per-entry update or delete. Supersedes the never-built
 * keepiq_access_log sketched in the secrets-spec notes (design D4).
 */
class Version000015Date20260614000000 extends SimpleMigrationStep {
	/**
	 * Change the database schema to add the audit_log table.
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

		if ($schema->hasTable('keepiq_audit_log') === true) {
			return null;
		}

		$table = $schema->createTable('keepiq_audit_log');

		$table->addColumn('id', Types::BIGINT, ['notnull' => true, 'autoincrement' => true, 'unsigned' => true]);
		$table->addColumn('occurred_at', Types::DATETIME, ['notnull' => true]);
		$table->addColumn('actor_type', Types::STRING, ['notnull' => true, 'length' => 16]);
		$table->addColumn('actor_id', Types::STRING, ['notnull' => false, 'length' => 64]);
		$table->addColumn('event_type', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('object_type', Types::STRING, ['notnull' => true, 'length' => 32]);
		$table->addColumn('object_id', Types::STRING, ['notnull' => false, 'length' => 36]);
		$table->addColumn('object_name', Types::STRING, ['notnull' => false, 'length' => 255]);
		$table->addColumn('metadata', Types::TEXT, ['notnull' => false]);

		$table->setPrimaryKey(['id']);
		$table->addIndex(['occurred_at'], 'keepiq_al_occurred_idx');
		$table->addIndex(['actor_id'], 'keepiq_al_actor_idx');
		$table->addIndex(['object_type', 'object_id'], 'keepiq_al_object_idx');
		$table->addIndex(['event_type'], 'keepiq_al_event_idx');

		return $schema;
	}//end changeSchema()
}//end class
