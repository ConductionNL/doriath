<?php

/**
 * Keepiq Audit Service
 *
 * The single write path into the append-only keepiq_audit_log table and the
 * query API over it (add-secret-audit-trail §2.3). record() is the ONLY code
 * path that inserts a row; it validates metadata against the per-event-type
 * whitelist (unknown keys dropped) and rejects any forbidden secret-material
 * key in any position (design D3) — the no-secret-material guarantee is
 * structural, not a convention. The only mutations the service permits are the
 * retention purge and account-deletion anonymization; there is no update-entry
 * or delete-entry verb anywhere in the public API (Append-Only Log requirement).
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
use OCA\Keepiq\Db\AuditEntry;
use OCA\Keepiq\Db\AuditEntryMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Exception\AuditForbiddenMetadataException;

/**
 * Single write path + query API for the append-only audit log.
 */
class AuditService {
	/**
	 * Constructor for AuditService.
	 *
	 * @param AuditEntryMapper $mapper The audit entry mapper
	 *
	 * @return void
	 */
	public function __construct(
		private AuditEntryMapper $mapper,
	) {
	}//end __construct()

	/**
	 * Record one audit entry from a typed event — the single insert path.
	 *
	 * Validates the event metadata against the per-event-type whitelist
	 * (unknown keys dropped) and rejects forbidden secret-material keys with an
	 * exception (design D3), then persists an immutable row.
	 *
	 * @param AuditEvent $event The audited operation
	 *
	 * @return AuditEntry The persisted entry
	 *
	 * @throws AuditForbiddenMetadataException When metadata contains a forbidden key
	 *
	 * @spec openspec/changes/add-secret-audit-trail/specs/secret-audit-trail/spec.md
	 */
	public function record(AuditEvent $event): AuditEntry {
		$metadata = $this->sanitiseMetadata(eventType: $event->getEventType(), metadata: $event->getMetadata());

		$entry = new AuditEntry();
		$entry->setOccurredAt(new DateTime());
		$entry->setActorType($event->getActorType());
		$entry->setActorId($event->getActorId());
		$entry->setEventType($event->getEventType());
		$entry->setObjectType($event->getObjectType());
		$entry->setObjectId($event->getObjectId());
		$entry->setObjectName($this->truncate(value: $event->getObjectName(), maxLength: 255));

		$entry->setMetadata(null);
		if (count($metadata) > 0) {
			$entry->setMetadata((string)json_encode($metadata));
		}

		return $this->mapper->insert($entry);
	}//end record()

	/**
	 * Validate + filter metadata: reject forbidden keys anywhere, drop unknown.
	 *
	 * @param string $eventType The event type
	 * @param array<string,mixed> $metadata The submitted metadata
	 *
	 * @return array<string,mixed> The whitelisted metadata
	 *
	 * @throws AuditForbiddenMetadataException When a forbidden key appears in any position
	 */
	private function sanitiseMetadata(string $eventType, array $metadata): array {
		$this->assertNoForbiddenKeys(data: $metadata);

		$allowed = AuditEventTypes::WHITELIST[$eventType] ?? [];

		$clean = [];
		foreach ($metadata as $key => $value) {
			if (in_array($key, $allowed, true) === true) {
				$clean[$key] = $value;
			}
		}

		return $clean;
	}//end sanitiseMetadata()

	/**
	 * Recursively assert that no forbidden secret-material key appears.
	 *
	 * @param array<mixed,mixed> $data The data to scan
	 *
	 * @return void
	 *
	 * @throws AuditForbiddenMetadataException When a forbidden key is present
	 */
	private function assertNoForbiddenKeys(array $data): void {
		foreach ($data as $key => $value) {
			if (is_string($key) === true
				&& in_array($key, AuditEventTypes::FORBIDDEN_KEYS, true) === true
			) {
				throw new AuditForbiddenMetadataException(
					message: 'Audit metadata may not contain the forbidden key "' . $key . '"'
				);
			}

			if (is_array($value) === true) {
				$this->assertNoForbiddenKeys(data: $value);
			}
		}
	}//end assertNoForbiddenKeys()

	/**
	 * Truncate a string to a maximum length (null-safe).
	 *
	 * @param string|null $value The value
	 * @param int $maxLength The maximum length
	 *
	 * @return string|null
	 */
	private function truncate(?string $value, int $maxLength): ?string {
		if ($value === null) {
			return null;
		}

		if (mb_strlen($value) <= $maxLength) {
			return $value;
		}

		return mb_substr($value, 0, $maxLength);
	}//end truncate()

