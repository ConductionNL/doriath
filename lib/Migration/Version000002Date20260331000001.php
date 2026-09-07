<?php

/**
 * Keepiq Migration Version 2
 *
 * Create the doriath_ca_certs table.
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
 * Create the doriath_ca_certs table.
 *
 * `notnull` MUST stay false on the `is_active` BOOLEAN column.
 *
 * Nextcloud's own database convention forbids `BOOLEAN NOT NULL`
 * (developer_manual/basics/storage/database.rst: "Columns with type bool ...
 * can not be NotNull as it can not store 0/false"), and on Nextcloud 31
 * `MigrationService::ensureOracleConstraints()` enforces it for EVERY platform
 * with a hard throw:
 *
 * Column "oc_doriath_ca_certs"."is_active" is type Bool and also NotNull, so
 * it can not store "false".
 *
 * With `notnull => true` that exception aborted `occ app:enable keepiq` on a
 * fresh NC 31 install — i.e. Keepiq could not be installed at all on the
 * minimum version its own appinfo/info.xml declares
 * (`<nextcloud min-version="31" …>`). Nextcloud 32 narrowed the same check to
 * Oracle only, which is why the defect was invisible on newer servers; no CI
 * job had ever installed the app on 31.
 *
 * Every other boolean column in this app's migrations already uses
 * `notnull => false` (is_permanent, renewable, has_password, enabled, tls,
 * is_re_request); this brings the first one into line. The entity declares
 * `protected bool $isActive = false`, so app code can never write NULL, and
 * CACertificateMapper only ever queries `is_active = true` — the nullable
 * column is behaviour-neutral.
 */
class Version000002Date20260331000001 extends SimpleMigrationStep {
	/**
	 * Change the database schema to add the CA certificates table.
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

		if ($schema->hasTable('doriath_ca_certs') === true) {
			return $schema;
		}

		$this->createCaCertsTable(schema: $schema);

		return $schema;
	}//end changeSchema()

	/**
	 * Define the doriath_ca_certs table on a schema that does not have it yet.
	 *
	 * See the class docblock for why `is_active` is a NULLABLE boolean.
	 *
	 * @param ISchemaWrapper $schema The schema to add the table to
	 *
	 * @return void
	 */
	private function createCaCertsTable(ISchemaWrapper $schema): void {
		$table = $schema->createTable('doriath_ca_certs');

		$table->addColumn(
			'id',
			Types::STRING,
			[
				'notnull' => true,
				'length' => 36,
			]
		);
		$table->addColumn(
			'type',
			Types::STRING,
			[
				'notnull' => true,
				'length' => 20,
			]
		);
		$table->addColumn(
			'certificate',
			Types::TEXT,
			[
				'notnull' => true,
			]
		);
		$table->addColumn(
			'private_key',
			Types::TEXT,
			[
				'notnull' => false,
			]
		);
		$table->addColumn(
			'created_at',
			Types::DATETIME,
			[
				'notnull' => true,
			]
		);
		$table->addColumn(
			'expires_at',
			Types::DATETIME,
			[
				'notnull' => true,
			]
		);
		// `notnull` MUST stay false on this BOOLEAN column — see the class
		// docblock; `notnull => true` made the app uninstallable on NC 31.
		$table->addColumn(
			'is_active',
			Types::BOOLEAN,
			[
				'notnull' => false,
				'default' => false,
			]
		);
		$table->addColumn(
			'revoked_at',
			Types::DATETIME,
			[
				'notnull' => false,
			]
		);
		$table->addColumn(
			'successor_id',
			Types::STRING,
			[
				'notnull' => false,
				'length' => 36,
			]
		);

		$table->setPrimaryKey(['id']);
	}//end createCaCertsTable()
}//end class
