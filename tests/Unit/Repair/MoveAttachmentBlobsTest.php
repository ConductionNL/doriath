<?php

/**
 * Unit tests for the attachment blob relocation step.
 *
 * The step deletes user data it has just copied, and the data is AES-GCM
 * ciphertext that cannot be regenerated from anything the server holds. So the
 * behaviours pinned here are the ones standing between a bug and unrecoverable
 * loss: it verifies the copy before removing the source, it refuses rather than
 * overwrites, and it never throws out of a pre-migration step.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Repair
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

namespace OCA\Keepiq\Tests\Unit\Repair;

use OCA\Keepiq\Repair\MoveAttachmentBlobs;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests the blob relocation step.
 */
class MoveAttachmentBlobsTest extends TestCase {

	/**
	 * Warnings the step emitted.
	 *
	 * @var string[]
	 */
	private array $warnings = [];

	/**
	 * Reset captured output.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->warnings = [];
	}//end setUp()

	/**
	 * An IOutput that records warnings.
	 *
	 * @return IOutput The recording output.
	 */
	private function recordingOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		$output->method('warning')->willReturnCallback(function (string $m): void {
			$this->warnings[] = $m;
		});

		return $output;
	}//end recordingOutput()

	/**
	 * Build a blob file mock.
	 *
	 * @param string $name The file name.
	 * @param int    $size The reported size.
	 *
	 * @return ISimpleFile The mocked blob.
	 */
	private function blob(string $name, int $size): ISimpleFile {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getName')->willReturn($name);
		$file->method('getSize')->willReturn($size);
		// Falls back to the string path; the streaming branch is storage-specific.
		$file->method('read')->willReturn(false);
		$file->method('getContent')->willReturn(str_repeat('x', $size));

		return $file;
	}//end blob()

	/**
	 * Wire a factory returning the given source and target folders.
	 *
	 * @param ISimpleFolder|null $source The old blob folder, or null when absent.
	 * @param ISimpleFolder      $target The new blob folder.
	 *
	 * @return IAppDataFactory The mocked factory.
	 */
	private function factory(?ISimpleFolder $source, ISimpleFolder $target): IAppDataFactory {
		$old = $this->createMock(IAppData::class);
		if ($source === null) {
			$old->method('getFolder')->willThrowException(new NotFoundException());
		} else {
			$old->method('getFolder')->willReturn($source);
		}

		$old->method('getDirectoryListing')->willReturn([]);

		$new = $this->createMock(IAppData::class);
		$new->method('getFolder')->willReturn($target);

		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->willReturnCallback(
			static fn (string $ns): IAppData => ($ns === 'doriath') ? $old : $new
		);

		return $factory;
	}//end factory()

	/**
	 * A blob is copied to the new namespace and the source removed.
	 *
	 * @return void
	 */
	public function testMovesABlobAndRemovesTheSource(): void {
		$file = $this->blob('blob-1.bin', 100);
		$file->expects($this->once())->method('delete');

		$source = $this->createMock(ISimpleFolder::class);
		$source->method('getDirectoryListing')->willReturnOnConsecutiveCalls([$file], []);

		$written = $this->createMock(ISimpleFile::class);
		$written->method('getSize')->willReturn(100);

		$target = $this->createMock(ISimpleFolder::class);
		$target->method('fileExists')->willReturn(false);
		$target->expects($this->once())->method('newFile')->willReturn($written);

		(new MoveAttachmentBlobs($this->factory($source, $target)))->run($this->recordingOutput());

		$this->assertSame([], $this->warnings);
	}//end testMovesABlobAndRemovesTheSource()

	/**
	 * A short copy leaves the source in place.
	 *
	 * This is the guard that matters most: deleting a source whose copy did
	 * not land loses ciphertext nothing can regenerate.
	 *
	 * @return void
	 */
	public function testDoesNotDeleteTheSourceWhenTheCopyIsShort(): void {
		$file = $this->blob('blob-1.bin', 100);
		$file->expects($this->never())->method('delete');

		$source = $this->createMock(ISimpleFolder::class);
		$source->method('getDirectoryListing')->willReturn([$file]);

		$short = $this->createMock(ISimpleFile::class);
		$short->method('getSize')->willReturn(41);

		$target = $this->createMock(ISimpleFolder::class);
		$target->method('fileExists')->willReturn(false);
		$target->method('newFile')->willReturn($short);

		(new MoveAttachmentBlobs($this->factory($source, $target)))->run($this->recordingOutput());

		$this->assertCount(1, $this->warnings);
		$this->assertStringContainsString('blob-1.bin', $this->warnings[0]);
	}//end testDoesNotDeleteTheSourceWhenTheCopyIsShort()

	/**
	 * A target of the same size is this step having already run.
	 *
	 * The source is then redundant and goes, so a resumed migration converges
	 * instead of leaving a permanent duplicate.
	 *
	 * @return void
	 */
	public function testTreatsAnEqualSizedTargetAsAlreadyRelocated(): void {
		$file = $this->blob('blob-1.bin', 100);
		$file->expects($this->once())->method('delete');

		$source = $this->createMock(ISimpleFolder::class);
		$source->method('getDirectoryListing')->willReturnOnConsecutiveCalls([$file], []);

		$existing = $this->createMock(ISimpleFile::class);
		$existing->method('getSize')->willReturn(100);

		$target = $this->createMock(ISimpleFolder::class);
		$target->method('fileExists')->willReturn(true);
		$target->method('getFile')->willReturn($existing);
		$target->expects($this->never())->method('newFile');

		(new MoveAttachmentBlobs($this->factory($source, $target)))->run($this->recordingOutput());

		$this->assertSame([], $this->warnings);
	}//end testTreatsAnEqualSizedTargetAsAlreadyRelocated()

	/**
	 * Two distinct blobs sharing a ref are both left in place, and reported.
	 *
	 * Reads resolve the new namespace first, so silently keeping the source
	 * would not help: a human has to say which is correct.
	 *
	 * @return void
	 */
	public function testReportsWhenBothNamespacesHoldDifferentBlobs(): void {
		$file = $this->blob('blob-1.bin', 100);
		$file->expects($this->never())->method('delete');

		$source = $this->createMock(ISimpleFolder::class);
		$source->method('getDirectoryListing')->willReturn([$file]);

		$other = $this->createMock(ISimpleFile::class);
		$other->method('getSize')->willReturn(41);

		$target = $this->createMock(ISimpleFolder::class);
		$target->method('fileExists')->willReturn(true);
		$target->method('getFile')->willReturn($other);
		$target->expects($this->never())->method('newFile');

		(new MoveAttachmentBlobs($this->factory($source, $target)))->run($this->recordingOutput());

		$this->assertCount(1, $this->warnings);
		$this->assertStringContainsString('different sizes', $this->warnings[0]);
	}//end testReportsWhenBothNamespacesHoldDifferentBlobs()

	/**
	 * No old namespace at all is a silent no-op.
	 *
	 * This is the fresh-install and already-migrated case.
	 *
	 * @return void
	 */
	public function testDoesNothingWhenThereIsNoOldFolder(): void {
		$target = $this->createMock(ISimpleFolder::class);
		$target->expects($this->never())->method('newFile');

		(new MoveAttachmentBlobs($this->factory(null, $target)))->run($this->recordingOutput());

		$this->assertSame([], $this->warnings);
	}//end testDoesNothingWhenThereIsNoOldFolder()

	/**
	 * A failing write is reported, not thrown.
	 *
	 * An exception escaping a pre-migration step aborts the upgrade.
	 *
	 * @return void
	 */
	public function testReportsRatherThanThrowsWhenTheCopyFails(): void {
		$file = $this->blob('blob-1.bin', 100);
		$file->expects($this->never())->method('delete');

		$source = $this->createMock(ISimpleFolder::class);
		$source->method('getDirectoryListing')->willReturn([$file]);

		$target = $this->createMock(ISimpleFolder::class);
		$target->method('fileExists')->willReturn(false);
		$target->method('newFile')->willThrowException(new RuntimeException('disk full'));

		(new MoveAttachmentBlobs($this->factory($source, $target)))->run($this->recordingOutput());

		$this->assertCount(1, $this->warnings);
		$this->assertStringContainsString('disk full', $this->warnings[0]);
	}//end testReportsRatherThanThrowsWhenTheCopyFails()
}//end class
