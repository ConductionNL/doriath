<?php

/**
 * Database-backed tests for SecretRequestMapper's conditional transition.
 *
 * The only test in this suite that talks to a real database, and deliberately so.
 * `transitionIfPending()` exists to make one guarantee — that a row which stopped
 * being `pending` cannot be overwritten — and that guarantee lives entirely in the
 * SQL. A mocked IQueryBuilder would execute the method and assert on the arguments
 * its own mock received: it would pass just as happily against `update ... where id
 * = ?` with no status guard, which is precisely the defect this method was written
 * to remove. Such a test measures nothing and moves a coverage number, so it is
 * worse than no test.
 *
 * This began as a throwaway probe run against the dev instance while fixing the
 * bug. Review on PR #293 pointed out, correctly, that a guarantee proven by a
 * script nobody runs again is not proven at all.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Db
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

namespace OCA\Keepiq\Tests\Unit\Db;

use DateTime;
use OCA\Keepiq\Db\SecretRequest;
use OCA\Keepiq\Db\SecretRequestMapper;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SecretRequestMapper::transitionIfPending().
 */
class SecretRequestMapperTest extends TestCase {

	/**
	 * The live database connection, or null when this suite runs without Nextcloud.
	 *
	 * @var IDBConnection|null
	 */
	private ?IDBConnection $db = null;

	/**
	 * The mapper under test.
	 *
	 * @var SecretRequestMapper|null
	 */
	private ?SecretRequestMapper $mapper = null;

	/**
	 * Ids inserted by the running test, removed again in tearDown.
	 *
	 * @var array<int,string>
	 */
	private array $inserted = [];

	/**
	 * Resolve a real connection, or skip.
	 *
	 * The bootstrap loads Nextcloud only when a config with a database exists, so
	 * this skips rather than fails on a bare checkout. CI runs against an installed
	 * instance with pgsql, which is where these assertions matter.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if (class_exists(\OC::class) === false || \OC::$server === null) {
			$this->markTestSkipped(message: 'needs a bootstrapped Nextcloud with a database');
		}

		$this->db = \OC::$server->get(IDBConnection::class);

		if ($this->db->tableExists('keepiq_secret_requests') === false) {
			$this->markTestSkipped(message: 'keepiq migrations have not run on this instance');
		}

		$this->mapper = new SecretRequestMapper(db: $this->db);
	}//end setUp()

	/**
	 * Remove every row this test inserted, whether it passed or failed.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->inserted as $id) {
			$delete = $this->db->getQueryBuilder();
			$delete->delete('keepiq_secret_requests')
				->where($delete->expr()->eq('id', $delete->createNamedParameter($id)));
			$delete->executeStatement();
		}

		$this->inserted = [];
		parent::tearDown();
	}//end tearDown()

	/**
	 * Insert one request row directly, bypassing the mapper under test.
	 *
	 * Written with the query builder rather than `insert()` so the row's starting
	 * status is stated by the test rather than produced by the code being tested.
	 *
	 * @param string $status The status to start the row in
	 *
	 * @return string The new row's id
	 */
	private function insertRequest(string $status): string {
		$id = 'test-' . bin2hex(random_bytes(8));

		$insert = $this->db->getQueryBuilder();
		$insert->insert('keepiq_secret_requests')->values([
			'id' => $insert->createNamedParameter($id),
			'secret_id' => $insert->createNamedParameter('sec-' . $id),
			'encryption_suite_id' => $insert->createNamedParameter('suite-' . $id),
			'token' => $insert->createNamedParameter('tok-' . $id),
			'requested_fields' => $insert->createNamedParameter('["key"]'),
			'status' => $insert->createNamedParameter($status),
			'created_by' => $insert->createNamedParameter('test-actor'),
			'created_at' => $insert->createNamedParameter(
				(new DateTime('2026-01-01T00:00:00Z'))->format('Y-m-d H:i:s')
			),
		]);
		$insert->executeStatement();

		$this->inserted[] = $id;

		return $id;
	}//end insertRequest()