	/**
	 * List audit entries for a single object (e.g. a secret), newest first.
	 *
	 * @param string $objectType The object type
	 * @param string $objectId The object id
	 * @param int $limit Maximum rows
	 * @param int $offset Row offset
	 *
	 * @return AuditEntry[]
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-2.3
	 */
	public function listForObject(string $objectType, string $objectId, int $limit = 50, int $offset = 0): array {
		return $this->mapper->findByObject($objectType, $objectId, $limit, $offset);
	}//end listForObject()

	/**
	 * List the entries a given user actored, newest first.
	 *
	 * @param string $actorId The actor user id
	 * @param int $limit Maximum rows
	 * @param int $offset Row offset
	 *
	 * @return AuditEntry[]
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-2.3
	 */
	public function listForActor(string $actorId, int $limit = 50, int $offset = 0): array {
		return $this->mapper->findByActor($actorId, $limit, $offset);
	}//end listForActor()

	/**
	 * The most recent secret-read entries for a user (dashboard widget source).
	 *
	 * @param string $actorId The actor user id
	 * @param int $limit Maximum rows
	 *
	 * @return AuditEntry[]
	 *
	 * @spec openspec/changes/add-secret-audit-trail/design.md
	 */
	public function recentlyAccessed(string $actorId, int $limit = 5): array {
		return $this->mapper->findRecentReadsByActor($actorId, $limit);
	}//end recentlyAccessed()

	/**
	 * Admin instance-wide filtered + paginated query with a total count.
	 *
	 * @param array<string,mixed> $filters Filters (eventType/actor/objectType/objectId/from/to)
	 * @param int $page 1-based page number
	 * @param int $limit Page size
	 *
	 * @return array{entries: AuditEntry[], total: int, page: int, limit: int}
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-2.3
	 */
	public function adminQuery(array $filters, int $page = 1, int $limit = 50): array {
		$page = max(1, $page);
		$limit = max(1, min(200, $limit));
		$offset = (($page - 1) * $limit);
		$entries = $this->mapper->findFiltered($filters, $limit, $offset);
		$total = $this->mapper->countFiltered($filters);

		return [
			'entries' => $entries,
			'total' => $total,
			'page' => $page,
			'limit' => $limit,
		];
	}//end adminQuery()

	/**
	 * Purge entries older than the retention window in bounded batches.
	 *
	 * One of only two permitted mutations of the log. Loops batch-by-batch so a
	 * single run can clear a large backlog without an unbounded statement.
	 *
	 * @param int $retentionDays The retention window in days
	 * @param int $batchSize Rows deleted per batch
	 *
	 * @return int Total rows deleted
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-2.3
	 */
	public function purge(int $retentionDays, int $batchSize = 1000): int {
		$cutoff = (new DateTime())->modify('-' . $retentionDays . ' days');
		$total = 0;

		do {
			$deleted = $this->mapper->purgeOlderThan($cutoff, $batchSize);
			$total += $deleted;
		} while ($deleted === $batchSize);

		return $total;
	}//end purge()

	/**
	 * Anonymize a deleted user out of the log without deleting entries.
	 *
	 * Replaces the user as actor_id by the deleted-account marker and scrubs
	 * whitelisted user-referencing metadata fields (recipientId, delegatedTo) by
	 * the same marker. Entries are retained so other users' accountability
	 * records survive (design D6). No user id, display name, or other personal
	 * data of the deleted user remains afterwards.
	 *
	 * @param string $userId The deleted user id
	 *
	 * @return void
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-2.3
	 */
	public function anonymizeUser(string $userId): void {
		$this->mapper->anonymizeActor($userId);

		// Scrub user-referencing whitelisted metadata fields.
		$candidates = $this->mapper->findMetadataReferencing($userId);
		foreach ($candidates as $entry) {
			$metadata = $entry->getMetadataArray();
			$changed = false;

			foreach (AuditEventTypes::USER_REFERENCING_METADATA_KEYS as $key) {
				if (array_key_exists($key, $metadata) === true
					&& (string)$metadata[$key] === $userId
				) {
					$metadata[$key] = AuditEntryMapper::DELETED_ACCOUNT_MARKER;
					$changed = true;
				}
			}

			if ($changed === true) {
				$json = null;
				if (count($metadata) > 0) {
					$json = (string)json_encode($metadata);
				}

				$this->mapper->rewriteMetadata((int)$entry->getId(), $json);
			}
		}//end foreach
	}//end anonymizeUser()
}//end class
