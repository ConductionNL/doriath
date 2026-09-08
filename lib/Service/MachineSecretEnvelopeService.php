<?php

/**
 * Keepiq Machine Secret Envelope Service
 *
 * Serializes a Secret into the versioned `doriath-machine-secret-v1`
 * machine-to-machine response envelope and derives the strong ETag used
 * for rotation polling. The envelope is self-describing: it names its
 * format version, the encryption suite, the suite certificate
 * fingerprint, and the encryption scheme (the existing ADR-003 RSA
 * path), so a consumer can decrypt with only the envelope and its own
 * private key. The server returns ciphertext only — never plaintext.
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

use OCA\Keepiq\AppInfo\Application as KeepiqApp;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\FolderMapper;
use OCA\Keepiq\Db\Secret;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Builds the `doriath-machine-secret-v1` envelope and its ETag.
 *
 * The envelope shape is the public, versioned contract consumed by the
 * OpenConnector-side resolver (and any other machine consumer). Breaking
 * changes require a new format identifier and a discovery-document bump,
 * never an in-place mutation — see the secret-store-api capability spec.
 */
class MachineSecretEnvelopeService {
	/**
	 * The current envelope format identifier.
	 *
	 * STILL `doriath-` AFTER THE doriath -> keepiq RENAME, and it stays that
	 * way until the first stable release. This string is a version tag on a published
	 * wire format, not an app id: it is advertised in the discovery
	 * document's `envelopeFormats`, and every machine consumer asserts on it
	 * before attempting decryption.
	 *
	 * IT CANNOT BE DUAL-VALUED THE WAY THE AUDIENCE AND THE DISCOVERY PATH
	 * CAN. Those are inbound — the consumer offers a value and this server
	 * decides whether to honour it, so accepting a second one costs nothing
	 * and no consumer notices. This is outbound: exactly one string goes into
	 * the envelope's `format` field, and whichever one it is, every consumer
	 * pinned to the other rejects the secret. There is no server-side change
	 * that makes a flip safe.
	 *
	 * So the compatibility work is on the consumer, and the only thing this
	 * server can usefully do is say what is coming. UPCOMING_FORMAT is
	 * published in discovery so consumers can be updated to accept BOTH names
	 * ahead of time; once they do, the switch is a non-event.
	 * The v1 envelope's BYTES do not change with the name — the successor
	 * differs in identifier only.
	 *
	 * @var string
	 */
	public const FORMAT = 'doriath-machine-secret-v1';

	/**
	 * The format identifier that replaces FORMAT at the first stable release.
	 *
	 * Published, not emitted. Nothing writes this value yet; it exists so a
	 * consumer can be taught to accept it before it starts arriving.
	 *
	 * @var string
	 */
	public const UPCOMING_FORMAT = 'keepiq-machine-secret-v1';

	/**
	 * The app version in which UPCOMING_FORMAT starts being emitted.
	 *
	 * @var string
	 */
	public const UPCOMING_FORMAT_APP_VERSION = KeepiqApp::PRE_STABLE_COMPAT_REMOVED_IN;

	/**
	 * The encryption scheme identifier naming the existing ADR-003 path
	 * (RSA-OAEP-SHA256 with 512-byte block chunking — see EncryptService).
	 * Named here so a consumer can implement decryption from the contract
	 * documentation alone; this change introduces no new cipher.
	 *
	 * @var string
	 */
	public const SCHEME = 'rsa-oaep-sha256-chunked-v1';

	/**
	 * Constructor for MachineSecretEnvelopeService.
	 *
	 * @param EncryptionSuiteMapper $suiteMapper The encryption-suite mapper
	 * @param FolderMapper $folderMapper The folder mapper
	 *
	 * @return void
	 */
	public function __construct(
		private EncryptionSuiteMapper $suiteMapper,
		private FolderMapper $folderMapper,
	) {
	}//end __construct()

	/**
	 * Serialize a secret into the `doriath-machine-secret-v1` envelope.
	 *
	 * Returns plaintext-safe metadata (id, name, url, derived folder path,
	 * type, timestamps), an `encryption` block (suite id, sha256
	 * certificate fingerprint, scheme identifier), and the base64
	 * ciphertext fields. No decrypted value can ever be produced here —
	 * the server holds only ciphertext.
	 *
	 * @param Secret $secret The secret to serialize
	 *
	 * @return array<string,mixed> The envelope
	 *
	 * @spec openspec/changes/openconnector-secret-store-api/specs/secret-store-api/spec.md
	 */
	public function serialize(Secret $secret): array {
		return [
			'format' => self::FORMAT,
			'secret' => [
				'id' => $secret->getId(),
				'name' => $secret->getName(),
				'url' => $secret->getUrl(),
				'folderPath' => $this->resolveFolderPath(folderId: $secret->getFolderId()),
				'type' => $secret->getTypeId(),
				'createdAt' => $secret->getCreatedAt()?->format('c'),
				'updatedAt' => $secret->getUpdatedAt()?->format('c'),
				'keyUpdatedAt' => $secret->getKeyUpdatedAt()?->format('c'),
			],
			'encryption' => [
				'suiteId' => $secret->getEncryptionSuiteId(),
				'certificateFingerprint' => $this->certificateFingerprint(suiteId: $secret->getEncryptionSuiteId()),
				'scheme' => self::SCHEME,
			],
			'ciphertext' => [
				'key' => $secret->getKey(),
				'login' => $secret->getLogin(),
				'additionalFields' => $secret->getAdditionalFields(),
			],
		];
	}//end serialize()