	/**
	 * Read a row's status straight from the table.
	 *
	 * @param string $id The row to read
	 *
	 * @return string|null The status, or null when the row is gone
	 */
	private function statusOf(string $id): ?string {
		$query = $this->db->getQueryBuilder();
		$query->select('status')->from('keepiq_secret_requests')
			->where($query->expr()->eq('id', $query->createNamedParameter($id)));

		$result = $query->executeQuery();
		$status = $result->fetchOne();
		$result->closeCursor();

		if ($status === false) {
			return null;
		}

		return (string)$status;
	}//end statusOf()

	/**
	 * A pending row transitions, and the call says it did.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-optional-expiry
	 */
	public function testAPendingRequestTransitions(): void {
		$id = $this->insertRequest(status: SecretRequest::STATUS_PENDING);

		$this->assertTrue(
			condition: $this->mapper->transitionIfPending(
				requestId: $id,
				toStatus: SecretRequest::STATUS_EXPIRED
			),
			message: 'a pending row must transition'
		);
		$this->assertSame(
			expected: SecretRequest::STATUS_EXPIRED,
			actual: $this->statusOf(id: $id)
		);
	}//end testAPendingRequestTransitions()

	/**
	 * The second caller loses, and changes nothing.
	 *
	 * This is the race itself: two actors that both read `pending`. Exactly one may
	 * win, and the loser must be told so — the boolean is what every caller keys its
	 * cleanup and auditing off, so a false positive here becomes an audit event for
	 * something that never happened.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-optional-expiry
	 */
	public function testTheSecondTransitionLosesAndChangesNothing(): void {
		$id = $this->insertRequest(status: SecretRequest::STATUS_PENDING);

		$this->mapper->transitionIfPending(requestId: $id, toStatus: SecretRequest::STATUS_EXPIRED);

		$this->assertFalse(
			condition: $this->mapper->transitionIfPending(
				requestId: $id,
				toStatus: SecretRequest::STATUS_DECLINED
			),
			message: 'a row that is no longer pending must refuse the transition'
		);
		$this->assertSame(
			expected: SecretRequest::STATUS_EXPIRED,
			actual: $this->statusOf(id: $id),
			message: 'the losing caller must not have written its own status'
		);
	}//end testTheSecondTransitionLosesAndChangesNothing()

	/**
	 * A fulfilled row cannot be expired or declined. This is the reported defect.
	 *
	 * Filling persists the submitted values BEFORE flipping the status, so an
	 * overwrite here left the row terminal with `fulfilled_at` still set: the
	 * requester was told their request had lapsed while the credential was already
	 * in their vault. Reported by review on PR #282 and #286.
	 *
	 * Both terminal statuses are checked because the two callers write different
	 * ones — the sweeper `expired`, an administrator `declined` — and the guard is
	 * on the row's CURRENT status, not on what is being written.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-optional-expiry
	 */
	public function testAFulfilledRequestIsUntouchable(): void {
		$id = $this->insertRequest(status: SecretRequest::STATUS_FULFILLED);

		foreach ([SecretRequest::STATUS_EXPIRED, SecretRequest::STATUS_DECLINED] as $target) {
			$this->assertFalse(
				condition: $this->mapper->transitionIfPending(requestId: $id, toStatus: $target),
				message: 'a fulfilled request must not be moved to ' . $target
			);
			$this->assertSame(
				expected: SecretRequest::STATUS_FULFILLED,
				actual: $this->statusOf(id: $id),
				message: 'the fulfilled status must survive an attempt to write ' . $target
			);
		}
	}//end testAFulfilledRequestIsUntouchable()

	/**
	 * An id that matches nothing reports false rather than throwing.
	 *
	 * The sweeper walks a batch; a request deleted mid-sweep must not abort the rest
	 * of it.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-requests/spec.md#requirement-optional-expiry
	 */
	public function testAnUnknownRequestReportsFalse(): void {
		$this->assertFalse(
			condition: $this->mapper->transitionIfPending(
				requestId: 'test-does-not-exist',
				toStatus: SecretRequest::STATUS_EXPIRED
			)
		);
	}//end testAnUnknownRequestReportsFalse()

}//end class
