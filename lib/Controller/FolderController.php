<?php

/**
 * Keepiq Folder Controller
 *
 * Authenticated API controller for Folder CRUD: listing, creating, renaming
 * and moving (update), the children endpoint used by the resolution dialog,
 * and the three-mode deletion protocol.
 *
 * @category Controller
 * @package  OCA\Keepiq\Controller
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

namespace OCA\Keepiq\Controller;

use InvalidArgumentException;
use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Db\Folder;
use OCA\Keepiq\Exception\ConflictException;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\FolderService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Authenticated API controller for Folder CRUD.
 */
class FolderController extends OCSController {
	/**
	 * Constructor for FolderController.
	 *
	 * @param IRequest $request The request object
	 * @param FolderService $folderService The folder service
	 * @param IUserSession $userSession The user session
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private FolderService $folderService,
		private IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Resolve the current user's UID or return null.
	 *
	 * @return string|null
	 */
	private function uid(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		return $user->getUID();
	}//end uid()

	/**
	 * Collect the presentation-attribute changes carried by the request.
	 *
	 * Only keys PRESENT in the raw request body are returned: a
	 * key-present-with-null CLEARS the stored value while an absent key
	 * leaves it untouched — the distinction the typed method arguments
	 * cannot make, since both read as null (restyle Stage 9).
	 *
	 * @param string|null $customIcon The custom icon key from the body
	 * @param string|null $customColor The custom color key from the body
	 *
	 * @return array<string,string|null>
	 *
	 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
	 */
	private function attributeChanges(?string $customIcon, ?string $customColor): array {
		$requestParams = $this->request->getParams();
		$changes = [];
		if (array_key_exists('customIcon', $requestParams) === true) {
			$changes['customIcon'] = $customIcon;
		}

		if (array_key_exists('customColor', $requestParams) === true) {
			$changes['customColor'] = $customColor;
		}

		return $changes;
	}//end attributeChanges()

	/**
	 * Apply an update request's mutations in order and return the folder.
	 *
	 * Each part is optional and independent: a rename, a move, and a
	 * presentation-attribute change can arrive together or not at all. When
	 * nothing mutated, the owned folder is loaded so the response still
	 * carries its current state.
	 *
	 * @param string $id The folder ID
	 * @param string|null $name The new name (null = no rename)
	 * @param string|null $parentId The new parent (when moving)
	 * @param bool $move Whether parentId is a move instruction
	 * @param array<string,string|null> $attributeChanges The presentation attributes to apply
	 * @param string $userId The requesting Nextcloud user ID
	 *
	 * @return Folder
	 *
	 * @throws NotFoundException When the folder or target does not exist
	 * @throws ForbiddenException When the folder or target is not owned
	 * @throws ConflictException When a sibling name collides
	 * @throws InvalidArgumentException When a name or attribute key is invalid
	 *
	 * @spec openspec/changes/implement-secrets/tasks.md#task-4.3
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The move flag carries the
	 *   same re-parent-to-root distinction the endpoint itself makes.
	 */
	private function applyUpdates(
		string $id,
		?string $name,
		?string $parentId,
		bool $move,
		array $attributeChanges,
		string $userId,
	): Folder {
		$folder = null;
		if ($name !== null) {
			$folder = $this->folderService->rename($id, $name, $userId);
		}

		if ($move === true) {
			$folder = $this->folderService->move($id, $parentId, $userId);
		}

		if ($attributeChanges !== []) {
			$folder = $this->folderService->updateAttributes($id, $attributeChanges, $userId);
		}

		if ($folder === null) {
			return $this->folderService->getOwned($id, $userId);
		}

		return $folder;
	}//end applyUpdates()

