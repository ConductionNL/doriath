<?php

/**
 * Keepiq Honey Flag Entity
 *
 * Marks one secret as a decoy tripwire (honey-credentials §1). Lives in
 * a SIDE table — never a column on keepiq_secrets and never part of
 * the secret's serialization — so recipients/attackers cannot
 * distinguish a honey secret.
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
 * Entity representing a honey (decoy) marker on a secret.
 *
 * @method string getSecretId()
 * @method void setSecretId(string $secretId)
 * @method string getOwnerId()
 * @method void setOwnerId(string $ownerId)
 * @method string|null getNote()
 * @method void setNote(?string $note)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 */
class HoneyFlag extends Entity implements JsonSerializable {

	/**
	 * The flagged decoy secret.
	 *
	 * @var string
	 */
	protected string $secretId = '';

	/**
	 * The secret owner (NC user id).
	 *
	 * @var string
	 */
	protected string $ownerId = '';

	/**
	 * Placement note (owner/admin only).
	 *
	 * @var string|null
	 */
	protected ?string $note = null;

	/**
	 * Who flagged it (owner or admin).
	 *
	 * @var string
	 */
	protected string $createdBy = '';

	/**
	 * When it was flagged.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $createdAt = null;

	/**
	 * The UUID primary key.
	 *
	 * @var string
	 */
	public $id = '';

	/**
	 * Get the UUID primary key.
	 *
	 * @return string
	 */
	public function getId(): string {
		return (string)$this->id;
	}//end getId()

	/**
	 * Set the UUID primary key.
	 *
	 * @param string $id The UUID
	 *
	 * @return void
	 */
	public function setId($id): void {
		$this->setter(name: 'id', args: [$id]);
	}//end setId()

	/**
	 * Constructor: declare column types for QBMapper hydration.
	 *
	 * @return void
	 */
	public function __construct() {
		$this->addType(fieldName: 'id', type: 'string');
		$this->addType(fieldName: 'secretId', type: 'string');
		$this->addType(fieldName: 'ownerId', type: 'string');
		$this->addType(fieldName: 'note', type: 'string');
		$this->addType(fieldName: 'createdBy', type: 'string');
		$this->addType(fieldName: 'createdAt', type: 'datetime');
	}//end __construct()

	/**
	 * Serialize for owner/admin API responses only — this shape is
	 * never merged into a secret response.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'secretId' => $this->secretId,
			'ownerId' => $this->ownerId,
			'note' => $this->note,
			'createdBy' => $this->createdBy,
			'createdAt' => $this->createdAt?->format('c'),
		];
	}//end jsonSerialize()
}//end class
