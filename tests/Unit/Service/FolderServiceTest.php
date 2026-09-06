<?php

/**
 * Unit tests for FolderService.
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
use OCA\Keepiq\Db\Folder;
use OCA\Keepiq\Db\FolderMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Exception\ConflictException;
use OCA\Keepiq\Exception\DuplicateFolderNameException;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\FolderCascadeService;
use OCA\Keepiq\Service\FolderDeletionService;
use OCA\Keepiq\Service\FolderNameGuard;
use OCA\Keepiq\Service\FolderOwnershipGuard;
use OCA\Keepiq\Service\FolderService;
use OCA\Keepiq\Service\FolderTreeService;
use OCA\Keepiq\Service\SecretChildDataCleaner;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for FolderService.
 */
class FolderServiceTest extends TestCase {

	/**
	 * @var FolderService
	 */
	private FolderService $service;

	/**
	 * @var FolderMapper
	 */
	private $mapper;

	/**
	 * @var SecretMapper
	 */
	private $secretMapper;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(FolderMapper::class);
		$this->secretMapper = $this->createMock(SecretMapper::class);
		$logger = $this->createMock(LoggerInterface::class);

		$nameGuard = new FolderNameGuard(mapper: $this->mapper);
		$childData = new SecretChildDataCleaner(secretMapper: $this->secretMapper);
		$ownership = new FolderOwnershipGuard(mapper: $this->mapper);

