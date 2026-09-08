<?php

/**
 * Moves attachment blobs from the `doriath` AppData namespace to `keepiq`.
 *
 * `IAppDataFactory::get($namespace)` resolves to `appdata_<instanceid>/<namespace>/`,
 * so the namespace string is a STORAGE LOCATION, not a label. Every attachment
 * ever uploaded lives at `appdata_<instanceid>/doriath/attachments/<blob_ref>`,
 * addressed by the `blob_ref` column in `keepiq_attachments`.
 *
 * REPOINTING AttachmentService WITHOUT THIS STEP LOSES EVERY ATTACHMENT, AND
 * LOSES IT SILENTLY. The app would open an empty `appdata_<instanceid>/keepiq/`
 * folder: uploads would keep working, every existing download would 404, and
 * nothing would log an error — the rows still exist and still name a file, just
 * one nothing looks for any more. The bytes are AES-GCM ciphertext whose file
 * key is RSA-wrapped per recipient, so an orphaned blob cannot be regenerated
 * from anything the server holds.
 *
 * IT MOVES THROUGH THE STORAGE API, NOT THE FILESYSTEM. A `mv` on disk would
 * leave `oc_filecache` pointing at the old path, which is the same silent-404
 * this step exists to prevent, one layer down. Going through ISimpleFolder
 * keeps the cache consistent.
 *
 * IT VERIFIES BEFORE IT DELETES. The source is removed only after the target
 * exists and reports the same byte count, because the alternative to a correct
 * copy here is unrecoverable data loss, not an inconvenience.
 *
 * IT REFUSES RATHER THAN OVERWRITES. Where a blob of the same name already
 * exists in the target, something has already written it and choosing between
 * two ciphertexts is not a decision a repair step can make.
 *
 * IT NEVER THROWS. It runs as a pre-migration step, where an escaping
 * exception aborts the upgrade.
 *
 * IT LEAVES THE EMPTY NAMESPACE DIRECTORY BEHIND. `ISimpleRoot` exposes no
 * delete(), so `appdata_<instanceid>/doriath/` itself cannot be removed
 * through the public API, and removing it on the filesystem would strand its
 * `oc_filecache` row — the same staleness this step routes around. An empty
 * directory and one cache row are harmless; the step reports them so nobody
 * has to wonder whether the move half-finished.
 *
 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-blob-addressing-survives-relocation
 *
 * @category  Repair
 * @package   OCA\Keepiq\Repair
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

namespace OCA\Keepiq\Repair;

use OCA\Keepiq\AppInfo\Application;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Throwable;

/**
 * Relocates attachment blobs to the keepiq AppData namespace.
 *
 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-blob-addressing-survives-relocation
 */
class MoveAttachmentBlobs implements IRepairStep {

	/**
	 * The AppData namespace blobs are moving away from.
	 *
	 * @var string
	 */
	private const OLD_NAMESPACE = 'doriath';

	/**
	 * The folder holding the blobs inside either namespace.
	 *
	 * Mirrors AttachmentService::BLOB_FOLDER.
	 *
	 * @var string
	 */
	private const BLOB_FOLDER = 'attachments';

	/**
	 * Constructor.
	 *
	 * @param IAppDataFactory $appDataFactory Resolves an AppData namespace.
	 */
	public function __construct(
		private readonly IAppDataFactory $appDataFactory,
	) {
	}//end __construct()

	/**
	 * Human-readable step name.
	 *
	 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-blob-addressing-survives-relocation
	 *
	 * @return string The name.
	 */
	public function getName(): string {
		return 'Keepiq: move attachment blobs to the keepiq AppData namespace';
	}//end getName()

	/**
	 * Move every blob this install still holds under the old namespace.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/encrypted-attachments/spec.md#requirement-blob-addressing-survives-relocation
	 */
	public function run(IOutput $output): void {
		$source = $this->sourceFolder();
		if ($source === null) {
			$output->info('No blobs under the old AppData namespace - nothing to move.');
			$this->reportEmptyNamespace(output: $output);
			return;
		}

		$listing = $this->listing(folder: $source, output: $output);
		if ($listing === []) {
			$this->removeEmptySource(source: $source, output: $output);
			$output->info('The old blob folder is already empty - nothing to move.');
			return;
		}

		$target = $this->targetFolder(output: $output);
		if ($target === null) {
			return;
		}

		$moved = 0;
		$skipped = 0;
		foreach ($listing as $file) {
			if ($this->moveOne(file: $file, target: $target, output: $output) === true) {
				$moved++;
				continue;
			}

			$skipped++;
		}

		$output->info(sprintf('Moved %d attachment blob(s), skipped %d.', $moved, $skipped));

		if ($skipped === 0) {
			$this->removeEmptySource(source: $source, output: $output);
			$this->reportEmptyNamespace(output: $output);
		}
	}//end run()

