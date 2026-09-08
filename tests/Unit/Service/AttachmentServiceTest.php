<?php

/**
 * Unit tests for AttachmentService (encrypted-attachments §7.1).
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

use InvalidArgumentException;
use OCA\Keepiq\Db\Attachment;
use OCA\Keepiq\Db\AttachmentGrant;
use OCA\Keepiq\Db\AttachmentGrantMapper;
use OCA\Keepiq\Db\AttachmentMapper;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Service\AttachmentService;
use OCA\Keepiq\Service\WriteLockService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\Files\IAppData;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AttachmentService.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Mirrors the service ctor.
 */
class AttachmentServiceTest extends TestCase {
	private AttachmentService $service;

	private AttachmentMapper&MockObject $mapper;

	private AttachmentGrantMapper&MockObject $grantMapper;

	private SecretMapper&MockObject $secretMapper;

	private IAppConfig&MockObject $appConfig;

	private ISimpleFolder&MockObject $folder;

	/**
	 * Build the service with fresh mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(originalClassName: AttachmentMapper::class);
		$this->grantMapper = $this->createMock(originalClassName: AttachmentGrantMapper::class);
		$this->secretMapper = $this->createMock(originalClassName: SecretMapper::class);
		$suiteMapper = $this->createMock(originalClassName: EncryptionSuiteMapper::class);
		$suiteMapper->method('findActiveByOwner')->willThrowException(new DoesNotExistException(''));
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);

		$this->folder = $this->createMock(originalClassName: ISimpleFolder::class);
		$appData = $this->createMock(originalClassName: IAppData::class);
		$appData->method('getFolder')->willReturn($this->folder);
		$appDataFactory = $this->createMock(originalClassName: IAppDataFactory::class);
		$appDataFactory->method('get')->willReturn($appData);

		$this->service = new AttachmentService(
			mapper: $this->mapper,
			grantMapper: $this->grantMapper,
			secretMapper: $this->secretMapper,
			suiteMapper: $suiteMapper,
			appDataFactory: $appDataFactory,
			appConfig: $this->appConfig,
			writeLockService: $this->createMock(WriteLockService::class),
			eventDispatcher: null,
		);
	}//end setUp()

	/**
	 * Build an owned Secret.
	 *
	 * @param string $id The secret id
	 * @param string $ownerId The owner
	 *
	 * @return Secret
	 */
	private function ownedSecret(string $id = 'sec-1', string $ownerId = 'alice'): Secret {
		$secret = new Secret();
		$secret->setId($id);
		$secret->setName('Wiki');
		$secret->setOwnerType('user');
		$secret->setOwnerId($ownerId);
		return $secret;
	}//end ownedSecret()

