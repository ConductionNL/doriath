<?php

/**
 * Unit tests for MigrationWorkService.
 *
 * Two things are locked here. First the per-record authorization: a
 * re-encryption write MUST be refused unless the row is bound to the
 * migration's old suite AND owned by the migration's owner. Both halves matter
 * — the owner half stops one user re-encrypting another's rows, and the suite
 * half stops a replayed or stale request overwriting ciphertext that has
 * already migrated.
 *
 * Second the `possibly_compromised_at` lifecycle on the raise side: set on every
 * committed secret, idempotent on retry, never set for a row that failed.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use DateTime;
use OCA\Keepiq\Db\AttachmentGrant;
use OCA\Keepiq\Db\AttachmentGrantMapper;
use OCA\Keepiq\Db\MigrationFailure;
use OCA\Keepiq\Db\MigrationFailureMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretVersion;
use OCA\Keepiq\Db\SecretVersionMapper;
use OCA\Keepiq\Db\SuiteMigration;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\MigrationWorkService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for MigrationWorkService.
 */
class MigrationWorkServiceTest extends TestCase {

	/**
	 * A foreign owner id. The nil UUID is used deliberately so the fixture can
	 * never be mistaken for a real account.
	 *
	 * @var string
	 */
	private const FOREIGN_OWNER = '00000000-0000-0000-0000-000000000000';

	private MigrationWorkService $service;

	private SecretMapper&MockObject $secretMapper;

	private SecretVersionMapper&MockObject $versionMapper;

	private AttachmentGrantMapper&MockObject $grantMapper;

