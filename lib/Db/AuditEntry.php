<?php

/**
 * Keepiq Audit Entry Entity
 *
 * One append-only row in the keepiq_audit_log table: a single
 * server-observable secret operation recorded with its actor, event type,
 * object reference, denormalized non-sensitive object name, and a whitelisted
 * metadata payload (add-secret-audit-trail §1.2). The metadata column is
 * stored as a JSON string and exposed as a decoded array via getMetadataArray.
 *
 * @category Db
 * @package  OCA\Keepiq\Db
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

namespace OCA\Keepiq\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Entity representing one append-only audit-log row.
 *
 * @method DateTime getOccurredAt()
 * @method void setOccurredAt(DateTime $occurredAt)
 * @method string getActorType()
 * @method void setActorType(string $actorType)
 * @method string|null getActorId()
 * @method void setActorId(?string $actorId)
 * @method string getEventType()
 * @method void setEventType(string $eventType)
 * @method string getObjectType()
 * @method void setObjectType(string $objectType)
 * @method string|null getObjectId()
 * @method void setObjectId(?string $objectId)
 * @method string|null getObjectName()
 * @method void setObjectName(?string $objectName)
 * @method string|null getMetadata()
 * @method void setMetadata(?string $metadata)
 */
class AuditEntry extends Entity implements JsonSerializable {

	/**
	 * When the audited operation occurred.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $occurredAt = null;

	/**
	 * The actor type: user | application | system | link_visitor.
	 *
	 * @var string
	 */
	protected string $actorType = '';

	/**
	 * The actor's Nextcloud user ID or application ID (null for system/link_visitor).
	 *
	 * @var string|null
	 */
	protected ?string $actorId = null;

	/**
	 * The dot-namespaced event type, e.g. secret.read.
	 *
	 * @var string
	 */
	protected string $eventType = '';

	/**
	 * The object type: secret | folder | share | link_share | secret_request | suite | application | vault.
	 *
	 * @var string
	 */
	protected string $objectType = '';

	/**
	 * The object reference ID.
	 *
	 * @var string|null
	 */
	protected ?string $objectId = null;

	/**
	 * The denormalized non-sensitive object name (survives object deletion).
	 *
	 * @var string|null
	 */
	protected ?string $objectName = null;

	/**
	 * The whitelisted metadata payload as a JSON string.
	 *
	 * @var string|null
	 */
	protected ?string $metadata = null;

	/**
	 * Constructor for AuditEntry.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->addType(fieldName: 'occurredAt', type: 'datetime');
		$this->addType(fieldName: 'actorType', type: 'string');
		$this->addType(fieldName: 'actorId', type: 'string');
		$this->addType(fieldName: 'eventType', type: 'string');
		$this->addType(fieldName: 'objectType', type: 'string');
		$this->addType(fieldName: 'objectId', type: 'string');
		$this->addType(fieldName: 'objectName', type: 'string');
		$this->addType(fieldName: 'metadata', type: 'string');
	}//end __construct()

	/**
	 * Decode the stored metadata JSON to an associative array.
	 *
	 * @return array<string,mixed>
	 */
	public function getMetadataArray(): array {
		if ($this->metadata === null || $this->metadata === '') {
			return [];
		}

		$decoded = json_decode($this->metadata, true);
		if (is_array($decoded) === false) {
			return [];
		}

		return $decoded;
	}//end getMetadataArray()

	/**
	 * Serialize the entity to an array for JSON output.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'occurredAt' => $this->occurredAt?->format('c'),
			'actorType' => $this->actorType,
			'actorId' => $this->actorId,
			'eventType' => $this->eventType,
			'objectType' => $this->objectType,
			'objectId' => $this->objectId,
			'objectName' => $this->objectName,
			'metadata' => $this->getMetadataArray(),
		];
	}//end jsonSerialize()
}//end class
