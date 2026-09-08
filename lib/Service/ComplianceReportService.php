<?php

/**
 * Keepiq Compliance Report Service
 *
 * Metadata-only compliance-posture aggregation (compliance-reporting
 * §2): six sections composed from COUNT queries over server-visible
 * columns — never a secret value, name, login, or ciphertext, and no
 * strength/reuse/breach figure anywhere. Every aggregate is validated
 * against a key allowlist before persistence.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

use DateTime;
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Db\ComplianceReport;
use OCA\Keepiq\Db\ComplianceReportMapper;
use OCA\Keepiq\Event\Audit\AuditEventFactory;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IDBConnection;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use Throwable;

/**
 * Business logic for compliance reporting.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The aggregator's whole
 *   job is composing counts across the domain tables.
 */
class ComplianceReportService {
	/**
	 * Section keys the aggregate may carry — anything else is rejected
	 * before persistence (compliance-reporting §2.4).
	 *
	 * @var array<string,string[]>
	 */
	private const SECTION_ALLOWLIST = [
		'adoption' => ['usersWithActiveSuite', 'usersWithSecrets', 'usersWithEmergencyContact'],
		'secretsPerUser' => ['totalSecrets', 'ownersWithSecrets', 'minPerOwner', 'medianPerOwner', 'maxPerOwner'],
		'shareHygiene' => ['userShares', 'groupShares', 'linkShares', 'linkSharesPasswordProtected', 'linkSharesExpiring'],
		'rotationPosture' => [
			'available',
			'expiryPolicies',
			'secretsWithExpiry',
			'overdueSecrets',
			'openFlagsByReason',
			'ciphertextAgeBands',
			'possiblyCompromised',
		],
		'auditIntegrity' => ['retentionDays', 'totalEntries', 'firstEntryAt', 'appendOnly'],
		'emergencyAccess' => ['grantorsWithActiveContact', 'pendingRequests'],
	];

	/**
	 * Constructor for ComplianceReportService.
	 *
	 * @param ComplianceReportMapper $mapper The report mapper
	 * @param IDBConnection $db The DB connection (COUNT queries)
	 * @param IAppConfig $appConfig The app config
	 * @param IAppManager $appManager The app manager (version)
	 * @param IEventDispatcher|null $eventDispatcher The audit dispatcher
	 * @param AuditEventFactory $auditEvents The audit-event factory
	 *
	 * @return void
	 */
	public function __construct(
		private ComplianceReportMapper $mapper,
		private IDBConnection $db,
		private IAppConfig $appConfig,
		private IAppManager $appManager,
		private ?IEventDispatcher $eventDispatcher = null,
		private AuditEventFactory $auditEvents = new AuditEventFactory(),
	) {
	}//end __construct()

	/**
	 * Compose the six metadata-only sections (§2.1–§2.3).
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/compliance-reporting/specs/compliance-reporting/spec.md
	 */
	public function aggregate(): array {
		$activeSuiteWhere = "status = 'active' AND owner_type = 'user'";
		$activeStateWhere = "state IN ('granted','accepted','active')";
		$aggregate = [
			'adoption' => [
				'usersWithActiveSuite' => $this->countDistinct(table: 'keepiq_enc_suites', column: 'owner_id', where: $activeSuiteWhere),
				'usersWithSecrets' => $this->countDistinct(table: 'keepiq_secrets', column: 'owner_id', where: "owner_type = 'user'"),
				'usersWithEmergencyContact' => $this->countDistinct(table: 'keepiq_emergency_contacts', column: 'grantor_user_id', where: null),
			],
			'secretsPerUser' => $this->secretsPerUserSection(),
			'shareHygiene' => [
				'userShares' => $this->countAll(table: 'keepiq_share_targets'),
				'groupShares' => $this->countAll(table: 'keepiq_group_shares'),
				'linkShares' => $this->countAll(table: 'keepiq_link_shares'),
				// Every link share carries an Argon2id-wrapped snapshot;
				// "password protected" = the whole population by protocol.
				'linkSharesPasswordProtected' => $this->countWhere(table: 'keepiq_link_shares', where: "argon2id_salt <> ''"),
				'linkSharesExpiring' => $this->countWhere(table: 'keepiq_link_shares', where: 'expires_at IS NOT NULL'),
			],
			'rotationPosture' => $this->rotationPostureSection(),
			'auditIntegrity' => [
				'retentionDays' => $this->appConfig->getValueInt(Application::APP_ID, 'audit_retention_days', 365),
				'totalEntries' => $this->countAll(table: 'keepiq_audit_log'),
				'firstEntryAt' => $this->scalar(sql: 'SELECT MIN(occurred_at) FROM *PREFIX*keepiq_audit_log'),
				'appendOnly' => true,
			],
			'emergencyAccess' => [
				'grantorsWithActiveContact' => $this->countDistinct(
					table: 'keepiq_emergency_contacts',
					column: 'grantor_user_id',
					where: $activeStateWhere
				),
				'pendingRequests' => $this->countWhere(table: 'keepiq_emergency_contacts', where: "state IN ('requested','pending')"),
			],
		];

		$this->assertAllowlisted(aggregate: $aggregate);

		return $aggregate;
	}//end aggregate()