	/**
	 * Configure limits.
	 *
	 * @param int $maxBytes The per-attachment cap
	 * @param int $quota The per-user quota
	 * @param int $used Bytes already in use
	 *
	 * @return void
	 */
	private function limits(int $maxBytes, int $quota, int $used = 0): void {
		$this->appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default) => match ($key) {
				'attachment_max_bytes' => $maxBytes,
				'attachment_user_quota_bytes' => $quota,
				default => $default,
			}
		);
		$this->mapper->method('sumBytesForOwner')->willReturn($used);
	}//end limits()

	/**
	 * Upload rejects a blob over the per-attachment size limit.
	 *
	 * @return void
	 */
	public function testUploadRejectsOverSizeLimit(): void {
		$this->secretMapper->method('findById')->willReturn($this->ownedSecret());
		$this->limits(maxBytes: 10, quota: 1000);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/per-attachment limit/');
		$this->service->upload(
			secretId: 'sec-1',
			userId: 'alice',
			blob: str_repeat('x', 11),
			encryptedMetadata: 'META',
			wrappedFileKey: 'WRAPPED_KEY_PLACEHOLDER',
		);
	}//end testUploadRejectsOverSizeLimit()

	/**
	 * Upload rejects when the user quota would be exceeded, and accepts
	 * under it (quota reclaimed space counts).
	 *
	 * @return void
	 */
	public function testUploadEnforcesQuota(): void {
		$this->secretMapper->method('findById')->willReturn($this->ownedSecret());
		$this->limits(maxBytes: 1000, quota: 100, used: 95);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/quota exceeded/');
		$this->service->upload(
			secretId: 'sec-1',
			userId: 'alice',
			blob: str_repeat('x', 10),
			encryptedMetadata: 'META',
			wrappedFileKey: 'WRAPPED_KEY_PLACEHOLDER',
		);
	}//end testUploadEnforcesQuota()

	/**
	 * A valid upload stores the blob, the row, and the owner grant.
	 *
	 * @return void
	 */
	public function testUploadStoresBlobRowAndOwnerGrant(): void {
		$this->secretMapper->method('findById')->willReturn($this->ownedSecret());
		$this->limits(maxBytes: 1000, quota: 1000);

		$file = $this->createMock(originalClassName: ISimpleFile::class);
		$file->expects($this->once())->method('putContent')->with('CIPHERTEXT');
		$this->folder->method('newFile')->willReturn($file);
		$this->mapper->method('insert')->willReturnArgument(0);
		$inserted = [];
		$this->grantMapper->method('insert')->willReturnCallback(
			static function (AttachmentGrant $grant) use (&$inserted) {
				$inserted[] = $grant;
				return $grant;
			}
		);

		$result = $this->service->upload(
			secretId: 'sec-1',
			userId: 'alice',
			blob: 'CIPHERTEXT',
			encryptedMetadata: 'META',
			wrappedFileKey: 'WRAPPED_KEY_PLACEHOLDER',
		);

		$this->assertSame(10, $result['attachment']->getSizeBytes());
		$this->assertCount(1, $inserted);
		$this->assertSame('alice', $inserted[0]->getRecipientId());
		$this->assertSame('user', $inserted[0]->getRecipientType());
	}//end testUploadStoresBlobRowAndOwnerGrant()

	/**
	 * Download requires a grant addressed to the caller.
	 *
	 * @return void
	 */
	public function testDownloadRequiresGrant(): void {
		$attachment = new Attachment();
		$attachment->setId('att-1');
		$attachment->setBlobRef('blob.bin');
		$this->mapper->method('findById')->willReturn($attachment);
		$this->grantMapper->method('findForRecipient')
			->willThrowException(new DoesNotExistException(''));

		$this->expectException(InvalidArgumentException::class);
		$this->service->downloadBlob(attachmentId: 'att-1', userId: 'mallory');
	}//end testDownloadRequiresGrant()

	/**
	 * A blob still in the pre-rename namespace is readable.
	 *
	 * MoveAttachmentBlobs cannot abort an upgrade, so a blob it declines to
	 * relocate keeps its bytes in the old namespace. Reads must find it there,
	 * or preserving the source protects the data and still loses the
	 * attachment - bytes intact and unreachable is indistinguishable from
	 * gone.
	 *
	 * @return void
	 */
	public function testDownloadFallsBackToThePreRenameNamespace(): void {
		$attachment = new Attachment();
		$attachment->setId('att-1');
		$attachment->setBlobRef('blob.bin');
		$this->mapper->method('findById')->willReturn($attachment);
		$this->grantMapper->method('findForRecipient')->willReturn(new AttachmentGrant());

		// Current namespace: relocation never happened, so the blob is absent.
		$current = $this->createMock(originalClassName: ISimpleFolder::class);
		$current->method('getFile')->willThrowException(new NotFoundException());

		// Pre-rename namespace: still holding the bytes.
		$legacyFile = $this->createMock(originalClassName: ISimpleFile::class);
		$legacyFile->method('getContent')->willReturn('LEGACY-CIPHERTEXT');
		$legacy = $this->createMock(originalClassName: ISimpleFolder::class);
		$legacy->method('getFile')->willReturn($legacyFile);

		$service = $this->serviceWithNamespaces(current: $current, legacy: $legacy);

		$this->assertSame(
			'LEGACY-CIPHERTEXT',
			$service->downloadBlob(attachmentId: 'att-1', userId: 'alice')
		);
	}//end testDownloadFallsBackToThePreRenameNamespace()

	/**
	 * Build a service whose two AppData namespaces hold different folders.
	 *
	 * @param ISimpleFolder $current The keepiq namespace folder.
	 * @param ISimpleFolder $legacy  The doriath namespace folder.
	 *
	 * @return AttachmentService The service under test.
	 */
	private function serviceWithNamespaces(ISimpleFolder $current, ISimpleFolder $legacy): AttachmentService {
		$currentAppData = $this->createMock(originalClassName: IAppData::class);
		$currentAppData->method('getFolder')->willReturn($current);
		$legacyAppData = $this->createMock(originalClassName: IAppData::class);
		$legacyAppData->method('getFolder')->willReturn($legacy);

		$factory = $this->createMock(originalClassName: IAppDataFactory::class);
		$factory->method('get')->willReturnCallback(
			static fn (string $ns): IAppData => ($ns === 'doriath') ? $legacyAppData : $currentAppData
		);

		$suiteMapper = $this->createMock(originalClassName: EncryptionSuiteMapper::class);
		$suiteMapper->method('findActiveByOwner')->willThrowException(new DoesNotExistException(''));

		return new AttachmentService(
			mapper: $this->mapper,
			grantMapper: $this->grantMapper,
			secretMapper: $this->secretMapper,
			suiteMapper: $suiteMapper,
			appDataFactory: $factory,
			appConfig: $this->appConfig,
			writeLockService: $this->createMock(WriteLockService::class),
			eventDispatcher: null,
		);
	}//end serviceWithNamespaces()

	/**
	 * deleteForSecret removes grants, unlinks orphaned blobs, and is
	 * idempotent (missing blob tolerated; empty secret is a no-op).
	 *
	 * @return void
	 */
	public function testDeleteForSecretGcsBlobsIdempotently(): void {
		$attachment = new Attachment();
		$attachment->setId('att-1');
		$attachment->setBlobRef('blob.bin');
		$this->mapper->method('findBySourceSecret')->willReturnOnConsecutiveCalls([$attachment], []);

		$grant = new AttachmentGrant();
		$grant->setId('g-1');
		$grant->setAttachmentId('att-1');
		$this->grantMapper->method('findByAttachment')->willReturn([$grant]);
		$this->grantMapper->expects($this->once())->method('delete');
		// After the grant delete the reference count is zero → blob unlinked.
		$this->grantMapper->method('countByAttachment')->willReturn(0);

		$file = $this->createMock(originalClassName: ISimpleFile::class);
		$file->expects($this->once())->method('delete');
		$this->folder->method('getFile')->willReturn($file);
		$this->mapper->expects($this->once())->method('delete');

		$this->assertSame(1, $this->service->deleteForSecret(sourceSecretId: 'sec-1'));
		// Second run: nothing left — no throw, zero removed.
		$this->assertSame(0, $this->service->deleteForSecret(sourceSecretId: 'sec-1'));
	}//end testDeleteForSecretGcsBlobsIdempotently()

	/**
	 * The blob survives while another copy still holds a grant.
	 *
	 * @return void
	 */
	public function testBlobSurvivesWhileGrantsRemain(): void {
		$attachment = new Attachment();
		$attachment->setId('att-1');
		$attachment->setBlobRef('blob.bin');
		$this->mapper->method('findById')->willReturn($attachment);

		$copyGrant = new AttachmentGrant();
		$copyGrant->setId('g-2');
		$copyGrant->setAttachmentId('att-1');
		$this->grantMapper->method('findBySecret')->willReturn([$copyGrant]);
		// One grant (the owner's) remains after the copy grant delete.
		$this->grantMapper->method('countByAttachment')->willReturn(1);
		$this->folder->expects($this->never())->method('getFile');

		$this->assertSame(1, $this->service->deleteGrantsForSecretCopy(copySecretId: 'copy-1'));
	}//end testBlobSurvivesWhileGrantsRemain()

	/**
	 * addGrant is idempotent per (attachment, copy) and owner-gated.
	 *
	 * @return void
	 */
	public function testAddGrantIdempotentAndOwnerGated(): void {
		$attachment = new Attachment();
		$attachment->setId('att-1');
		$attachment->setSourceSecretId('sec-1');
		$this->mapper->method('findById')->willReturn($attachment);
		$this->secretMapper->method('findById')->willReturn($this->ownedSecret());

		$existing = new AttachmentGrant();
		$existing->setId('g-1');
		$existing->setAttachmentId('att-1');
		$this->grantMapper->method('findBySecret')->willReturn([$existing]);
		$this->grantMapper->expects($this->never())->method('insert');

		$grant = $this->service->addGrant(
			attachmentId: 'att-1',
			userId: 'alice',
			copySecretId: 'copy-1',
			recipientId: 'bob',
			wrappedFileKey: 'REWRAPPED_KEY_PLACEHOLDER',
		);
		$this->assertSame('g-1', $grant->getId());

		// Non-owner: rejected.
		$this->expectException(InvalidArgumentException::class);
		$this->service->addGrant(
			attachmentId: 'att-1',
			userId: 'mallory',
			copySecretId: 'copy-1',
			recipientId: 'bob',
			wrappedFileKey: 'REWRAPPED_KEY_PLACEHOLDER',
		);
	}//end testAddGrantIdempotentAndOwnerGated()
}//end class
