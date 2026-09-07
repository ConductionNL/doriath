<?php

/**
 * Keepiq Migration - Default the secrets `key` column to the empty string
 *
 * Aligns the column with the entity contract it is supposed to mirror.
 * `Secret::$key` is declared `protected string $key = '';` — a non-null string
 * that MAY be empty — while the column was created NOT NULL with no default.
 *
 * That gap is only reachable through a Nextcloud Entity behaviour: the
 * generated setter marks a field dirty ONLY when the value actually changes,
 * and QBMapper::insert() builds its column list from the dirty fields. Calling
 * `setKey('')` on a fresh entity is therefore a no-op — `key` is omitted from
 * the INSERT entirely, Postgres looks for a default, finds none, and rejects
 * the row:
 *
 *   SQLSTATE[23502]: Not null violation: null value in column "key"
 *   of relation "oc_doriath_secrets" violates not-null constraint
 *
 * Nothing hit this until the machine secret-request surface, which creates a
 * deliberately keyless Secret shell: the human supplies the value later and the
 * server must never hold a plaintext one (ADR-003). On the user side the client
 * encrypts a placeholder, so `key` is ciphertext and the field always changes.
 *
 * The fix is at the schema rather than the call site because the trap is
 * structural: ANY future insert that legitimately leaves `key` at its entity
 * default would fail the same way, and a caller cannot mark a field dirty
 * (`markFieldUpdated` is protected on Entity). A default of '' makes the
 * omitted-column case store exactly what the entity says it holds. The column
 * stays NOT NULL, so a genuine null is still rejected.
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
 * Gives `doriath_secrets.key` an empty-string default.
 */
class Version000033Date20260817120000 extends SimpleMigrationStep {
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

		if ($schema->hasTable('doriath_secrets') === false) {
			return $schema;
		}

		$table = $schema->getTable('doriath_secrets');

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
