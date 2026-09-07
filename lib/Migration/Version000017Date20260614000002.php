<?php

/**
 * Keepiq Migration Version 17
 *
 * Add the tombstone columns to doriath_secrets — `tombstoned_at` (nullable
 * datetime) and `tombstone_reason` (nullable string). These mark a recipient's
 * share-copy as detached after the sharer's account was deleted
 * (secret-export-gdpr design D6 / D4 step 2). They are display metadata only:
 * the recipient fully owns the copy and access is never restricted by them.
 * No personal data of the deleted sharer is ever written here.
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
 * Add tombstone metadata columns to doriath_secrets.
 *
 * Additive and inert: two nullable columns. A detached recipient copy is an
 * ordinary secret the recipient owns; these fields only let the UI badge it and
 * future cleanup policies find it, without a join table (design D6).
 */
class Version000017Date20260614000002 extends SimpleMigrationStep {
	/**
	 * Add the tombstone columns.
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
		$changed = false;

		if ($table->hasColumn('tombstoned_at') === false) {
			$table->addColumn('tombstoned_at', Types::DATETIME, ['notnull' => false]);
			$changed = true;
		}

		if ($table->hasColumn('tombstone_reason') === false) {
			$table->addColumn('tombstone_reason', Types::STRING, ['notnull' => false, 'length' => 64]);
			$changed = true;
		}

		if ($changed === false) {
			return $schema;
		}

		return $schema;
	}//end changeSchema()
}//end class