		$this->service = new FolderService(
			mapper: $this->mapper,
			ownership: $ownership,
			tree: new FolderTreeService(
				mapper: $this->mapper,
				ownership: $ownership,
				nameGuard: $nameGuard,
				logger: $logger,
			),
			deletion: new FolderDeletionService(
				mapper: $this->mapper,
				secretMapper: $this->secretMapper,
				cascade: new FolderCascadeService(
					mapper: $this->mapper,
					secretMapper: $this->secretMapper,
					childData: $childData,
					nameGuard: $nameGuard,
				),
				logger: $logger,
				eventDispatcher: null,
			),
		);
	}//end setUp()

	/**
	 * Build a folder owned by a user.
	 *
	 * @param string $id The folder ID
	 * @param string|null $parentId The parent ID
	 * @param string $ownerId The owner ID
	 *
	 * @return Folder
	 */
	private function makeFolder(string $id = 'f-1', ?string $parentId = null, string $ownerId = 'alice'): Folder {
		$folder = new Folder();
		$folder->setId($id);
		$folder->setName('Folder ' . $id);
		$folder->setParentId($parentId);
		$folder->setOwnerType('user');
		$folder->setOwnerId($ownerId);
		return $folder;
	}//end makeFolder()

	/**
	 * Creating a root folder sets a null parent and the owner.
	 *
	 * @return void
	 */
	public function testCreateRootFolder(): void {
		$this->mapper->expects($this->once())->method('insert');

		$folder = $this->service->create(name: 'Work', parentId: null, userId: 'alice');

		$this->assertSame('Work', $folder->getName());
		$this->assertNull($folder->getParentId());
		$this->assertSame('alice', $folder->getOwnerId());
	}//end testCreateRootFolder()

	/**
	 * A slash in the name is rejected.
	 *
	 * @return void
	 */
	public function testCreateFolderSlashRejected(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->service->create(name: 'a/b', parentId: null, userId: 'alice');
	}//end testCreateFolderSlashRejected()

	/**
	 * Creating under a non-owned parent is forbidden.
	 *
	 * @return void
	 */
	public function testCreateUnderForeignParentForbidden(): void {
		$this->mapper->method('findById')->willReturn($this->makeFolder(ownerId: 'bob'));

		$this->expectException(ForbiddenException::class);
		$this->service->create(name: 'Sub', parentId: 'f-1', userId: 'alice');
	}//end testCreateUnderForeignParentForbidden()

	/**
	 * Accessing a missing folder throws NotFound.
	 *
	 * @return void
	 */
	public function testGetOwnedMissing(): void {
		$this->mapper->method('findById')->willThrowException(new DoesNotExistException('nope'));
		$this->expectException(NotFoundException::class);
		$this->service->getOwned(id: 'gone', userId: 'alice');
	}//end testGetOwnedMissing()

	/**
	 * Renaming updates the name.
	 *
	 * @return void
	 */
	public function testRename(): void {
		$this->mapper->method('findById')->willReturn($this->makeFolder());
		$this->mapper->expects($this->once())->method('update');

		$folder = $this->service->rename(id: 'f-1', name: 'Renamed', userId: 'alice');
		$this->assertSame('Renamed', $folder->getName());
	}//end testRename()

	/**
	 * Moving into own subtree is rejected.
	 *
	 * @return void
	 */
	public function testMoveIntoOwnSubtreeRejected(): void {
		$this->mapper->method('findById')->willReturn($this->makeFolder(id: 'f-1'));
		$this->mapper->method('getSubtreeIds')->willReturn(['f-1', 'child']);

		$this->expectException(InvalidArgumentException::class);
		$this->service->move(id: 'f-1', newParentId: 'child', userId: 'alice');
	}//end testMoveIntoOwnSubtreeRejected()

	/**
	 * Deleting an empty folder removes it directly.
	 *
	 * @return void
	 */
	public function testDeleteEmptyFolder(): void {
		$this->mapper->method('findById')->willReturn($this->makeFolder());
		$this->mapper->method('findChildren')->willReturn([]);
		$this->secretMapper->method('countByFolder')->willReturn(0);
		$this->mapper->expects($this->once())->method('delete');

		$this->service->delete(id: 'f-1', cascade: null, resolution: null, userId: 'alice');
	}//end testDeleteEmptyFolder()

	/**
	 * Deleting a non-empty leaf without a cascade flag is a conflict.
	 *
	 * @return void
	 */
	public function testDeleteNonEmptyWithoutCascadeConflict(): void {
		$this->mapper->method('findById')->willReturn($this->makeFolder());
		$this->mapper->method('findChildren')->willReturn([]);
		$this->secretMapper->method('countByFolder')->willReturn(3);

		$this->expectException(ConflictException::class);
		$this->service->delete(id: 'f-1', cascade: null, resolution: null, userId: 'alice');
	}//end testDeleteNonEmptyWithoutCascadeConflict()

	/**
	 * cascade=delete removes the folder and its secrets.
	 *
	 * @return void
	 */
	public function testDeleteCascadeDelete(): void {
		$this->mapper->method('findById')->willReturn($this->makeFolder());
		$this->mapper->method('findChildren')->willReturn([]);
		$this->secretMapper->method('countByFolder')->willReturn(2);
		$this->secretMapper->expects($this->once())->method('deleteByFolderId')->with('f-1');
		$this->mapper->expects($this->once())->method('delete');

		$this->service->delete(id: 'f-1', cascade: 'delete', resolution: null, userId: 'alice');
	}//end testDeleteCascadeDelete()

	/**
	 * cascade=move re-parents secrets to the folder's parent.
	 *
	 * @return void
	 */
	public function testDeleteCascadeMove(): void {
		$this->mapper->method('findById')->willReturn($this->makeFolder(id: 'f-1', parentId: 'parent'));
		$this->mapper->method('findChildren')->willReturn([]);
		$this->secretMapper->method('countByFolder')->willReturn(2);
		$this->secretMapper->expects($this->once())
			->method('updateFolderForSecrets')
			->with('f-1', 'parent');

		$this->service->delete(id: 'f-1', cascade: 'move', resolution: null, userId: 'alice');
	}//end testDeleteCascadeMove()

	/**
	 * Deleting a folder with subfolders requires a resolution body.
	 *
	 * @return void
	 */
	public function testDeleteWithSubfoldersRequiresResolution(): void {
		$this->mapper->method('findById')->willReturn($this->makeFolder());
		$this->mapper->method('findChildren')->willReturn([$this->makeFolder(id: 'sub')]);
		$this->secretMapper->method('countByFolder')->willReturn(0);

		$this->expectException(ConflictException::class);
		$this->service->delete(id: 'f-1', cascade: 'delete', resolution: null, userId: 'alice');
	}//end testDeleteWithSubfoldersRequiresResolution()

	/**
	 * A resolution body missing a subfolder entry is rejected.
	 *
	 * @return void
	 */
	public function testResolutionMissingSubfolderRejected(): void {
		$this->mapper->method('findById')->willReturn($this->makeFolder());
		$this->mapper->method('findChildren')->willReturn([$this->makeFolder(id: 'sub')]);
		$this->secretMapper->method('countByFolder')->willReturn(0);

		$this->expectException(InvalidArgumentException::class);
		$this->service->delete(
			id: 'f-1',
			cascade: null,
			resolution: ['subfolders' => ['other' => 'keep']],
			userId: 'alice',
		);
	}//end testResolutionMissingSubfolderRejected()

	/**
	 * Resolution action keep re-parents the subfolder.
	 *
	 * @return void
	 */
	public function testResolutionKeepReparentsSubfolder(): void {
		$parent = $this->makeFolder(id: 'f-1', parentId: 'root');
		$sub = $this->makeFolder(id: 'sub', parentId: 'f-1');

		$this->mapper->method('findById')->willReturnMap(
			[
				['f-1', $parent],
				['sub', $sub],
			]
		);
		$this->mapper->method('findChildren')->willReturn([$sub]);
		$this->secretMapper->method('countByFolder')->willReturn(0);

		// The kept subfolder is updated (re-parented) and the deleted folder removed.
		$this->mapper->expects($this->atLeastOnce())->method('update');
		$this->mapper->expects($this->atLeastOnce())->method('delete');

		$this->service->delete(
			id: 'f-1',
			cascade: null,
			resolution: ['directSecrets' => 'move', 'subfolders' => ['sub' => 'keep']],
			userId: 'alice',
		);
	}//end testResolutionKeepReparentsSubfolder()

	/**
	 * Children endpoint reports direct secret count and subfolder counts.
	 *
	 * @return void
	 */
	public function testGetChildren(): void {
		$this->mapper->method('findById')->willReturn($this->makeFolder(id: 'f-1'));
		$this->mapper->method('findChildren')->willReturn([$this->makeFolder(id: 'sub')]);
		$this->mapper->method('getSubtreeIds')->willReturn(['sub', 'nested']);
		$this->secretMapper->method('countByFolder')->willReturn(2);
		$this->secretMapper->method('countByFolderIds')->willReturn(3);

		$result = $this->service->getChildren(id: 'f-1', userId: 'alice');

		$this->assertSame(2, $result['directSecretCount']);
		$this->assertCount(1, $result['subfolders']);
		$this->assertSame(3, $result['subfolders'][0]['secretCount']);
		$this->assertSame(1, $result['subfolders'][0]['subfolderCount']);
	}//end testGetChildren()

	/**
	 * Accessing a non-owned folder's children is forbidden.
	 *
	 * @return void
	 */
	public function testGetChildrenForeignForbidden(): void {
		$this->mapper->method('findById')->willReturn($this->makeFolder(ownerId: 'bob'));

		$this->expectException(ForbiddenException::class);
		$this->service->getChildren(id: 'f-1', userId: 'alice');
	}//end testGetChildrenForeignForbidden()

	/**
	 * Creating a folder whose name already exists in the parent is rejected.
	 *
	 * @return void
	 */
	public function testCreateDuplicateNameRejected(): void {
		$this->mapper->method('existsInParent')->willReturn(true);
		$this->mapper->expects($this->never())->method('insert');

		$this->expectException(DuplicateFolderNameException::class);
		$this->expectExceptionMessageMatches('/already exists/');

		$this->service->create(name: 'Duplicate', parentId: null, userId: 'alice');
	}//end testCreateDuplicateNameRejected()

	/**
	 * Renaming a folder to a name an existing sibling already uses is rejected.
	 *
	 * @return void
	 */
	public function testRenameDuplicateNameRejected(): void {
		$folder = $this->makeFolder(id: 'f-1', parentId: 'parent-1');

		$this->mapper->method('findById')->willReturn($folder);
		$this->mapper->method('existsInParent')
			->with('user', 'alice', 'parent-1', 'New Name', 'f-1')
			->willReturn(true);
		$this->mapper->expects($this->never())->method('update');

		$this->expectException(DuplicateFolderNameException::class);

		$this->service->rename(id: 'f-1', name: 'New Name', userId: 'alice');
	}//end testRenameDuplicateNameRejected()

	/**
	 * Moving a folder into a parent that already contains its name is rejected.
	 *
	 * @return void
	 */
	public function testMoveDuplicateNameRejected(): void {
		$folder = $this->makeFolder(id: 'f-1', parentId: null);
		$parent = $this->makeFolder(id: 'parent-folder-id', parentId: null);

		$this->mapper->method('findById')->willReturnMap(
			[
				['f-1', $folder],
				['parent-folder-id', $parent],
			]
		);
		$this->mapper->method('getSubtreeIds')->willReturn(['f-1']);
		$this->mapper->method('existsInParent')
			->with('user', 'alice', 'parent-folder-id', 'Folder f-1', 'f-1')
			->willReturn(true);
		$this->mapper->expects($this->never())->method('update');

		$this->expectException(DuplicateFolderNameException::class);

		$this->service->move(id: 'f-1', newParentId: 'parent-folder-id', userId: 'alice');
	}//end testMoveDuplicateNameRejected()

	/**
	 * Creating with customization keys persists both (restyle Stage 9).
	 *
	 * @return void
	 */
	public function testCreateWithCustomization(): void {
		$this->mapper->expects($this->once())->method('insert');

		$folder = $this->service->create(
			name: 'Work',
			parentId: null,
			userId: 'alice',
			customIcon: 'briefcase',
			customColor: 'blue',
		);

		$this->assertSame('briefcase', $folder->getCustomIcon());
		$this->assertSame('blue', $folder->getCustomColor());
	}//end testCreateWithCustomization()

	/**
	 * A non-key customization value is rejected on create — the columns
	 * store catalog KEYS, never hex or free text.
	 *
	 * @return void
	 */
	public function testCreateInvalidCustomColorRejected(): void {
		$this->mapper->expects($this->never())->method('insert');

		$this->expectException(InvalidArgumentException::class);
		$this->service->create(
			name: 'Work',
			parentId: null,
			userId: 'alice',
			customColor: '#ff0000',
		);
	}//end testCreateInvalidCustomColorRejected()

	/**
	 * updateAttributes sets the values present in the changes map.
	 *
	 * @return void
	 */
	public function testUpdateAttributesSetsValues(): void {
		$this->mapper->method('findById')->willReturn($this->makeFolder());
		$this->mapper->expects($this->once())->method('update');

		$folder = $this->service->updateAttributes(
			id: 'f-1',
			changes: ['customIcon' => 'star', 'customColor' => 'green'],
			userId: 'alice',
		);

		$this->assertSame('star', $folder->getCustomIcon());
		$this->assertSame('green', $folder->getCustomColor());
	}//end testUpdateAttributesSetsValues()

	/**
	 * A key present with an explicit null CLEARS the stored value.
	 *
	 * @return void
	 */
	public function testUpdateAttributesNullClears(): void {
		$folder = $this->makeFolder();
		$folder->setCustomIcon('star');
		$folder->setCustomColor('green');
		$this->mapper->method('findById')->willReturn($folder);

		$updated = $this->service->updateAttributes(
			id: 'f-1',
			changes: ['customIcon' => null, 'customColor' => null],
			userId: 'alice',
		);

		$this->assertNull($updated->getCustomIcon());
		$this->assertNull($updated->getCustomColor());
	}//end testUpdateAttributesNullClears()

	/**
	 * A key ABSENT from the changes map leaves its value untouched — the
	 * distinction that keeps a color-only update from wiping the icon.
	 *
	 * @return void
	 */
	public function testUpdateAttributesAbsentKeyUntouched(): void {
		$folder = $this->makeFolder();
		$folder->setCustomIcon('star');
		$this->mapper->method('findById')->willReturn($folder);

		$updated = $this->service->updateAttributes(
			id: 'f-1',
			changes: ['customColor' => 'red'],
			userId: 'alice',
		);

		$this->assertSame('star', $updated->getCustomIcon());
		$this->assertSame('red', $updated->getCustomColor());
	}//end testUpdateAttributesAbsentKeyUntouched()

	/**
	 * Only the owner may update a folder's attributes.
	 *
	 * @return void
	 */
	public function testUpdateAttributesForeignForbidden(): void {
		$this->mapper->method('findById')->willReturn($this->makeFolder(ownerId: 'bob'));
		$this->mapper->expects($this->never())->method('update');

		$this->expectException(ForbiddenException::class);
		$this->service->updateAttributes(
			id: 'f-1',
			changes: ['customColor' => 'red'],
			userId: 'alice',
		);
	}//end testUpdateAttributesForeignForbidden()

	/**
	 * A malformed key (uppercase / spaces / over-long) is rejected.
	 *
	 * @return void
	 */
	public function testUpdateAttributesInvalidFormatRejected(): void {
		$this->mapper->method('findById')->willReturn($this->makeFolder());
		$this->mapper->expects($this->never())->method('update');

		$this->expectException(InvalidArgumentException::class);
		$this->service->updateAttributes(
			id: 'f-1',
			changes: ['customIcon' => 'Not A Key!'],
			userId: 'alice',
		);
	}//end testUpdateAttributesInvalidFormatRejected()

	/**
	 * A kept subfolder that would collide in the destination parent is rejected.
	 *
	 * @return void
	 */
	public function testDeleteKeepDuplicateNameRejected(): void {
		$parent = $this->makeFolder(id: 'f-1', parentId: 'grandparent-1');
		$sub = $this->makeFolder(id: 'sub', parentId: 'f-1');

		$this->mapper->method('findById')->willReturnMap(
			[
				['f-1', $parent],
				['sub', $sub],
			]
		);
		$this->mapper->method('findChildren')->willReturn([$sub]);
		$this->secretMapper->method('countByFolder')->willReturn(0);
		$this->mapper->method('existsInParent')
			->with('user', 'alice', 'grandparent-1', 'Folder sub', 'sub')
			->willReturn(true);

		$this->expectException(DuplicateFolderNameException::class);

		$this->service->delete(
			id: 'f-1',
			cascade: null,
			resolution: ['directSecrets' => 'move', 'subfolders' => ['sub' => 'keep']],
			userId: 'alice',
		);
	}//end testDeleteKeepDuplicateNameRejected()
}//end class