	/**
	 * List the folders owned by the current user.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-secrets/tasks.md#task-4.3
	 */
	#[NoAdminRequired]
	public function index(): JSONResponse {
		$userId = $this->uid();
		if ($userId === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$folders = $this->folderService->listForUser($userId);

		return new JSONResponse(
			data: array_map(static fn ($folder) => $folder->jsonSerialize(), $folders)
		);
	}//end index()

	/**
	 * Create a folder.
	 *
	 * @param string $name The folder name (no slashes)
	 * @param string|null $parentId The parent folder ID (null = root)
	 * @param string|null $customIcon Optional custom icon key (restyle Stage 9)
	 * @param string|null $customColor Optional custom color key (restyle Stage 9)
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-secrets/tasks.md#task-4.3
	 */
	#[NoAdminRequired]
	public function create(
		string $name,
		?string $parentId = null,
		?string $customIcon = null,
		?string $customColor = null,
	): JSONResponse {
		$userId = $this->uid();
		if ($userId === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$folder = $this->folderService->create($name, $parentId, $userId, $customIcon, $customColor);
		} catch (ForbiddenException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		} catch (ConflictException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_CONFLICT);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: $folder->jsonSerialize(), statusCode: Http::STATUS_CREATED);
	}//end create()

	/**
	 * Update a folder — rename, move, and/or update presentation attributes.
	 *
	 * The customIcon/customColor attributes apply only when their KEY is
	 * present in the request body (an explicit null CLEARS the value), so a
	 * rename/move call that does not mention them can never accidentally
	 * wipe a customization (restyle Stage 9).
	 *
	 * @param string $id The folder ID
	 * @param string|null $name The new name (null = no rename)
	 * @param string|null $parentId The new parent (when moving)
	 * @param bool $move Whether parentId is a move instruction
	 * @param string|null $customIcon The new custom icon key (null clears; only applied when the key is present)
	 * @param string|null $customColor The new custom color key (null clears; only applied when the key is present)
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-secrets/tasks.md#task-4.3
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) The move flag distinguishes a
	 *   re-parent-to-root (parentId null) request from a plain rename where
	 *   parentId is simply absent.
	 */
	#[NoAdminRequired]
	public function update(
		string $id,
		?string $name = null,
		?string $parentId = null,
		bool $move = false,
		?string $customIcon = null,
		?string $customColor = null,
	): JSONResponse {
		$userId = $this->uid();
		if ($userId === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$folder = $this->applyUpdates(
				id: $id,
				name: $name,
				parentId: $parentId,
				move: $move,
				attributeChanges: $this->attributeChanges(
					customIcon: $customIcon,
					customColor: $customColor
				),
				userId: $userId
			);
		} catch (NotFoundException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		} catch (ConflictException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_CONFLICT);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}//end try

		return new JSONResponse(data: $folder->jsonSerialize());
	}//end update()

	/**
	 * List a folder's children for the resolution dialog.
	 *
	 * @param string $id The folder ID
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-secrets/tasks.md#task-4.3
	 */
	#[NoAdminRequired]
	public function children(string $id): JSONResponse {
		$userId = $this->uid();
		if ($userId === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$children = $this->folderService->getChildren($id, $userId);
		} catch (NotFoundException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(data: $children);
	}//end children()

	/**
	 * Delete a folder with the appropriate cascade/resolution protocol.
	 *
	 * @param string $id The folder ID
	 * @param string|null $cascade The shorthand cascade mode (delete/move)
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/implement-secrets/tasks.md#task-4.3
	 */
	#[NoAdminRequired]
	public function destroy(string $id, ?string $cascade = null): JSONResponse {
		$userId = $this->uid();
		if ($userId === null) {
			return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		// The resolution plan, when present, arrives in the JSON body.
		$resolution = null;
		$subfolders = $this->request->getParam('subfolders');
		if ($subfolders !== null) {
			$resolution = [
				'subfolders' => $subfolders,
				'directSecrets' => $this->request->getParam('directSecrets', 'move'),
			];
		}

		try {
			$this->folderService->delete($id, $cascade, $resolution, $userId);
		} catch (NotFoundException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_NOT_FOUND);
		} catch (ForbiddenException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_FORBIDDEN);
		} catch (ConflictException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_CONFLICT);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(data: ['message' => $e->getMessage()], statusCode: Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(data: ['status' => 'deleted']);
	}//end destroy()
}//end class