	/**
	 * Build a candidate descriptor for the 409-ambiguity response body.
	 *
	 * Contains only non-sensitive metadata an operator needs to
	 * disambiguate (rename, or use a folder-scoped reference). No
	 * ciphertext, no encryption block.
	 *
	 * @param Secret $secret The secret to describe
	 *
	 * @return array<string,mixed>
	 *
	 * @spec openspec/changes/openconnector-secret-store-api/specs/secret-store-api/spec.md
	 */
	public function candidate(Secret $secret): array {
		return [
			'id' => $secret->getId(),
			'name' => $secret->getName(),
			'folderPath' => $this->resolveFolderPath(folderId: $secret->getFolderId()),
			'updatedAt' => $secret->getUpdatedAt()?->format('c'),
		];
	}//end candidate()

	/**
	 * Derive a strong ETag for a single secret.
	 *
	 * The tag changes whenever the secret's stored state changes — it is a
	 * hash over the id, the update timestamp, and a digest of the
	 * ciphertext fields. A no-op read yields a stable tag (304); any
	 * ciphertext or metadata-timestamp change yields a new tag.
	 *
	 * @param Secret $secret The secret
	 *
	 * @return string A quoted strong ETag value (e.g. `"<hex>"`)
	 *
	 * @spec openspec/changes/openconnector-secret-store-api/specs/secret-store-api/spec.md
	 */
	public function etag(Secret $secret): string {
		$material = implode(
			"\0",
			[
				$secret->getId(),
				$secret->getUpdatedAt()?->format('U.u') ?? '',
				hash('sha256', (string)$secret->getKey()),
				hash('sha256', (string)$secret->getLogin()),
				hash('sha256', (string)$secret->getAdditionalFields()),
			]
		);

		return '"' . hash('sha256', $material) . '"';
	}//end etag()

	/**
	 * Compute the sha256 fingerprint of the DER form of a suite's
	 * certificate, prefixed `sha256:`.
	 *
	 * Lets a consumer fail fast with a clear "wrong key" error (e.g. after
	 * re-registration) instead of a bare decrypt exception. Returns null
	 * when the suite or certificate cannot be resolved.
	 *
	 * @param string $suiteId The encryption-suite id
	 *
	 * @return string|null The `sha256:<hex>` fingerprint, or null
	 *
	 * @spec openspec/changes/openconnector-secret-store-api/specs/secret-store-api/spec.md
	 */
	public function certificateFingerprint(string $suiteId): ?string {
		if ($suiteId === '') {
			return null;
		}

		try {
			$suite = $this->suiteMapper->findById($suiteId);
		} catch (DoesNotExistException) {
			return null;
		}

		$pem = $suite->getCertificate();
		if ($pem === null || $pem === '') {
			return null;
		}

		$der = $this->pemToDer(pem: $pem);
		if ($der === null) {
			return null;
		}

		return 'sha256:' . hash('sha256', $der);
	}//end certificateFingerprint()

	/**
	 * Resolve a folder id to its slash-separated path, or '' for root.
	 *
	 * @param string|null $folderId The folder id (null = vault root)
	 *
	 * @return string The folder path, '' when at root
	 */
	private function resolveFolderPath(?string $folderId): string {
		if ($folderId === null || $folderId === '') {
			return '';
		}

		return $this->folderMapper->getPath($folderId);
	}//end resolveFolderPath()

	/**
	 * Decode a PEM certificate to its raw DER bytes.
	 *
	 * @param string $pem The PEM-encoded certificate
	 *
	 * @return string|null The DER bytes, or null when the PEM is malformed
	 */
	private function pemToDer(string $pem): ?string {
		if (preg_match(
			'/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s',
			$pem,
			$matches
		) !== 1
		) {
			return null;
		}

		$der = base64_decode(preg_replace('/\s+/', '', $matches[1]) ?? '', true);
		if ($der === false || $der === '') {
			return null;
		}

		return $der;
	}//end pemToDer()
}//end class
