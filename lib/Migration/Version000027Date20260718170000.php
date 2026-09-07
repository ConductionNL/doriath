<?php

/**
 * Keepiq Migration - Compliance reports
 *
 * Adds `keepiq_compliance_reports` (compliance-reporting §1.1):
 * immutable metadata-only posture snapshots. The aggregate is counts
 * only — never a secret value, name, or ciphertext.
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
 * Creates the compliance-reports table.
 */
class Version000027Date20260718170000 extends SimpleMigrationStep {
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

		if ($schema->hasTable('keepiq_compliance_reports') === false) {
			$table = $schema->createTable('keepiq_compliance_reports');
			$table->addColumn('id', Types::STRING, ['notnull' => true, 'length' => 36]);
			$table->addColumn('generated_by', Types::STRING, ['notnull' => true, 'length' => 64]);
			$table->addColumn('generated_at', Types::DATETIME, ['notnull' => true]);
			$table->addColumn('app_version', Types::STRING, ['notnull' => true, 'length' => 32]);
			$table->addColumn('config_snapshot', Types::TEXT, ['notnull' => true]);
			$table->addColumn('aggregate', Types::TEXT, ['notnull' => true]);
			$table->setPrimaryKey(['id']);
			$table->addIndex(['generated_at'], 'keepiq_cr_generated');
		}

		return $schema;
	}//end changeSchema()
}//end class
