<?php

/**
 * Keepiq Attachment Grant Mapper
 *
 * Query-builder mapper for AttachmentGrant rows, including the
 * reference-count query the blob garbage collection relies on.
 *
 * @category Db
 * @package  OCA\Keepiq\Db
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

namespace OCA\Keepiq\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * Mapper for the keepiq_attachment_grants table.
 *
 * @template-extends QBMapper<AttachmentGrant>
 */
class AttachmentGrantMapper extends QBMapper {
	/**
	 * Constructor for AttachmentGrantMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(
			db: $db,
			tableName: 'keepiq_attachment_grants',
			entityClass: AttachmentGrant::class
		);
	}//end __construct()

	/**
	 * Find all grants of an attachment.
	 *
	 * @param string $attachmentId The attachment UUID
	 *
	 * @return AttachmentGrant[]
	 */
	public function findByAttachment(string $attachmentId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('attachment_id', $qb->createNamedParameter($attachmentId)));

		return $this->findEntities(query: $qb);
	}//end findByAttachment()

	/**
	 * Find the grant addressed to one recipient for one attachment.
	 *
	 * @param string $attachmentId The attachment UUID
	 * @param string $recipientId The recipient user/application ID
	 *
	 * @return AttachmentGrant
	 *
	 * @throws DoesNotExistException When the caller holds no grant
	 */
	public function findForRecipient(string $attachmentId, string $recipientId): AttachmentGrant {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('attachment_id', $qb->createNamedParameter($attachmentId)))
			->andWhere($qb->expr()->eq('recipient_id', $qb->createNamedParameter($recipientId)))
			->setMaxResults(1);

		return $this->findEntity(query: $qb);
	}//end findForRecipient()

	/**
	 * Find all grants attached to a Secret copy (revocation cascade).
	 *
	 * @param string $secretId The Secret copy UUID
	 *
	 * @return AttachmentGrant[]
	 */
	public function findBySecret(string $secretId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)));

		return $this->findEntities(query: $qb);
	}//end findBySecret()

	/**
	 * Count the remaining grants of an attachment (blob GC reference count).
	 *
	 * @param string $attachmentId The attachment UUID
	 *
	 * @return int
	 */
	public function countByAttachment(string $attachmentId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('attachment_id', $qb->createNamedParameter($attachmentId)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['cnt'] ?? 0);
	}//end countByAttachment()

	/**
	 * Count a recipient's own grants still bound to a suite.
	 *
	 * Scoped by RECIPIENT, not by the owner of the attachment: a grant is the
	 * file key wrapped to one holder's certificate, so the rotating user can
	 * only re-wrap the grants addressed to them. Every other recipient's grant
	 * stays bound to its own suite and MUST NOT be counted as outstanding work
	 * — counting it would block completion on a row nobody in this migration
	 * is able to touch.
	 *
	 * @param string $encryptionSuiteId The suite ID
	 * @param string $recipientType The recipient type
	 * @param string $recipientId The recipient ID
	 *
	 * @return int
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	public function countBySuiteForRecipient(
		string $encryptionSuiteId,
		string $recipientType,
		string $recipientId,
	): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName())
			->where($qb->expr()->eq('encryption_suite_id', $qb->createNamedParameter($encryptionSuiteId)))
			->andWhere($qb->expr()->eq('recipient_type', $qb->createNamedParameter($recipientType)))
			->andWhere($qb->expr()->eq('recipient_id', $qb->createNamedParameter($recipientId)));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['cnt'] ?? 0);
	}//end countBySuiteForRecipient()

	/**
	 * Count a recipient's own grants on a suite whose owning secret carries NO
	 * recorded migration failure.
	 *
	 * Like versions, grants have no `migration_error` column of their own, so
	 * the owning secret's error is the "accounted for" signal.
	 *
	 * @param string $encryptionSuiteId The suite ID
	 * @param string $recipientType The recipient type
	 * @param string $recipientId The recipient ID
	 *
	 * @return int
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	public function countUnaccountedBySuiteForRecipient(
		string $encryptionSuiteId,
		string $recipientType,
		string $recipientId,
	): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*', 'cnt'))
			->from($this->getTableName(), 'g')
			->innerJoin('g', 'keepiq_secrets', 's', $qb->expr()->eq('g.secret_id', 's.id'))
			->where($qb->expr()->eq('g.encryption_suite_id', $qb->createNamedParameter($encryptionSuiteId)))
			->andWhere($qb->expr()->eq('g.recipient_type', $qb->createNamedParameter($recipientType)))
			->andWhere($qb->expr()->eq('g.recipient_id', $qb->createNamedParameter($recipientId)))
			->andWhere($qb->expr()->isNull('s.migration_error'));

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();

		return (int)($row['cnt'] ?? 0);
	}//end countUnaccountedBySuiteForRecipient()

	/**
	 * List a recipient's own grants still bound to a suite, paged.
	 *
	 * @param string $encryptionSuiteId The suite ID
	 * @param string $recipientType The recipient type
	 * @param string $recipientId The recipient ID
	 * @param int $limit Maximum rows
	 * @param int $offset Row offset
	 *
	 * @return AttachmentGrant[]
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	public function findBySuiteForRecipient(
		string $encryptionSuiteId,
		string $recipientType,
		string $recipientId,
		int $limit = 100,
		int $offset = 0,
	): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('encryption_suite_id', $qb->createNamedParameter($encryptionSuiteId)))
			->andWhere($qb->expr()->eq('recipient_type', $qb->createNamedParameter($recipientType)))
			->andWhere($qb->expr()->eq('recipient_id', $qb->createNamedParameter($recipientId)))
			->orderBy('id', 'ASC')
			->setMaxResults(max(1, $limit))
			->setFirstResult(max(0, $offset));

		return $this->findEntities(query: $qb);
	}//end findBySuiteForRecipient()

	/**
	 * Find a grant by its UUID.
	 *
	 * @param string $id The grant UUID
	 *
	 * @return AttachmentGrant
	 *
	 * @throws DoesNotExistException When no grant matches
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	public function findById(string $id): AttachmentGrant {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity(query: $qb);
	}//end findById()
}//end class
