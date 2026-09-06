<?php

/**
 * Keepiq Folder Tree Service
 *
 * The SHAPE-CHANGING operations of the per-owner folder tree: create, rename
 * and move. Each validates the name, resolves ownership of anything it
 * touches, refuses a cycle, and enforces sibling-name uniqueness through
 * FolderNameGuard before it writes.
 *
 * Deletion is a protocol of its own and lives in FolderDeletionService.
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
use InvalidArgumentException;
use OCA\Keepiq\Db\Folder;
use OCA\Keepiq\Db\FolderMapper;
use OCA\Keepiq\Exception\DuplicateFolderNameException;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Creates, renames and moves folders in the per-owner tree.
 */
class FolderTreeService {
	/**
	 * Constructor for FolderTreeService.
	 *
	 * @param FolderMapper $mapper The folder mapper
	 * @param FolderOwnershipGuard $ownership The folder ownership guard
	 * @param FolderNameGuard $nameGuard The sibling-name guard
	 * @param LoggerInterface $logger The logger interface
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private FolderMapper $mapper,
		private FolderOwnershipGuard $ownership,
		private FolderNameGuard $nameGuard,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Create a folder.
	 *
	 * @param string $name The folder name (no slashes)
	 * @param string|null $parentId The parent folder ID (null = root)
	 * @param string $userId The owning Nextcloud user ID
	 * @param string|null $customIcon Optional custom icon key (validated by FolderService)
	 * @param string|null $customColor Optional custom color key (validated by FolderService)
	 *
	 * @return Folder
	 *
	 * @throws InvalidArgumentException When the name is invalid
	 * @throws NotFoundException When the parent does not exist
	 * @throws ForbiddenException When the parent is not owned
	 * @throws DuplicateFolderNameException When a sibling folder already uses the name
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
	 */
	public function create(
		string $name,
		?string $parentId,
		string $userId,
		?string $customIcon = null,
		?string $customColor = null,
	): Folder {
		$name = trim($name);
		if ($name === '') {
			throw new InvalidArgumentException('Folder name is required');
		}

		if (str_contains($name, '/') === true) {
			throw new InvalidArgumentException('Folder names cannot contain slashes');
		}

		if ($parentId === '') {
			$parentId = null;
		}

		if ($parentId !== null) {
			// Verifies ownership; throws ForbiddenException if not owned.
			$this->ownership->requireOwned(id: $parentId, userId: $userId);
		}

		$this->nameGuard->assertNameUnique(
			ownerType: 'user',
			ownerId: $userId,
			parentId: $parentId,
			name: $name
		);

		$now = new DateTime();
		$folder = new Folder();
		$folder->setId(Uuid::uuid4()->toString());
		$folder->setName($name);
		$folder->setParentId($parentId);
		$folder->setOwnerType('user');
		$folder->setOwnerId($userId);
		$folder->setCustomIcon($customIcon);
		$folder->setCustomColor($customColor);
		$folder->setCreatedAt($now);
		$folder->setUpdatedAt($now);

		$this->mapper->insert($folder);
		$this->logger->info("Keepiq: folder '{$name}' created by {$userId}");

		return $folder;
	}//end create()

	/**
	 * Rename a folder.
	 *
	 * @param string $id The folder ID
	 * @param string $name The new name
	 * @param string $userId The requesting Nextcloud user ID
	 *
	 * @return Folder
	 *
	 * @throws InvalidArgumentException When the name is invalid
	 * @throws NotFoundException When the folder does not exist
	 * @throws ForbiddenException When not owned
	 * @throws DuplicateFolderNameException When a sibling folder already uses the name
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
	 */
	public function rename(string $id, string $name, string $userId): Folder {
		$name = trim($name);
		if ($name === '') {
			throw new InvalidArgumentException('Folder name is required');
		}

		if (str_contains($name, '/') === true) {
			throw new InvalidArgumentException('Folder names cannot contain slashes');
		}

		$folder = $this->ownership->requireOwned(id: $id, userId: $userId);

		$this->nameGuard->assertNameUnique(
			ownerType: $folder->getOwnerType(),
			ownerId: $folder->getOwnerId(),
			parentId: $folder->getParentId(),
			name: $name,
			excludeId: $id
		);

		$folder->setName($name);
		$folder->setUpdatedAt(new DateTime());
		$this->mapper->update($folder);

		return $folder;
	}//end rename()

	/**
	 * Move a folder to a different parent (or to root).
	 *
	 * @param string $id The folder ID
	 * @param string|null $newParentId The target parent ID (null = root)
	 * @param string $userId The requesting Nextcloud user ID
	 *
	 * @return Folder
	 *
	 * @throws InvalidArgumentException When the move would create a cycle
	 * @throws NotFoundException When the folder or target does not exist
	 * @throws ForbiddenException When the folder or target is not owned
	 * @throws DuplicateFolderNameException When the target parent already contains the name
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
	 */
	public function move(string $id, ?string $newParentId, string $userId): Folder {
		$folder = $this->ownership->requireOwned(id: $id, userId: $userId);

		if ($newParentId === '') {
			$newParentId = null;
		}

		if ($newParentId !== null) {
			$this->ownership->requireOwned(id: $newParentId, userId: $userId);

			if ($newParentId === $id || in_array($newParentId, $this->mapper->getSubtreeIds($id), true) === true) {
				throw new InvalidArgumentException('Cannot move a folder into itself or its own subtree');
			}
		}

		$this->nameGuard->assertNameUnique(
			ownerType: $folder->getOwnerType(),
			ownerId: $folder->getOwnerId(),
			parentId: $newParentId,
			name: $folder->getName(),
			excludeId: $id
		);

		$folder->setParentId($newParentId);
		$folder->setUpdatedAt(new DateTime());
		$this->mapper->update($folder);

		return $folder;
	}//end move()
}//end class
