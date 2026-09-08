<?php

/**
 * Database-backed tests for the batch active-suite lookup.
 *
 * findActiveByOwners() has to reproduce findActiveByOwner()'s ORDERING, not
 * just its filter, and that is only observable against a real database: the
 * ordering lives in SQL. Compromise recovery leaves the old suite `active`
 * until the migration terminates, so an owner can legitimately have two active
 * rows, and returning the older certificate produces a shared copy the
 * recipient cannot open.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Db
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

namespace OCA\Keepiq\Tests\Unit\Db;

use DateTime;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Tests EncryptionSuiteMapper::findActiveByOwners against a live database.
 */
class EncryptionSuiteMapperTest extends TestCase {

	/**
	 * The live database connection, or null without Nextcloud.
	 *
	 * @var IDBConnection|null
	 */
	private ?IDBConnection $db = null;

	/**
	 * The mapper under test.
	 *
	 * @var EncryptionSuiteMapper|null
	 */
	private ?EncryptionSuiteMapper $mapper = null;

	/**
	 * Suite ids inserted by the running test, removed again in tearDown.
	 *
	 * @var array<int,string>
	 */
	private array $inserted = [];

	/**
	 * Resolve a real connection, or skip.
	 *
	 * The reachability check goes through the mapper rather than naming a
	 * table, so it holds on either side of the doriath -> keepiq table rename.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if (class_exists(\OC::class) === false || \OC::$server === null) {
			$this->markTestSkipped(message: 'needs a bootstrapped Nextcloud with a database');
		}

		$this->db = \OC::$server->get(IDBConnection::class);
		$this->mapper = new EncryptionSuiteMapper(db: $this->db);

		try {
			$this->mapper->findActiveByOwners(ownerType: 'user', ownerIds: ['__probe__']);
		} catch (Throwable) {
			$this->markTestSkipped(message: 'keepiq migrations have not run on this instance');
		}
	}//end setUp()

	/**
	 * Remove every row this test inserted, whether it passed or failed.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		foreach ($this->inserted as $id) {
			try {
				$this->mapper?->delete($this->mapper->findById($id));
			} catch (Throwable) {
				// Already gone, or the insert never landed.
			}
		}

		$this->inserted = [];
		parent::tearDown();
	}//end tearDown()

	/**
	 * Insert an active suite for an owner.
	 *
	 * @param string   $ownerId     The owner.
	 * @param string   $certificate The certificate to store.
	 * @param DateTime $createdAt   Creation timestamp.
	 *
	 * @return string The inserted suite id.
	 */
	private function insertActive(string $ownerId, string $certificate, DateTime $createdAt): string {
		$suite = new EncryptionSuite();
		$suite->setId(uniqid(prefix: 'test-suite-', more_entropy: true));
		$suite->setOwnerType('user');
		$suite->setOwnerId($ownerId);
		$suite->setCertificate($certificate);
		$suite->setPrivateKey('not-a-real-key');
		$suite->setStatus('active');
		$suite->setCreatedAt($createdAt);

		$this->mapper->insert($suite);
		$this->inserted[] = $suite->getId();

		return $suite->getId();
	}//end insertActive()

	/**
	 * With two active suites, the newest certificate is returned.
	 *
	 * This is the compromise-recovery window. Returning the older suite hands
	 * out the certificate the owner is migrating away from.
	 *
	 * @return void
	 */
	public function testReturnsTheNewestSuiteWhenTwoAreActive(): void {
		$owner = uniqid(prefix: 'test-owner-');

		$this->insertActive($owner, 'PEM-OLD', new DateTime('2026-01-01 00:00:00'));
		$this->insertActive($owner, 'PEM-NEW', new DateTime('2026-06-01 00:00:00'));

		$found = $this->mapper->findActiveByOwners(ownerType: 'user', ownerIds: [$owner]);

		$this->assertArrayHasKey($owner, $found);
		$this->assertSame(
			'PEM-NEW',
			$found[$owner]->getCertificate(),
			'the newest active suite must win, as it does for findActiveByOwner()'
		);
	}//end testReturnsTheNewestSuiteWhenTwoAreActive()

	/**
	 * The batch result agrees with the single-owner lookup.
	 *
	 * The two must not be allowed to drift; a caller picking one over the
	 * other should never get a different certificate.
	 *
	 * @return void
	 */
	public function testAgreesWithTheSingleOwnerLookup(): void {
		$owner = uniqid(prefix: 'test-owner-');

		$this->insertActive($owner, 'PEM-OLD', new DateTime('2026-01-01 00:00:00'));
		$this->insertActive($owner, 'PEM-NEW', new DateTime('2026-06-01 00:00:00'));

		$batch = $this->mapper->findActiveByOwners(ownerType: 'user', ownerIds: [$owner]);
		$single = $this->mapper->findActiveByOwner(ownerType: 'user', ownerId: $owner);

		$this->assertSame($single->getCertificate(), $batch[$owner]->getCertificate());
	}//end testAgreesWithTheSingleOwnerLookup()

	/**
	 * An owner with no active suite is absent from the result.
	 *
	 * Absent rather than present-with-null, so a caller cannot mistake "not
	 * shareable" for "shareable with nothing".
	 *
	 * @return void
	 */
	public function testAnOwnerWithoutASuiteIsAbsent(): void {
		$present = uniqid(prefix: 'test-owner-');
		$missing = uniqid(prefix: 'test-owner-');

		$this->insertActive($present, 'PEM-ONLY', new DateTime('2026-06-01 00:00:00'));

		$found = $this->mapper->findActiveByOwners(ownerType: 'user', ownerIds: [$present, $missing]);

		$this->assertArrayHasKey($present, $found);
		$this->assertArrayNotHasKey($missing, $found);
	}//end testAnOwnerWithoutASuiteIsAbsent()

	/**
	 * An empty id list never reaches the database.
	 *
	 * `IN ()` is a syntax error on most platforms, so the guard matters.
	 *
	 * @return void
	 */
	public function testAnEmptyIdListReturnsNothing(): void {
		$this->assertSame([], $this->mapper->findActiveByOwners(ownerType: 'user', ownerIds: []));
	}//end testAnEmptyIdListReturnsNothing()
}//end class