	/**
	 * Generate + persist an immutable snapshot (§2.5).
	 *
	 * @param string $adminUid The generating admin
	 *
	 * @return ComplianceReport
	 *
	 * @spec openspec/changes/compliance-reporting/specs/compliance-reporting/spec.md
	 */
	public function generate(string $adminUid): ComplianceReport {
		$appId = Application::APP_ID;
		$config = [
			'auditRetentionDays' => $this->appConfig->getValueInt($appId, 'audit_retention_days', 365),
			'breachCheckEnabled' => $this->appConfig->getValueBool($appId, 'breach_check_enabled', false),
			'expiryDefaultMaxDays' => $this->appConfig->getValueInt($appId, 'expiry_default_max_age_days', 0),
			'policyEnabled' => $this->appConfig->getValueBool($appId, 'policy_enabled', false),
			'appVersion' => $this->appVersion(),
		];

		$report = new ComplianceReport();
		$report->setId(Uuid::uuid4()->toString());
		$report->setGeneratedBy($adminUid);
		$report->setGeneratedAt(new DateTime());
		$report->setAppVersion($this->appVersion());
		$report->setConfigSnapshot((string)json_encode($config));
		$report->setAggregate((string)json_encode($this->aggregate()));
		$report = $this->mapper->insert($report);

		$this->dispatchAudit(
			actorId: $adminUid,
			eventType: AuditEventTypes::COMPLIANCE_REPORT_GENERATED,
			reportId: $report->getId(),
		);

		return $report;
	}//end generate()

	/**
	 * List snapshots, newest first.
	 *
	 * @return ComplianceReport[]
	 */
	public function listReports(): array {
		return $this->mapper->findAll();
	}//end listReports()

	/**
	 * One snapshot by id.
	 *
	 * @param string $id The report UUID
	 *
	 * @return ComplianceReport
	 *
	 * @throws DoesNotExistException When no row matches
	 */
	public function getReport(string $id): ComplianceReport {
		return $this->mapper->findById($id);
	}//end getReport()

	/**
	 * Record an export beacon (§4.3).
	 *
	 * @param string $adminUid The exporting admin
	 * @param string $reportId The exported report
	 * @param string $format The export format (csv|pdf)
	 *
	 * @return void
	 */
	public function recordExport(string $adminUid, string $reportId, string $format): void {
		$this->dispatchAudit(
			actorId: $adminUid,
			eventType: AuditEventTypes::COMPLIANCE_REPORT_EXPORTED,
			reportId: $reportId,
			format: $format,
		);
	}//end recordExport()

	/**
	 * Recompute the warm metrics cache (daily job, §3.1).
	 *
	 * @return void
	 */
	public function refreshMetricsCache(): void {
		$appId = Application::APP_ID;
		$this->appConfig->setValueString($appId, 'compliance_metrics_cache', (string)json_encode($this->aggregate()));
		$this->appConfig->setValueString($appId, 'compliance_metrics_computed_at', (new DateTime())->format('c'));
	}//end refreshMetricsCache()

