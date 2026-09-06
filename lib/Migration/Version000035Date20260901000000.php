<?php

/**
 * Keepiq Migration Version 35
 *
 * Add custom_icon and custom_color to the folders table (restyle Stage 9,
 * Proton-style vault personalization). Both are nullable KEY columns —
 * lowercase kebab identifiers resolved against the frontend's curated
 * catalogs — never free text or hex, so 64 characters is ample.
 *
 * @category Migration
 * @package  OCA\Keepiq\Migration
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
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
 * Add custom_icon and custom_color columns to the doriath_folders table
 * (table names kept their prefix through the app rename).
 */
class Version000035Date20260901000000 extends SimpleMigrationStep {
	/**
	 * Add the two nullable customization columns to the folders table.
	 *
	 * @param IOutput $output The output interface
	 * @param Closure $schemaClosure The schema closure
	 * @param array<string,mixed> $options Migration options
	 *
	 * @return null|ISchemaWrapper
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) $output and $options are
	 * mandated by SimpleMigrationStep::changeSchema(); this step reads only the
	 * schema closure.
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable('doriath_folders') === false) {
			return null;
		}

		$table = $schema->getTable('doriath_folders');
		$changed = false;

		if ($table->hasColumn('custom_icon') === false) {
			$table->addColumn(
				'custom_icon',
				Types::STRING,
				[
					'notnull' => false,
					'length' => 64,
				]
			);
			$changed = true;
		}

		if ($table->hasColumn('custom_color') === false) {
			$table->addColumn(
				'custom_color',
				Types::STRING,
				[
					'notnull' => false,
					'length' => 64,
				]
			);
			$changed = true;
		}

		if ($changed === false) {
			return null;
		}

		return $schema;
	}//end changeSchema()
}//end class
