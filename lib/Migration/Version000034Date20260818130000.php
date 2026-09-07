<?php

/**
 * Keepiq Migration - Default the secret-version `key` column to empty string
 *
 * The same defect as Version000033, one table over, and reachable for the same
 * reason: `SecretVersion::$key` is declared `protected string $key = '';` while
 * the column was created NOT NULL with no default. Nextcloud's Entity setter
 * marks a field dirty only when the value CHANGES, so `setKey('')` on a fresh
 * entity is a no-op, QBMapper omits the column from the INSERT, and Postgres
 * rejects the row:
 *
 *   SQLSTATE[23502]: Not null violation: null value in column "key"
 *   of relation "oc_doriath_secret_versions" violates not-null constraint
 *
 * Nothing could hit it until a Secret could legitimately hold no value. Filling
 * a request placeholder is exactly that: `SecretService::update()` snapshots the
 * PRE-update row, whose `key` is empty, so the first fill of every placeholder
 * died here and the recipient saw "Unable to fulfil request" with a 500.
 *
 * Fixed at the schema rather than the call site because the trap is structural —
 * any future snapshot of a valueless row would fail identically, and a caller
 * cannot mark a field dirty (`markFieldUpdated` is protected on Entity). The
 * column stays NOT NULL, so a genuine null is still rejected.
 *
 * Version000033 did this for `doriath_secrets.key`. That fix should have prompted
 * a look at every other table with the same shape; it did not, and this is the
 * cost. `login` and the remaining value columns here are already nullable.
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
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Gives `doriath_secret_versions.key` an empty-string default.
 */
class Version000034Date20260818130000 extends SimpleMigrationStep {
	/**
	 * Apply the schema change.
	 *
	 * @param IOutput $output The migration output
	 * @param Closure(): ISchemaWrapper $schemaClosure The schema closure
	 * @param array<string,mixed> $options The migration options
	 *
	 * @return ISchemaWrapper|null
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();

		if ($schema->hasTable('doriath_secret_versions') === false) {
			return $schema;
		}

		$table = $schema->getTable('doriath_secret_versions');

		if ($table->hasColumn('key') === false) {
			return $schema;
		}

		$column = $table->getColumn('key');

		// Idempotent: re-running must not emit a redundant ALTER.
		if ($column->getDefault() === '') {
			return $schema;
		}

		$column->setDefault('');

		return $schema;
	}//end changeSchema()
}//end class