	/**
	 * The warm metrics cache (computed on demand when cold).
	 *
	 * @return array{computedAt:string|null, metrics:array<string,mixed>}
	 */
	public function cachedMetrics(): array {
		$appId = Application::APP_ID;
		$cached = $this->appConfig->getValueString($appId, 'compliance_metrics_cache', '');
		if ($cached === '') {
			$this->refreshMetricsCache();
			$cached = $this->appConfig->getValueString($appId, 'compliance_metrics_cache', '{}');
		}

		$metrics = json_decode($cached, true);
		if (is_array($metrics) === false) {
			$metrics = [];
		}

		return [
			'computedAt' => $this->appConfig->getValueString($appId, 'compliance_metrics_computed_at', ''),
			'metrics' => $metrics,
		];
	}//end cachedMetrics()

	/**
	 * The secrets-per-user section (§2.1) — counts only.
	 *
	 * @return array<string,int>
	 */
	private function secretsPerUserSection(): array {
		$counts = [];
		$result = $this->db->executeQuery(
			'SELECT COUNT(*) AS c FROM *PREFIX*keepiq_secrets WHERE owner_type = ? GROUP BY owner_id',
			['user']
		);
		while (($row = $result->fetch()) !== false) {
			$counts[] = (int)$row['c'];
		}

		$result->closeCursor();
		sort($counts);
		$ownerCount = count($counts);
		$median = 0;
		if ($ownerCount > 0) {
			$median = $counts[intdiv($ownerCount, 2)];
		}

		$min = 0;
		$max = 0;
		if ($ownerCount > 0) {
			$min = $counts[0];
			$max = $counts[($ownerCount - 1)];
		}

		return [
			'totalSecrets' => array_sum($counts),
			'ownersWithSecrets' => $ownerCount,
			'minPerOwner' => $min,
			'medianPerOwner' => $median,
			'maxPerOwner' => $max,
		];
	}//end secretsPerUserSection()

	/**
	 * The rotation-posture section (§2.2/§2.3) — degrades to
	 * unavailable when the rotation capability is absent; ciphertext-age
	 * bands are labelled ciphertext-age, never strength.
	 *
	 * @return array<string,mixed>
	 */
	private function rotationPostureSection(): array {
		if (class_exists(\OCA\Keepiq\Service\RotationPolicyService::class) === false) {
			return ['available' => false];
		}

		try {
			$flagsByReason = [];
			$result = $this->db->executeQuery(
				'SELECT reason, COUNT(*) AS c FROM *PREFIX*keepiq_rotation_flags WHERE status = ? GROUP BY reason',
				['open']
			);
			while (($row = $result->fetch()) !== false) {
				$flagsByReason[(string)$row['reason']] = (int)$row['c'];
			}

			$result->closeCursor();

			$overdueWhere = 'expires_at IS NOT NULL AND expires_at < CURRENT_TIMESTAMP';

			return [
				'available' => true,
				'expiryPolicies' => $this->countAll(table: 'keepiq_expiry_policies'),
				'secretsWithExpiry' => $this->countWhere(table: 'keepiq_secrets', where: 'expires_at IS NOT NULL'),
				'overdueSecrets' => $this->countWhere(table: 'keepiq_secrets', where: $overdueWhere),
				'openFlagsByReason' => $flagsByReason,
				'ciphertextAgeBands' => $this->ciphertextAgeBands(),
				'possiblyCompromised' => $this->countWhere(table: 'keepiq_secrets', where: 'possibly_compromised_at IS NOT NULL'),
			];
		} catch (Throwable) {
			return ['available' => false];
		}//end try
	}//end rotationPostureSection()

	/**
	 * Ciphertext-age bands from key_updated_at (§2.3) — explicitly
	 * labelled ciphertext-age, never password strength.
	 *
	 * @return array<string,int>
	 */
	private function ciphertextAgeBands(): array {
		$bands = [
			'under90Days' => 0,
			'under1Year' => 0,
			'over1Year' => 0,
		];
		$result = $this->db->executeQuery(
			'SELECT key_updated_at FROM *PREFIX*keepiq_secrets WHERE key_updated_at IS NOT NULL'
		);
		$now = time();
		while (($row = $result->fetch()) !== false) {
			$age = $now - strtotime((string)$row['key_updated_at']);
			$band = 'over1Year';
			if ($age < 7776000) {
				$band = 'under90Days';
			} elseif ($age < 31536000) {
				$band = 'under1Year';
			}

			++$bands[$band];
		}

		$result->closeCursor();

		return $bands;
	}//end ciphertextAgeBands()

