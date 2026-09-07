<?php

/**
 * Renames this app's `doriath_*` database objects to `keepiq_*`.
 *
 * The doriath -> keepiq rename left the schema behind: 33 tables, their
 * indexes and their sequences all still carried the old name while the
 * mappers were pointed at the new one. This step closes that gap on installs
 * that already have the old objects; a fresh install never needs it, because
 * the migrations now create the new names directly.
 *
 * IT RUNS AS A `pre-migration` STEP, AND THAT PLACEMENT IS LOAD-BEARING.
 * `Installer::installAppLastSteps()` runs pre-migration repair BEFORE
 * `MigrationService::migrate()`, so an install sitting partway through the
 * migration list is handled correctly: whatever subset of old tables exists
 * is renamed first, and the migrations that have not run yet then create the
 * remaining tables under the new name. Doing this as a migration step instead
 * would NOT work — `migrateSchemaOnly()` (the fresh-install path, reached via
 * `migrate('latest', schemaOnly: true)`) never calls preSchemaChange() or
 * postSchemaChange(), so the rename would be skipped while the migration was
 * still recorded as executed.
 *
 * IT NEVER THROWS. A pre-migration step that escapes aborts the upgrade and
 * leaves the app half-migrated, so every failure is reported and stepped over.
 *
 * IT REFUSES RATHER THAN MERGES. Where both the old and the new table exist,
 * something has already created the new one and picking a winner is a decision
 * about data, not a rename — so both are left alone and the collision reported.
 *
 * Index and sequence renames are best-effort and platform-specific: Postgres
 * has ALTER INDEX/ALTER SEQUENCE, MySQL has ALTER TABLE .. RENAME INDEX and no
 * sequences, and SQLite has neither. A stale index name is cosmetic, so where
 * the platform cannot rename it the step says so and moves on.
 *
 * @spec exclude One-off doriath -> keepiq table rename plumbing: it moves
 * existing schema objects onto the new prefix and is removed once every
 * install has run it. No canonical spec describes the rename itself.
 *
 * @category  Repair
 * @package   OCA\Keepiq\Repair
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

namespace OCA\Keepiq\Repair;

use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Throwable;

/**
 * Renames the app's doriath_* tables, indexes and sequences to keepiq_*.
 *
 * @spec exclude One-off doriath -> keepiq table rename plumbing. It moves
 *  existing schema objects onto the new prefix and is removed once every
 *  install has run it; no canonical spec describes the rename itself.
 */
class RenameDoriathTables implements IRepairStep {

	/**
	 * The old prefix being retired.
	 *
	 * @var string
	 */
	private const OLD = 'doriath_';

	/**
	 * The prefix replacing it.
	 *
	 * @var string
	 */
	private const NEW = 'keepiq_';