	/**
	 * The blob folder under the old namespace, or null when there is none.
	 *
	 * @return ISimpleFolder|null The source folder.
	 */
	private function sourceFolder(): ?ISimpleFolder {
		try {
			return $this->appDataFactory->get(self::OLD_NAMESPACE)->getFolder(self::BLOB_FOLDER);
		} catch (Throwable) {
			return null;
		}
	}//end sourceFolder()

	/**
	 * The blob folder under the new namespace, creating it when absent.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return ISimpleFolder|null The target folder, or null when unavailable.
	 */
	private function targetFolder(IOutput $output): ?ISimpleFolder {
		try {
			$appData = $this->appDataFactory->get(Application::APP_ID);

			try {
				return $appData->getFolder(self::BLOB_FOLDER);
			} catch (NotFoundException) {
				return $appData->newFolder(self::BLOB_FOLDER);
			}
		} catch (Throwable $e) {
			$output->warning('Could not open the target blob folder, leaving every blob in place: ' . $e->getMessage());
			return null;
		}
	}//end targetFolder()

	/**
	 * The files in a folder, or an empty list when it cannot be read.
	 *
	 * @param ISimpleFolder $folder The folder to list.
	 * @param IOutput       $output Repair output.
	 *
	 * @return ISimpleFile[] The files.
	 */
	private function listing(ISimpleFolder $folder, IOutput $output): array {
		try {
			return $folder->getDirectoryListing();
		} catch (Throwable $e) {
			$output->warning('Could not list the old blob folder: ' . $e->getMessage());
			return [];
		}
	}//end listing()

	/**
	 * Copy one blob to the target and remove the source once verified.
	 *
	 * @param ISimpleFile   $file   The blob to move.
	 * @param ISimpleFolder $target The destination folder.
	 * @param IOutput       $output Repair output.
	 *
	 * @return bool True when the blob now lives only in the target.
	 */
	private function moveOne(ISimpleFile $file, ISimpleFolder $target, IOutput $output): bool {
		$name = $file->getName();

		try {
			if ($target->fileExists($name) === true) {
				$output->warning(
					sprintf('Blob "%s" already exists under the new namespace - leaving both alone.', $name)
				);
				return false;
			}

			$expected = $file->getSize();
			$written = $this->copy(file: $file, target: $target, name: $name);

			if ($written->getSize() !== $expected) {
				$output->warning(
					sprintf(
						'Blob "%s" copied as %s bytes but the source is %s - keeping the source, remove the partial copy by hand.',
						$name,
						(string)$written->getSize(),
						(string)$expected
					)
				);
				return false;
			}

			$file->delete();

			return true;
		} catch (Throwable $e) {
			$output->warning(sprintf('Could not move blob "%s": %s', $name, $e->getMessage()));
			return false;
		}
	}//end moveOne()

	/**
	 * Write a blob into the target folder, streaming where possible.
	 *
	 * @param ISimpleFile   $file   The source blob.
	 * @param ISimpleFolder $target The destination folder.
	 * @param string        $name   The file name to write.
	 *
	 * @return ISimpleFile The written file.
	 */
	private function copy(ISimpleFile $file, ISimpleFolder $target, string $name): ISimpleFile {
		// Streamed so a large attachment never lands in memory whole. Not every
		// storage backend returns a usable handle, hence the string fallback.
		$handle = $file->read();

		if (is_resource($handle) === false) {
			return $target->newFile($name, $file->getContent());
		}

		try {
			return $target->newFile($name, $handle);
		} finally {
			// The caller owns this handle: Nextcloud's file_put_contents copies
			// FROM a passed resource and closes only its own target, never the
			// source. So it is still open here, on the throwing path too.
			fclose($handle);
		}
	}//end copy()

	/**
	 * Delete the old blob folder once nothing is left in it.
	 *
	 * @param ISimpleFolder $source The old folder.
	 * @param IOutput       $output Repair output.
	 *
	 * @return void
	 */
	private function removeEmptySource(ISimpleFolder $source, IOutput $output): void {
		try {
			if ($source->getDirectoryListing() !== []) {
				return;
			}

			$source->delete();
		} catch (Throwable $e) {
			$output->warning('Could not remove the emptied blob folder: ' . $e->getMessage());
		}
	}//end removeEmptySource()
	/**
	 * Note the empty old namespace directory, which cannot be deleted here.
	 *
	 * @param IOutput $output Repair output.
	 *
	 * @return void
	 */
	private function reportEmptyNamespace(IOutput $output): void {
		try {
			if ($this->appDataFactory->get(self::OLD_NAMESPACE)->getDirectoryListing() !== []) {
				return;
			}

			$output->info(
				sprintf(
					'The empty "%s" AppData directory remains; ISimpleRoot exposes no delete() so it '
					. 'must be removed by hand if you want it gone. Nothing reads it.',
					self::OLD_NAMESPACE
				)
			);
		} catch (Throwable) {
			// Nothing to report: the namespace is already gone or unreadable.
		}
	}//end reportEmptyNamespace()
}//end class
