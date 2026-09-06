<?php

/**
 * Keepiq Folder Service
 *
 * The API-facing entry point of the per-owner folder tree. Every operation
 * starts from the same ownership guard and is then carried out by the
 * collaborator that owns it:
 *  - FolderOwnershipGuard   resolves a folder and asserts the caller owns it
 *  - FolderTreeService      create, rename, move
 *  - FolderDeletionService  the three-mode deletion protocol and the children
 *                           payload the resolution dialog is driven from
 *  - FolderCascadeService   the subtree mechanics a delete cascades into
 *  - FolderNameGuard        sibling-name uniqueness
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
use OCA\Keepiq\Exception\ConflictException;
use OCA\Keepiq\Exception\DuplicateFolderNameException;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;

/**
 * Business logic for the folder tree.
 */
class FolderService {
	/**
	 * Constructor for FolderService.
	 *
	 * @param FolderMapper $mapper The folder mapper
	 * @param FolderOwnershipGuard $ownership The folder ownership guard
	 * @param FolderTreeService $tree The create/rename/move operations
	 * @param FolderDeletionService $deletion The folder deletion protocol
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private FolderMapper $mapper,
		private FolderOwnershipGuard $ownership,
		private FolderTreeService $tree,
		private FolderDeletionService $deletion,
	) {
	}//end __construct()

	/**
	 * List the folders owned by a user.
	 *
	 * @param string $userId The Nextcloud user ID
	 *
	 * @return Folder[]
	 *
	 * @spec exclude Plain owner-scoped listing; no dedicated spec requirement.
	 */
	public function listForUser(string $userId): array {
		return $this->mapper->findByOwner('user', $userId);
	}//end listForUser()

	/**
	 * Load a folder and verify the requester owns it.
	 *
	 * @param string $id The folder ID
	 * @param string $userId The requesting Nextcloud user ID
	 *
	 * @return Folder
	 *
	 * @throws NotFoundException When the folder does not exist
	 * @throws ForbiddenException When the folder belongs to another user
	 *
	 * @spec exclude Ownership guard delegated to FolderOwnershipGuard; no spec behaviour of its own.
	 */
	public function getOwned(string $id, string $userId): Folder {
		return $this->ownership->requireOwned(id: $id, userId: $userId);
	}//end getOwned()

	/**
	 * Create a folder.
	 *
	 * @param string $name The folder name (no slashes)
	 * @param string|null $parentId The parent folder ID (null = root)
	 * @param string $userId The owning Nextcloud user ID
	 * @param string|null $customIcon Optional custom icon key (restyle Stage 9)
	 * @param string|null $customColor Optional custom color key (restyle Stage 9)
	 *
	 * @return Folder
	 *
	 * @throws InvalidArgumentException When the name or an attribute key is invalid
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
		$this->assertAttributeFormat(attribute: 'customIcon', value: $customIcon);
		$this->assertAttributeFormat(attribute: 'customColor', value: $customColor);

		return $this->tree->create(
			name: $name,
			parentId: $parentId,
			userId: $userId,
			customIcon: $customIcon,
			customColor: $customColor
		);
	}//end create()

	/**
	 * Update presentation attributes (customIcon, customColor) on a folder.
	 *
	 * Only keys PRESENT in $changes are applied; an explicit null CLEARS the
	 * value, an absent key leaves it untouched — so a rename/move request
	 * that carries no attribute keys can never accidentally wipe a color.
	 *
	 * Values are validated on FORMAT only (lowercase kebab key, max 64
	 * chars): the frontend owns the catalogs, and an unknown key renders as
	 * the fallback glyph there — which keeps old servers forward-compatible
	 * with newer frontends.
	 *
	 * @param string $id The folder ID
	 * @param array<string,string|null> $changes Attribute name to new value (null clears)
	 * @param string $userId The requesting Nextcloud user ID
	 *
	 * @return Folder
	 *
	 * @throws InvalidArgumentException When a value fails the format check
	 * @throws NotFoundException When the folder does not exist
	 * @throws ForbiddenException When the folder is not owned
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
	 */
	public function updateAttributes(string $id, array $changes, string $userId): Folder {
		$folder = $this->ownership->requireOwned(id: $id, userId: $userId);

		if (array_key_exists('customIcon', $changes) === true) {
			$this->assertAttributeFormat(
				attribute: 'customIcon',
				value: $changes['customIcon']
			);
			$folder->setCustomIcon($changes['customIcon']);
		}

		if (array_key_exists('customColor', $changes) === true) {
			$this->assertAttributeFormat(
				attribute: 'customColor',
				value: $changes['customColor']
			);
			$folder->setCustomColor($changes['customColor']);
		}

		$folder->setUpdatedAt(new DateTime());
		$this->mapper->update($folder);

		return $folder;
	}//end updateAttributes()

	/**
	 * Reject a customization value that is not a catalog-shaped key.
	 *
	 * Null passes (it means "unset"/"clear"); anything else must be a
	 * lowercase kebab identifier of at most 64 characters. Free text and
	 * hex values are refused — the columns store KEYS, not presentation.
	 *
	 * @param string $attribute The attribute name (for the error message)
	 * @param string|null $value The candidate value
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the value fails the format check
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
	 */
	private function assertAttributeFormat(string $attribute, ?string $value): void {
		if ($value === null) {
			return;
		}

		if (preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $value) !== 1) {
			throw new InvalidArgumentException(
				"Invalid {$attribute}: expected a lowercase kebab-case key of at most 64 characters"
			);
		}
	}//end assertAttributeFormat()

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
		return $this->tree->rename(id: $id, name: $name, userId: $userId);
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
		return $this->tree->move(id: $id, newParentId: $newParentId, userId: $userId);
	}//end move()

	/**
	 * Build the children payload for the resolution dialog: the folder's
	 * direct secret count and its direct subfolders with recursive counts.
	 * Ownership is verified here; the payload is built by
	 * FolderDeletionService.
	 *
	 * @param string $id The folder ID
	 * @param string $userId The requesting Nextcloud user ID
	 *
	 * @return array<string,mixed>
	 *
	 * @throws NotFoundException When the folder does not exist
	 * @throws ForbiddenException When the folder is not owned
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.2
	 */
	public function getChildren(string $id, string $userId): array {
		$this->ownership->requireOwned(id: $id, userId: $userId);

		return $this->deletion->describeChildren(id: $id);
	}//end getChildren()

	/**
	 * Delete a folder, applying the appropriate protocol.
	 *
	 * Ownership is verified here; the protocol itself lives in
	 * FolderDeletionService.
	 *
	 * @param string $id The folder ID
	 * @param string|null $cascade The shorthand cascade mode (delete/move) for leaf folders
	 * @param array<string,mixed>|null $resolution The per-subfolder resolution body
	 * @param string $userId The requesting Nextcloud user ID
	 *
	 * @return void
	 *
	 * @throws NotFoundException When the folder does not exist
	 * @throws ForbiddenException When not owned
	 * @throws ConflictException When a non-empty folder is deleted without a plan
	 * @throws InvalidArgumentException When the resolution body is incomplete
	 *
	 * @spec openspec/changes/add-secret-audit-trail/tasks.md#task-3.2
	 */
	public function delete(string $id, ?string $cascade, ?array $resolution, string $userId): void {
		$folder = $this->ownership->requireOwned(id: $id, userId: $userId);

		$this->deletion->delete(
			folder: $folder,
			cascade: $cascade,
			resolution: $resolution,
			userId: $userId
		);
	}//end delete()
}//end class