	/**
	 * Per-record failure accounting.
	 *
	 * @var MigrationFailureMapper&MockObject
	 */
	private MigrationFailureMapper&MockObject $failureMapper;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->secretMapper = $this->createMock(SecretMapper::class);
		$this->versionMapper = $this->createMock(SecretVersionMapper::class);
		$this->grantMapper = $this->createMock(AttachmentGrantMapper::class);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default = 0): int => $default
		);

		$this->failureMapper = $this->createMock(MigrationFailureMapper::class);

		$this->service = new MigrationWorkService(
			secretMapper: $this->secretMapper,
			versionMapper: $this->versionMapper,
			grantMapper: $this->grantMapper,
			failureMapper: $this->failureMapper,
			db: $this->createMock(IDBConnection::class),
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * Build an in-progress migration from old-suite to new-suite.
	 *
	 * @return SuiteMigration
	 */
	private function makeMigration(): SuiteMigration {
		$migration = new SuiteMigration();
		$migration->setId('migration-1');
		$migration->setOldSuiteId('old-suite');
		$migration->setNewSuiteId('new-suite');
		$migration->setStatus('in_progress');

		return $migration;
	}//end makeMigration()

	/**
	 * Build a secret owned by `alice` and bound to a suite.
	 *
	 * @param string $suiteId The suite the secret is bound to
	 * @param string $ownerId The owning user id
	 *
	 * @return Secret
	 */
	private function makeSecret(string $suiteId = 'old-suite', string $ownerId = 'alice'): Secret {
		$secret = new Secret();
		$secret->setId('secret-1');
		$secret->setName('example');
		$secret->setOwnerType('user');
		$secret->setOwnerId($ownerId);
		$secret->setEncryptionSuiteId($suiteId);
		$secret->setKey('OLD-KEY-CIPHERTEXT');

		return $secret;
	}//end makeSecret()

	/**
	 * Test a secret owned by another user is refused.
	 *
	 * @return void
	 */
	public function testCommitSecretRefusesForeignOwner(): void {
		$this->secretMapper->method('findById')
			->willReturn($this->makeSecret(ownerId: self::FOREIGN_OWNER));

		// Nothing may be written when the guard fails.
		$this->secretMapper->expects($this->never())->method('update');

		$this->expectException(ForbiddenException::class);

		$this->service->commitSecret(
			migration: $this->makeMigration(),
			ownerId: 'alice',
			secretId: 'secret-1',
			key: 'NEW-KEY-CIPHERTEXT',
			login: null,
			additionalFields: null
		);
	}//end testCommitSecretRefusesForeignOwner()

	/**
	 * Test a secret already migrated to the new suite is refused.
	 *
	 * @return void
	 */
	public function testCommitSecretRefusesRowNotOnOldSuite(): void {
		// Already re-pointed: a replayed request must not overwrite the good
		// new ciphertext with a stale re-encryption.
		$this->secretMapper->method('findById')
			->willReturn($this->makeSecret(suiteId: 'new-suite'));

		$this->secretMapper->expects($this->never())->method('update');

		$this->expectException(ForbiddenException::class);

		$this->service->commitSecret(
			migration: $this->makeMigration(),
			ownerId: 'alice',
			secretId: 'secret-1',
			key: 'NEW-KEY-CIPHERTEXT',
			login: null,
			additionalFields: null
		);
	}//end testCommitSecretRefusesRowNotOnOldSuite()

	/**
	 * Test a missing secret is reported as not found, not forbidden.
	 *
	 * @return void
	 */
	public function testCommitSecretReportsMissingRow(): void {
		$this->secretMapper->method('findById')
			->willThrowException(new DoesNotExistException('nope'));

		$this->expectException(NotFoundException::class);

		$this->service->commitSecret(
			migration: $this->makeMigration(),
			ownerId: 'alice',
			secretId: 'secret-1',
			key: 'NEW-KEY-CIPHERTEXT',
			login: null,
			additionalFields: null
		);
	}//end testCommitSecretReportsMissingRow()

	/**
	 * Test a committed secret is re-pointed, flagged and cleared of any prior
	 * error, all in one write.
	 *
	 * @return void
	 */
	public function testCommitSecretRepointsAndFlags(): void {
		$secret = $this->makeSecret();
		$secret->setMigrationError('secrets: an earlier attempt failed');

		$this->secretMapper->method('findById')->willReturn($secret);
		$this->secretMapper->method('update')->willReturnArgument(0);

		$result = $this->service->commitSecret(
			migration: $this->makeMigration(),
			ownerId: 'alice',
			secretId: 'secret-1',
			key: 'NEW-KEY-CIPHERTEXT',
			login: 'NEW-LOGIN-CIPHERTEXT',
			additionalFields: null
		);

		$this->assertSame('NEW-KEY-CIPHERTEXT', $result->getKey());
		$this->assertSame('NEW-LOGIN-CIPHERTEXT', $result->getLogin());
		$this->assertNull($result->getAdditionalFields());
		$this->assertSame('new-suite', $result->getEncryptionSuiteId());
		$this->assertNotNull($result->getPossiblyCompromisedAt());
	}//end testCommitSecretRepointsAndFlags()

	/**
	 * Committing a record clears ONLY that record's failure.
	 *
	 * The second blocker on #219: commitSecret blanket-nulled
	 * `secrets.migration_error`, and commitVersion / commitAttachmentGrant
	 * cleared the same column on the owning secret. Because one secret owns its
	 * head plus N versions plus M grants, whichever record was written last
	 * won — so committing a sibling erased a failure that had already been
	 * recorded, reclassifying a genuinely failed record as "not yet attempted".
	 *
	 * @return void
	 *
	 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
	 */
	public function testCommittingAVersionDoesNotClearASiblingsFailure(): void {
		$version = new SecretVersion();
		$version->setId('version-2');
		$version->setSecretId('secret-1');
		$version->setEncryptionSuiteId('old-suite');
		$version->setKey('OLD');

		$this->versionMapper->method('findById')->willReturn($version);
		$this->versionMapper->method('update')->willReturnArgument(0);
		$this->secretMapper->method('findById')->willReturn($this->makeSecret());

		// Scoped to (migration, versions, version-2) and nothing else. A call
		// naming the secret head or another version would be the old bug.
		$this->failureMapper->expects($this->once())
			->method('clearRecord')
			->with('migration-1', 'versions', 'version-2');

		$this->service->commitVersion(
			migration: $this->makeMigration(),
			ownerId: 'alice',
			versionId: 'version-2',
			key: 'NEW',
			login: null,
			additionalFields: null
		);
	}//end testCommittingAVersionDoesNotClearASiblingsFailure()

	/**
	 * Test the migration does not reset the ciphertext-age clock.
	 *
	 * The value is re-sealed, not replaced, so presenting it as freshly rotated
	 * would be a lie — and precisely the opposite of what the flag says.
	 *
	 * @return void
	 */
	public function testCommitSecretLeavesKeyUpdatedAtAlone(): void {
		$stamp = new DateTime('2020-01-01T00:00:00+00:00');
		$secret = $this->makeSecret();
		$secret->setKeyUpdatedAt($stamp);

		$this->secretMapper->method('findById')->willReturn($secret);
		$this->secretMapper->method('update')->willReturnArgument(0);

		$result = $this->service->commitSecret(
			migration: $this->makeMigration(),
			ownerId: 'alice',
			secretId: 'secret-1',
			key: 'NEW-KEY-CIPHERTEXT',
			login: null,
			additionalFields: null
		);

		$this->assertSame($stamp, $result->getKeyUpdatedAt());
	}//end testCommitSecretLeavesKeyUpdatedAtAlone()

	/**
	 * Test raising the flag is idempotent across a retry.
	 *
	 * The timestamp records when the value was first known to be exposed; a
	 * retry must not push it forward and make the exposure look newer.
	 *
	 * @return void
	 */
	public function testFlagRaisingIsIdempotent(): void {
		$original = new DateTime('2026-01-01T00:00:00+00:00');
		$secret = $this->makeSecret();
		$secret->setPossiblyCompromisedAt($original);

		$this->secretMapper->method('findById')->willReturn($secret);
		$this->secretMapper->method('update')->willReturnArgument(0);

		$result = $this->service->commitSecret(
			migration: $this->makeMigration(),
			ownerId: 'alice',
			secretId: 'secret-1',
			key: 'NEW-KEY-CIPHERTEXT',
			login: null,
			additionalFields: null
		);

		$this->assertSame($original, $result->getPossiblyCompromisedAt());
	}//end testFlagRaisingIsIdempotent()

	/**
	 * Test a failed record is never flagged.
	 *
	 * @return void
	 */
	public function testFailedRecordIsNotFlagged(): void {
		$secret = $this->makeSecret();

		$this->secretMapper->method('findById')->willReturn($secret);
		$this->secretMapper->method('update')->willReturnArgument(0);
		$this->failureMapper->method('record')->willReturn(new MigrationFailure());

		$this->service->recordFailure(
			migration: $this->makeMigration(),
			ownerId: 'alice',
			store: 'secrets',
			recordId: 'secret-1',
			message: 'Re-encrypted key did not decrypt back to the original value'
		);

		$this->assertNull($secret->getPossiblyCompromisedAt());
	}//end testFailedRecordIsNotFlagged()

	/**
	 * Test a version failure is recorded on the owning secret, store-prefixed.
	 *
	 * `migration_error` exists only on keepiq_secrets, so this is where a
	 * version or grant failure has to land.
	 *
	 * @return void
	 */
	public function testVersionFailureIsRecordedAgainstTheVersion(): void {
		$version = new SecretVersion();
		$version->setId('version-1');
		$version->setSecretId('secret-1');
		$version->setEncryptionSuiteId('old-suite');

		$secret = $this->makeSecret();

		$this->versionMapper->method('findById')->willReturn($version);
		$this->secretMapper->method('findById')->willReturn($secret);
		$this->secretMapper->method('update')->willReturnArgument(0);

		// The failing VERSION is the unit of accounting; the owning secret is
		// carried only so the acknowledgement list can name it. Recording
		// against the secret is the first blocker: once its head had migrated,
		// the version was invisible to both completion gates.
		$this->failureMapper->expects($this->once())
			->method('record')
			->with(
				'migration-1',
				'versions',
				'version-1',
				'secret-1',
				'round-trip mismatch'
			)
			->willReturn(new MigrationFailure());

		$secretId = $this->service->recordFailure(
			migration: $this->makeMigration(),
			ownerId: 'alice',
			store: 'versions',
			recordId: 'version-1',
			message: 'round-trip mismatch'
		);

		$this->assertSame('secret-1', $secretId);
	}//end testVersionFailureIsRecordedAgainstTheVersion()

	/**
	 * Test a version whose owning secret belongs to someone else is refused.
	 *
	 * Ownership is resolved through the secret because a version row has no
	 * owner column of its own.
	 *
	 * @return void
	 */
	public function testCommitVersionRefusesForeignOwner(): void {
		$version = new SecretVersion();
		$version->setId('version-1');
		$version->setSecretId('secret-1');
		$version->setEncryptionSuiteId('old-suite');

		$this->versionMapper->method('findById')->willReturn($version);
		$this->secretMapper->method('findById')
			->willReturn($this->makeSecret(ownerId: self::FOREIGN_OWNER));

		$this->versionMapper->expects($this->never())->method('update');

		$this->expectException(ForbiddenException::class);

		$this->service->commitVersion(
			migration: $this->makeMigration(),
			ownerId: 'alice',
			versionId: 'version-1',
			key: 'NEW-KEY-CIPHERTEXT',
			login: null,
			additionalFields: null
		);
	}//end testCommitVersionRefusesForeignOwner()

	/**
	 * Test a version already on the new suite is refused.
	 *
	 * @return void
	 */
	public function testCommitVersionRefusesRowNotOnOldSuite(): void {
		$version = new SecretVersion();
		$version->setId('version-1');
		$version->setSecretId('secret-1');
		$version->setEncryptionSuiteId('new-suite');

		$this->versionMapper->method('findById')->willReturn($version);
		$this->versionMapper->expects($this->never())->method('update');

		$this->expectException(ForbiddenException::class);

		$this->service->commitVersion(
			migration: $this->makeMigration(),
			ownerId: 'alice',
			versionId: 'version-1',
			key: 'NEW-KEY-CIPHERTEXT',
			login: null,
			additionalFields: null
		);
	}//end testCommitVersionRefusesRowNotOnOldSuite()

	/**
	 * Test a version whose head has already migrated is still accepted.
	 *
	 * Versions carry their own encryption_suite_id and migrate independently of
	 * their head, so the guard must check the version's suite and the secret's
	 * OWNERSHIP — not the secret's suite.
	 *
	 * @return void
	 */
	public function testCommitVersionAcceptedWhenHeadAlreadyMigrated(): void {
		$version = new SecretVersion();
		$version->setId('version-1');
		$version->setSecretId('secret-1');
		$version->setEncryptionSuiteId('old-suite');

		$this->versionMapper->method('findById')->willReturn($version);
		$this->versionMapper->method('update')->willReturnArgument(0);
		// The head is already on the new suite.
		$this->secretMapper->method('findById')->willReturn($this->makeSecret(suiteId: 'new-suite'));

		$result = $this->service->commitVersion(
			migration: $this->makeMigration(),
			ownerId: 'alice',
			versionId: 'version-1',
			key: 'NEW-KEY-CIPHERTEXT',
			login: null,
			additionalFields: null
		);

		$this->assertSame('new-suite', $result->getEncryptionSuiteId());
	}//end testCommitVersionAcceptedWhenHeadAlreadyMigrated()

	/**
	 * Test a grant addressed to another recipient is refused.
	 *
	 * Re-pointing somebody else's grant would seal that recipient out of the
	 * attachment, so recipient scoping is the guard even on a secret this user
	 * owns.
	 *
	 * @return void
	 */
	public function testCommitGrantRefusesForeignRecipient(): void {
		$grant = new AttachmentGrant();
		$grant->setId('grant-1');
		$grant->setSecretId('secret-1');
		$grant->setRecipientType('user');
		$grant->setRecipientId(self::FOREIGN_OWNER);
		$grant->setEncryptionSuiteId('old-suite');

		$this->grantMapper->method('findById')->willReturn($grant);
		$this->grantMapper->expects($this->never())->method('update');

		$this->expectException(ForbiddenException::class);

		$this->service->commitAttachmentGrant(
			migration: $this->makeMigration(),
			ownerId: 'alice',
			grantId: 'grant-1',
			wrappedFileKey: 'NEW-WRAPPED-KEY'
		);
	}//end testCommitGrantRefusesForeignRecipient()

	/**
	 * Test a grant already on the new suite is refused.
	 *
	 * @return void
	 */
	public function testCommitGrantRefusesRowNotOnOldSuite(): void {
		$grant = new AttachmentGrant();
		$grant->setId('grant-1');
		$grant->setSecretId('secret-1');
		$grant->setRecipientType('user');
		$grant->setRecipientId('alice');
		$grant->setEncryptionSuiteId('new-suite');

		$this->grantMapper->method('findById')->willReturn($grant);
		$this->grantMapper->expects($this->never())->method('update');

		$this->expectException(ForbiddenException::class);

		$this->service->commitAttachmentGrant(
			migration: $this->makeMigration(),
			ownerId: 'alice',
			grantId: 'grant-1',
			wrappedFileKey: 'NEW-WRAPPED-KEY'
		);
	}//end testCommitGrantRefusesRowNotOnOldSuite()

	/**
	 * Test a committed grant is re-wrapped and re-pointed.
	 *
	 * @return void
	 */
	public function testCommitGrantRewrapsAndRepoints(): void {
		$grant = new AttachmentGrant();
		$grant->setId('grant-1');
		$grant->setSecretId('secret-1');
		$grant->setRecipientType('user');
		$grant->setRecipientId('alice');
		$grant->setEncryptionSuiteId('old-suite');
		$grant->setWrappedFileKey('OLD-WRAPPED-KEY');

		$this->grantMapper->method('findById')->willReturn($grant);
		$this->grantMapper->method('update')->willReturnArgument(0);
		$this->secretMapper->method('findById')->willReturn($this->makeSecret());

		$result = $this->service->commitAttachmentGrant(
			migration: $this->makeMigration(),
			ownerId: 'alice',
			grantId: 'grant-1',
			wrappedFileKey: 'NEW-WRAPPED-KEY'
		);

		$this->assertSame('NEW-WRAPPED-KEY', $result->getWrappedFileKey());
		$this->assertSame('new-suite', $result->getEncryptionSuiteId());
	}//end testCommitGrantRewrapsAndRepoints()

	/**
	 * Test an unknown store is refused rather than guessed at.
	 *
	 * @return void
	 */
	public function testRecordFailureRefusesUnknownStore(): void {
		$this->expectException(ForbiddenException::class);

		$this->service->recordFailure(
			migration: $this->makeMigration(),
			ownerId: 'alice',
			store: 'linkShares',
			recordId: 'share-1',
			message: 'nope'
		);
	}//end testRecordFailureRefusesUnknownStore()

	/**
	 * Test outstanding counts add up across the three re-encrypted stores.
	 *
	 * @return void
	 */
	public function testCountOutstandingSumsTheThreeStores(): void {
		$this->secretMapper->method('countBySuiteForOwner')->willReturn(4);
		$this->versionMapper->method('countBySuiteForOwner')->willReturn(7);
		$this->grantMapper->method('countBySuiteForRecipient')->willReturn(2);

		$counts = $this->service->countOutstanding(
			migration: $this->makeMigration(),
			ownerId: 'alice'
		);

		$this->assertSame(4, $counts['secrets']);
		$this->assertSame(7, $counts['versions']);
		$this->assertSame(2, $counts['attachmentGrants']);
		$this->assertSame(13, $counts['total']);
	}//end testCountOutstandingSumsTheThreeStores()

	/**
	 * Test the work list re-encrypts the window and reports the rest as drops.
	 *
	 * Head plus the 5 most recent, per the version-history spec: a secret with
	 * 12 outstanding snapshots yields 5 to migrate and 7 to drop.
	 *
	 * @return void
	 */
	public function testListWorkAppliesTheVersionWindow(): void {
		$versions = [];
		for ($number = 12; $number >= 1; $number--) {
			$version = new SecretVersion();
			$version->setId('version-' . $number);
			$version->setSecretId('secret-1');
			$version->setVersionNumber($number);
			$version->setEncryptionSuiteId('old-suite');
			$version->setKey('V' . $number . '-CIPHERTEXT');
			$versions[] = $version;
		}

		$this->secretMapper->method('findBySuiteForOwner')->willReturn([]);
		$this->grantMapper->method('findBySuiteForRecipient')->willReturn([]);
		$this->versionMapper->method('findSecretIdsWithSuiteVersionsForOwner')->willReturn(['secret-1']);
		$this->versionMapper->method('findBySecretAndSuite')->willReturn($versions);
		$this->secretMapper->method('countBySuiteForOwner')->willReturn(0);
		$this->versionMapper->method('countBySuiteForOwner')->willReturn(12);
		$this->grantMapper->method('countBySuiteForRecipient')->willReturn(0);

		$work = $this->service->listWork(
			migration: $this->makeMigration(),
			ownerId: 'alice'
		);

		$this->assertSame(5, $this->service->getVersionWindow());
		$this->assertCount(5, $work['versions']['records']);
		$this->assertSame(7, $work['versions']['dropCandidates']);

		// Newest first: the window keeps versions 12 down to 8.
		$this->assertSame('version-12', $work['versions']['records'][0]['id']);
		$this->assertSame('version-8', $work['versions']['records'][4]['id']);
	}//end testListWorkAppliesTheVersionWindow()

	/**
	 * Test the work list carries ciphertext and no decrypted value.
	 *
	 * @return void
	 */
	public function testListWorkCarriesCiphertextOnly(): void {
		$this->secretMapper->method('findBySuiteForOwner')->willReturn([$this->makeSecret()]);
		$this->grantMapper->method('findBySuiteForRecipient')->willReturn([]);
		$this->versionMapper->method('findSecretIdsWithSuiteVersionsForOwner')->willReturn([]);
		$this->secretMapper->method('countBySuiteForOwner')->willReturn(1);
		$this->versionMapper->method('countBySuiteForOwner')->willReturn(0);
		$this->grantMapper->method('countBySuiteForRecipient')->willReturn(0);

		$work = $this->service->listWork(
			migration: $this->makeMigration(),
			ownerId: 'alice'
		);

		$record = $work['secrets']['records'][0];

		// The blob is passed through exactly as stored — the server performs no
		// decryption anywhere in this path (ADR-003).
		$this->assertSame('OLD-KEY-CIPHERTEXT', $record['key']);
		$this->assertSame(1, $work['totalRemaining']);

		// Plaintext organisational metadata is fine; a decrypted value is not.
		$this->assertSame(
			['id', 'name', 'key', 'login', 'additionalFields'],
			array_keys($record)
		);
	}//end testListWorkCarriesCiphertextOnly()

	/**
	 * countCommitted sums the owner's rows now on the NEW suite across the three
	 * suite-bound stores — the count the abort gate uses to decide whether any
	 * record has already moved.
	 *
	 * @return void
	 */
	public function testCountCommittedSumsNewSuiteRowsAcrossStores(): void {
		$migration = new SuiteMigration();
		$migration->setId('migration-1');
		$migration->setOldSuiteId('old-suite');
		$migration->setNewSuiteId('new-suite');

		$this->secretMapper->method('countBySuiteForOwner')
			->with('new-suite', 'user', 'alice')->willReturn(2);
		$this->versionMapper->method('countBySuiteForOwner')
			->with('new-suite', 'user', 'alice')->willReturn(1);
		$this->grantMapper->method('countBySuiteForRecipient')
			->with('new-suite', 'user', 'alice')->willReturn(3);

		$this->assertSame(6, $this->service->countCommitted($migration, 'alice'));
	}//end testCountCommittedSumsNewSuiteRowsAcrossStores()

	/**
	 * Nothing on the new suite means nothing has been committed — the state in
	 * which abort is allowed.
	 *
	 * @return void
	 */
	public function testCountCommittedIsZeroWhenNothingMoved(): void {
		$migration = new SuiteMigration();
		$migration->setId('migration-1');
		$migration->setOldSuiteId('old-suite');
		$migration->setNewSuiteId('new-suite');

		$this->secretMapper->method('countBySuiteForOwner')->willReturn(0);
		$this->versionMapper->method('countBySuiteForOwner')->willReturn(0);
		$this->grantMapper->method('countBySuiteForRecipient')->willReturn(0);

		$this->assertSame(0, $this->service->countCommitted($migration, 'alice'));
	}//end testCountCommittedIsZeroWhenNothingMoved()
}//end class
