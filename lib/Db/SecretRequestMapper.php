<?php

/**
 * Keepiq Secret Request Mapper
 *
 * Database mapper for secret request entities.
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

use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for SecretRequest entities.
 *
 * @extends QBMapper<SecretRequest>
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) 11 against a threshold of 10.
 * A mapper's public surface IS its query set, and each of these answers a
 * different question the domain actually asks: by id, by token, by secret, by
 * creator, the pending one for a secret, the lapsed ones for the sweeper, plus
 * the writes. Splitting them across two mappers would put queries over one table
 * in two places, which is how a scoping rule ends up applied in one and not the
 * other.
 */
class SecretRequestMapper extends QBMapper {
	/**
	 * Every request an application created, whatever its status.
	 *
	 * Deliberately not filtered to pending. An administrator auditing an
	 * application needs the fulfilled and revoked ones too — "what has this thing
	 * been asking people for" is the question, and a list of only what is
	 * outstanding answers a narrower one.
	 *
	 * Scoping is structural: `created_by` is set by the service, never by a request
	 * body, and no Nextcloud user id can take the `application:` form. So this
	 * cannot be steered into returning another actor's rows.
	 *
	 * @param string $applicationId The application whose requests to return
	 *
	 * @return array<int,SecretRequest> Every request that application created
	 *
	 * @spec openspec/specs/application-mgmt/spec.md#requirement-outstanding-application-requests-visible-to-administrators
	 */
	public function findByApplication(string $applicationId): array {
		return $this->findByCreatedBy(
			userId: SecretRequest::ACTOR_APPLICATION_PREFIX . $applicationId
		);
	}//end findByApplication()

	/**
	 * Constructor for SecretRequestMapper.
	 *
	 * @param IDBConnection $db The database connection
	 *
	 * @return void
	 */
	public function __construct(IDBConnection $db) {
		parent::__construct(db: $db, tableName: 'keepiq_secret_requests', entityClass: SecretRequest::class);
	}//end __construct()

