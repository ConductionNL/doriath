<?php

/**
 * Keepiq Migration Version 16
 *
 * Add the key_updated_at column to doriath_secrets — the server-maintained
 * ciphertext-age field backing the password-health change (§1.1). It records
 * when a secret's encrypted `key` blob last changed, so a rename, folder move,
 * or other metadata edit does not silently un-stale an old password. The value
 * is backfilled from updated_at for existing rows; the column describes
 * ciphertext age only and is set without any decryption (password-health
 * design D4).
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
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add key_updated_at (ciphertext age) to doriath_secrets.
 *
 * Additive and inert: a nullable datetime backfilled from updated_at. The
 * password-health feature reads it client-side to flag stale credentials; the
 * server never decrypts to maintain it (password-health design D4).
 */
class Version000016Date20260614000001 extends SimpleMigrationStep {
	/**
	 * Constructor.
	 *
	 * @param IDBConnection $connection The database connection (post-schema backfill)
	 *
	 * @return void
	 */
	public function __construct(
		private IDBConnection $connection,
	) {
	}//end __construct()

	/**
	 * Add the key_updated_at column.
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

		if ($schema->hasTable('doriath_secrets') === false) {
			return $schema;
		}

		$table = $schema->getTable('doriath_secrets');
		if ($table->hasColumn('key_updated_at') === true) {
			return $schema;
		}

		$table->addColumn('key_updated_at', Types::DATETIME, ['notnull' => false]);

		return $schema;
	}//end changeSchema()

	/**
	 * Backfill key_updated_at from updated_at for existing rows.
	 *
	 * @param IOutput $output The output interface
	 * @param Closure $schemaClosure The schema closure
	 * @param array<string,mixed> $options Migration options
	 *
	 * @return void
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$qb = $this->connection->getQueryBuilder();
		$qb->update('doriath_secrets')
			->set('key_updated_at', 'updated_at')
			->where($qb->expr()->isNull('key_updated_at'));
		$qb->executeStatement();
	}//end postSchemaChange()
}//end class