	/**
	 * Table name suffixes owned by this app, without either prefix.
	 *
	 * Held as a closed list rather than discovered, so the step can never
	 * rename a table this app does not own.
	 *
	 * @var string[]
	 */
	private const TABLES = [
		'app_lease_policies',
		'applications',
		'attachment_grants',
		'attachments',
		'audit_log',
		'ca_certs',
		'certificate_metadata',
		'compliance_reports',
		'dashboard_settings',
		'emergency_contacts',
		'enc_suites',
		'ephemeral_sends',
		'expiry_policies',
		'folders',
		'group_shares',
		'honey_alerts',
		'honey_flags',
		'link_shares',
		'machine_leases',
		'migration_failures',
		'passkey_credentials',
		'rotation_flags',
		'secret_delegations',
		'secret_requests',
		'secret_types',
		'secret_versions',
		'secrets',
		'share_targets',
		'siem_queue',
		'siem_sinks',
		'suite_migr',
		'team_folder_members',
		'team_folders',
	];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db     Database connection.
	 * @param IConfig       $config System configuration, for the table prefix.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly IConfig $config,
	) {
	}//end __construct()

	/**
	 * Human-readable step name.
	 *
	 * @return string The name.
		 * @spec exclude One-off doriath -> keepiq table rename plumbing; no
	 * canonical spec describes the rename itself.
	 *
 */
	public function getName(): string {
		return 'Keepiq: rename doriath_* database objects to keepiq_*';
	}//end getName()

	/**
	 * Rename every old object this install still carries.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
		 * @spec exclude One-off doriath -> keepiq table rename plumbing; no
	 * canonical spec describes the rename itself.
	 *
 */
	public function run(IOutput $output): void {
		$renamed = [];

		foreach (self::TABLES as $suffix) {
			$old = self::OLD . $suffix;
			$new = self::NEW . $suffix;

			try {
				if ($this->db->tableExists($old) === false) {
					continue;
				}

				if ($this->db->tableExists($new) === true) {
					$output->warning(
						sprintf('Both %s and %s exist - leaving both alone, this needs a human.', $old, $new)
					);
					continue;
				}

				$this->db->executeStatement(
					sprintf('ALTER TABLE %s RENAME TO %s', $this->quote(table: $old), $this->quote(table: $new))
				);
				$renamed[] = $new;
			} catch (Throwable $e) {
				$output->warning(sprintf('Could not rename table %s: %s', $old, $e->getMessage()));
			}
		}

		if ($renamed === []) {
			$output->info('No doriath_* tables found - nothing to rename.');
			return;
		}

		$output->info(sprintf('Renamed %d table(s) to the keepiq_ prefix.', count($renamed)));
		$this->renameIndexes(output: $output, tables: $renamed);
		$this->renameSequences(output: $output);
	}//end run()

	/**
	 * Rename indexes still carrying the old name on the renamed tables.
	 *
	 * @param IOutput  $output  Repair output.
	 * @param string[] $tables  Unprefixed names of the tables just renamed.
	 *
	 * @return void
	 */
	private function renameIndexes(IOutput $output, array $tables): void {
		$provider = $this->db->getDatabaseProvider();

		if (in_array($provider, [IDBConnection::PLATFORM_POSTGRES, IDBConnection::PLATFORM_MYSQL, IDBConnection::PLATFORM_MARIADB], true) === false) {
			$output->info(sprintf('%s cannot rename indexes - leaving the old index names in place.', $provider));
			return;
		}

		$count = 0;
		foreach ($tables as $table) {
			$prefixed = $this->config->getSystemValueString(key: 'dbtableprefix', default: 'oc_') . $table;

			foreach ($this->indexesOf(provider: $provider, table: $prefixed) as $index) {
				if (str_contains($index, self::OLD) === false) {
					continue;
				}

				$new = str_replace(self::OLD, self::NEW, $index);

				try {
					if ($provider === IDBConnection::PLATFORM_POSTGRES) {
						$sql = sprintf('ALTER INDEX %s RENAME TO %s', $this->quoteRaw(identifier: $index), $this->quoteRaw(identifier: $new));
					} else {
						$sql = sprintf(
							'ALTER TABLE %s RENAME INDEX %s TO %s',
							$this->quoteRaw(identifier: $prefixed),
							$this->quoteRaw(identifier: $index),
							$this->quoteRaw(identifier: $new)
						);
					}

					$this->db->executeStatement($sql);
					$count++;
				} catch (Throwable $e) {
					$output->warning(sprintf('Could not rename index %s: %s', $index, $e->getMessage()));
				}
			}
		}

		$output->info(sprintf('Renamed %d index(es).', $count));
	}//end renameIndexes()

	/**
	 * List the index names on a prefixed table.
	 *
	 * @param string $provider Database provider.
	 * @param string $table    Prefixed table name.
	 *
	 * @return string[] The index names.
	 */
	private function indexesOf(string $provider, string $table): array {
		try {
			if ($provider === IDBConnection::PLATFORM_POSTGRES) {
				$sql = 'SELECT indexname FROM pg_indexes WHERE schemaname = current_schema() AND tablename = ?';
			} else {
				$sql = 'SELECT DISTINCT index_name FROM information_schema.statistics '
					. 'WHERE table_schema = DATABASE() AND table_name = ?';
			}

			$result = $this->db->executeQuery($sql, [$table]);
			$names = array_map(strval(...), $result->fetchFirstColumn());
			$result->closeCursor();

			return $names;
		} catch (Throwable) {
			return [];
		}
	}//end indexesOf()

	/**
	 * Rename sequences still carrying the old name.
	 *
	 * Postgres only - MySQL uses AUTO_INCREMENT and SQLite uses rowid, so
	 * neither has a sequence object to rename.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 */
	private function renameSequences(IOutput $output): void {
		if ($this->db->getDatabaseProvider() !== IDBConnection::PLATFORM_POSTGRES) {
			return;
		}

		$count = 0;
		try {
			$result = $this->db->executeQuery(
				'SELECT sequencename FROM pg_sequences WHERE schemaname = current_schema() AND sequencename LIKE ?',
				['%' . self::OLD . '%']
			);
			$names = array_map(strval(...), $result->fetchFirstColumn());
			$result->closeCursor();

			foreach ($names as $old) {
				$new = str_replace(self::OLD, self::NEW, $old);

				try {
					$this->db->executeStatement(
						sprintf('ALTER SEQUENCE %s RENAME TO %s', $this->quoteRaw(identifier: $old), $this->quoteRaw(identifier: $new))
					);
					$count++;
				} catch (Throwable $e) {
					$output->warning(sprintf('Could not rename sequence %s: %s', $old, $e->getMessage()));
				}
			}
		} catch (Throwable $e) {
			$output->warning('Could not list sequences: ' . $e->getMessage());
		}

		$output->info(sprintf('Renamed %d sequence(s).', $count));
	}//end renameSequences()

	/**
	 * Quote an unprefixed table name, applying the instance table prefix.
	 *
	 * @param string $table Unprefixed table name.
	 *
	 * @return string The quoted, prefixed identifier.
	 */
	private function quote(string $table): string {
		return $this->quoteRaw(
			identifier: $this->config->getSystemValueString(key: 'dbtableprefix', default: 'oc_') . $table
		);
	}//end quote()

	/**
	 * Quote an identifier that is already complete.
	 *
	 * Identifiers reaching here come from this class's own closed list or from
	 * the database's own catalogue, never from user input; the character check
	 * is a belt-and-braces guard against a malformed name reaching DDL.
	 *
	 * @param string $identifier The identifier.
	 *
	 * @return string The quoted identifier.
	 *
	 * @throws \InvalidArgumentException When the identifier is not a bare name.
	 */
	private function quoteRaw(string $identifier): string {
		if (preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
			throw new \InvalidArgumentException('Refusing to build DDL for identifier: ' . $identifier);
		}

		// MySQL and MariaDB reject "x" as an identifier unless ANSI_QUOTES is
		// set, which Nextcloud does not guarantee; Postgres and SQLite take it.
		$provider = $this->db->getDatabaseProvider();
		$isBacktick = in_array(
			$provider,
			[IDBConnection::PLATFORM_MYSQL, IDBConnection::PLATFORM_MARIADB],
			true
		);
		if ($isBacktick === true) {
			$quote = '`';
		} else {
			$quote = '"';
		}

		return $quote . $identifier . $quote;
	}//end quoteRaw()
}//end class