	/**
	 * Find a secret request by its UUID.
	 *
	 * @param string $id The secret request ID
	 *
	 * @return SecretRequest
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findById(string $id): SecretRequest {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id)));

		return $this->findEntity(query: $qb);
	}//end findById()

	/**
	 * Find a secret request by its public access token.
	 *
	 * @param string $token The access token
	 *
	 * @return SecretRequest
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findByToken(string $token): SecretRequest {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('token', $qb->createNamedParameter($token)));

		return $this->findEntity(query: $qb);
	}//end findByToken()

	/**
	 * Find all secret requests for a given Secret.
	 *
	 * @param string $secretId The Secret ID
	 *
	 * @return SecretRequest[]
	 */
	public function findBySecretId(string $secretId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)));

		return $this->findEntities(query: $qb);
	}//end findBySecretId()

	/**
	 * Move a request out of `pending` only if it is still pending.
	 *
	 * A conditional write, because reading a status and then writing is a race the
	 * callers cannot win any other way. `QBMapper::update()` issues
	 * `UPDATE ... WHERE id = ?` with no status guard, so a caller that checked
	 * `pending` on an entity loaded earlier will happily overwrite whatever the row
	 * says now. The expiry job holds up to 500 entities across a batch, and an
	 * administrator revoking has a person-sized gap between reading and writing —
	 * in both cases a recipient can fill the request in between, and the stale write
	 * turns `fulfilled` into `expired` or `declined`.
	 *
	 * The submitted values survive that (the fill persists them BEFORE flipping the
	 * status), which makes it worse rather than better: the row ends up terminal with
	 * `fulfilled_at` still set, telling the requester their request lapsed while the
	 * credential sits in their vault.
	 *
	 * Reported by review on PR #282 and #286.
	 *
	 * @param string $requestId The request to transition
	 * @param string $toStatus The terminal status to move it to
	 *
	 * @return bool True when this call performed the transition; false when the row
	 *              was no longer pending, and the caller must do nothing further.
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-optional-expiry
	 */
	public function transitionIfPending(string $requestId, string $toStatus): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter($toStatus))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($requestId)))
			->andWhere(
				$qb->expr()->eq(
					'status',
					$qb->createNamedParameter(SecretRequest::STATUS_PENDING)
				)
			);

		return $qb->executeStatement() > 0;
	}//end transitionIfPending()

	/**
	 * Find all secret requests created by a given user.
	 *
	 * @param string $userId The Nextcloud user ID
	 *
	 * @return SecretRequest[]
	 */
	public function findByCreatedBy(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('created_by', $qb->createNamedParameter($userId)));

		return $this->findEntities(query: $qb);
	}//end findByCreatedBy()

	/**
	 * Find pending requests whose expiry has passed.
	 *
	 * Narrow on purpose. `expires_at IS NOT NULL` is not redundant with the date
	 * comparison: a request with NO expiry must never be swept, because Optional
	 * Expiry promises it "remains open until fulfilled or manually revoked".
	 * Relying on a NULL comparison to exclude it would leave that promise resting
	 * on SQL three-valued logic rather than on something a reader can see.
	 *
	 * @param DateTime $now The cutoff instant
	 * @param int $limit Maximum rows per sweep
	 *
	 * @return SecretRequest[]
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-optional-expiry
	 */
	public function findLapsedPending(DateTime $now, int $limit = 500): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('status', $qb->createNamedParameter(SecretRequest::STATUS_PENDING)))
			->andWhere($qb->expr()->isNotNull('expires_at'))
			->andWhere(
				$qb->expr()->lt(
					'expires_at',
					$qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_MUTABLE)
				)
			)
			->setMaxResults($limit);

		return $this->findEntities(query: $qb);
	}//end findLapsedPending()

	/**
	 * Delete all secret requests for a given Secret (cascade on secret delete).
	 *
	 * @param string $secretId The Secret ID
	 *
	 * @return void
	 */
	public function deleteBySecretId(string $secretId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)));

		$qb->executeStatement();
	}//end deleteBySecretId()

	/**
	 * Find a pending request for a given Secret, when one exists.
	 *
	 * @param string $secretId The Secret ID
	 *
	 * @return SecretRequest
	 *
	 * @throws DoesNotExistException
	 * @throws MultipleObjectsReturnedException
	 */
	public function findPendingBySecretId(string $secretId): SecretRequest {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('secret_id', $qb->createNamedParameter($secretId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(SecretRequest::STATUS_PENDING)));

		return $this->findEntity(query: $qb);
	}//end findPendingBySecretId()

	/**
	 * Lock all pending requests bound to a given EncryptionSuite — used
	 * by the compromise-recovery flow to freeze public fill links while
	 * the recipient migrates to a new suite.
	 *
	 * @param string $encryptionSuiteId The recipient's old EncryptionSuite ID
	 *
	 * @return int The number of rows affected.
	 */
	public function lockByEncryptionSuiteId(string $encryptionSuiteId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('status', $qb->createNamedParameter(SecretRequest::STATUS_LOCKED))
			->where($qb->expr()->eq('encryption_suite_id', $qb->createNamedParameter($encryptionSuiteId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(SecretRequest::STATUS_PENDING)));

		return $qb->executeStatement();
	}//end lockByEncryptionSuiteId()

	/**
	 * Re-point all locked requests bound to the old EncryptionSuite at
	 * the new EncryptionSuite + return them to pending.
	 *
	 * @param string $oldEncryptionSuiteId The old EncryptionSuite ID
	 * @param string $newEncryptionSuiteId The new EncryptionSuite ID
	 *
	 * @return int The number of rows affected.
	 */
	public function unlockAndUpdateSuite(string $oldEncryptionSuiteId, string $newEncryptionSuiteId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('encryption_suite_id', $qb->createNamedParameter($newEncryptionSuiteId))
			->set('status', $qb->createNamedParameter(SecretRequest::STATUS_PENDING))
			->where($qb->expr()->eq('encryption_suite_id', $qb->createNamedParameter($oldEncryptionSuiteId)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(SecretRequest::STATUS_LOCKED)));

		return $qb->executeStatement();
	}//end unlockAndUpdateSuite()

	/**
	 * Delete every secret request created by a user (account-deletion cascade).
	 *
	 * Idempotent: a second call simply matches no rows.
	 *
	 * @param string $userId The Nextcloud user ID that created the requests
	 *
	 * @return int The number of rows deleted
	 *
	 * @spec openspec/changes/secret-export-gdpr/specs/gdpr-compliance/spec.md
	 */
	public function deleteByCreatedBy(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('created_by', $qb->createNamedParameter($userId)));

		return $qb->executeStatement();
	}//end deleteByCreatedBy()
}//end class