	/**
	 * Assert every aggregate section and key is allowlisted (§2.4).
	 *
	 * SECTION_ALLOWLIST is a CLOSED set: a section or key that is not
	 * named there is rejected outright, which is strictly stronger than
	 * any substring denylist could be. (An earlier FORBIDDEN_KEY_PARTS
	 * denylist constant sat here unused and could never have been
	 * enabled — it would have rejected the legitimately allowlisted
	 * `ciphertextAgeBands` and `linkSharesPasswordProtected` keys.)
	 *
	 * @param array<string,mixed> $aggregate The composed aggregate
	 *
	 * @return void
	 *
	 * @throws RuntimeException When an unexpected key appears
	 */
	private function assertAllowlisted(array $aggregate): void {
		foreach ($aggregate as $section => $values) {
			if (isset(self::SECTION_ALLOWLIST[$section]) === false) {
				throw new RuntimeException('Unexpected compliance section: ' . $section);
			}

			foreach (array_keys((array)$values) as $valueKey) {
				if (in_array($valueKey, self::SECTION_ALLOWLIST[$section], true) === false) {
					throw new RuntimeException('Unexpected compliance key: ' . $section . '.' . $valueKey);
				}
			}
		}
	}//end assertAllowlisted()

	/**
	 * COUNT(*) of a table.
	 *
	 * @param string $table The unprefixed table name
	 *
	 * @return int
	 */
	private function countAll(string $table): int {
		return (int)$this->scalar(sql: 'SELECT COUNT(*) FROM *PREFIX*' . $table);
	}//end countAll()

	/**
	 * COUNT(*) with a static WHERE clause (no user input reaches this).
	 *
	 * @param string $table The unprefixed table name
	 * @param string $where The static WHERE clause
	 *
	 * @return int
	 */
	private function countWhere(string $table, string $where): int {
		return (int)$this->scalar(sql: 'SELECT COUNT(*) FROM *PREFIX*' . $table . ' WHERE ' . $where);
	}//end countWhere()

	/**
	 * COUNT(DISTINCT column) with an optional static WHERE clause.
	 *
	 * @param string $table The unprefixed table name
	 * @param string $column The distinct column
	 * @param string|null $where The static WHERE clause
	 *
	 * @return int
	 */
	private function countDistinct(string $table, string $column, ?string $where): int {
		$sql = 'SELECT COUNT(DISTINCT ' . $column . ') FROM *PREFIX*' . $table;
		if ($where !== null) {
			$sql .= ' WHERE ' . $where;
		}

		try {
			return (int)$this->scalar(sql: $sql);
		} catch (Throwable) {
			// A table from an optional capability may not exist.
			return 0;
		}
	}//end countDistinct()

	/**
	 * Run a scalar query.
	 *
	 * @param string $sql The SQL (static, *PREFIX* substituted by NC)
	 *
	 * @return mixed
	 */
	private function scalar(string $sql): mixed {
		$result = $this->db->executeQuery($sql);
		$value = $result->fetchOne();
		$result->closeCursor();

		return $value;
	}//end scalar()

	/**
	 * The installed app version.
	 *
	 * @return string
	 */
	private function appVersion(): string {
		try {
			return $this->appManager->getAppVersion(Application::APP_ID);
		} catch (Throwable) {
			return 'unknown';
		}
	}//end appVersion()

	/**
	 * Dispatch a compliance audit event (identifiers only).
	 *
	 * @param string $actorId The admin actor
	 * @param string $eventType The event type
	 * @param string $reportId The report id
	 * @param string|null $format The export format (export events)
	 *
	 * @return void
	 */
	private function dispatchAudit(string $actorId, string $eventType, string $reportId, ?string $format = null): void {
		$metadata = ['reportId' => $reportId];
		if ($format !== null) {
			$metadata['format'] = $format;
		}

		$this->eventDispatcher?->dispatchTyped(
			$this->auditEvents->forUser(
				actorId: $actorId,
				eventType: $eventType,
				objectType: 'compliance_report',
				objectId: $reportId,
				objectName: '',
				metadata: $metadata,
			)
		);
	}//end dispatchAudit()
}//end class
