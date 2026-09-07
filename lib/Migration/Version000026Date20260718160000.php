<?php

/**
 * Keepiq Migration - Team-folder member grades
 *
 * Adds the `grade` column (`read`|`write`, default `read`) to
 * `keepiq_team_folder_members` (folder-permission-grades §1.1). The
 * string default backfills every existing membership to `read`, which
 * is exactly the pre-grade behavior.
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
 * Adds the grade column to team-folder memberships.
 */
class Version000026Date20260718160000 extends SimpleMigrationStep {
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

		if ($schema->hasTable('keepiq_team_folder_members') === true) {
			$table = $schema->getTable('keepiq_team_folder_members');
			if ($table->hasColumn('grade') === false) {
				$table->addColumn('grade', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'read']);
			}
		}

		return $schema;
	}//end changeSchema()
}//end class
