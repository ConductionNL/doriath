<?php

/**
 * Keepiq Honey Alert Entity
 *
 * One raised tripwire alert (honey-credentials §1): who accessed a
 * decoy secret, over which channel, from where. Carries NO secret
 * material — accessor/channel/transport metadata only.
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
 * Entity representing one honey tripwire alert.
 *
 * @method string getHoneyFlagId()
 * @method void setHoneyFlagId(string $honeyFlagId)
 * @method string getSecretId()
 * @method void setSecretId(string $secretId)
 * @method string getAccessorType()
 * @method void setAccessorType(string $accessorType)
 * @method string|null getAccessorId()
 * @method void setAccessorId(?string $accessorId)
 * @method string getChannel()
 * @method void setChannel(string $channel)
 * @method string|null getIp()
 * @method void setIp(?string $ip)
 * @method string|null getUserAgent()
 * @method void setUserAgent(?string $userAgent)
 * @method int getAccessCount()
 * @method void setAccessCount(int $accessCount)
 * @method DateTime|null getAccessedAt()
 * @method void setAccessedAt(DateTime $accessedAt)
 * @method DateTime|null getAcknowledgedAt()
 * @method void setAcknowledgedAt(?DateTime $acknowledgedAt)
 * @method string|null getAcknowledgedBy()
 * @method void setAcknowledgedBy(?string $acknowledgedBy)
 * @method DateTime|null getSnoozedUntil()
 * @method void setSnoozedUntil(?DateTime $snoozedUntil)
 *
 * @SuppressWarnings(PHPMD.ShortVariable) The `$ip` property name IS the persistence
 *   contract: QBMapper derives the column name from the property, and the column
 *   shipped as `ip` in Migration\Version001000Date20260908000000. Renaming it would
 *   silently retarget every read and write at a non-existent `ip_address` column, so
 *   the short name is load-bearing. It is the only sub-3-character name in this class.
 */
class HoneyAlert extends Entity implements JsonSerializable {

	/**
	 * The tripped flag.
	 *
	 * @var string
	 */
	protected string $honeyFlagId = '';

	/**
	 * Denormalized decoy secret id (survives unflagging).
	 *
	 * @var string
	 */
	protected string $secretId = '';

	/**
	 * One of: user | application | link_visitor | system.
	 *
	 * @var string
	 */
	protected string $accessorType = '';

	/**
	 * Accessor id; null for anonymous link visitors.
	 *
	 * @var string|null
	 */
	protected ?string $accessorId = null;

	/**
	 * One of: ui | machine_api | link | share.
	 *
	 * @var string
	 */
	protected string $channel = '';

	/**
	 * Remote address when available. Name is the column name — see the
	 * class docblock.
	 *
	 * @var string|null
	 */
	protected ?string $ip = null;

	/**
	 * User agent when available.
	 *
	 * @var string|null
	 */
	protected ?string $userAgent = null;

	/**
	 * Accesses collapsed into this alert (dedup window).
	 *
	 * @var integer
	 */
	protected int $accessCount = 1;

	/**
	 * Last access instant.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $accessedAt = null;

	/**
	 * When the alert was acknowledged.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $acknowledgedAt = null;

	/**
	 * Who acknowledged it.
	 *
	 * @var string|null
	 */
	protected ?string $acknowledgedBy = null;

	/**
	 * Per-accessor paging suppression watermark.
	 *
	 * @var DateTime|null
	 */
	protected ?DateTime $snoozedUntil = null;

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
		$this->addType(fieldName: 'honeyFlagId', type: 'string');
		$this->addType(fieldName: 'secretId', type: 'string');
		$this->addType(fieldName: 'accessorType', type: 'string');
		$this->addType(fieldName: 'accessorId', type: 'string');
		$this->addType(fieldName: 'channel', type: 'string');
		$this->addType(fieldName: 'ip', type: 'string');
		$this->addType(fieldName: 'userAgent', type: 'string');
		$this->addType(fieldName: 'accessCount', type: 'integer');
		$this->addType(fieldName: 'accessedAt', type: 'datetime');
		$this->addType(fieldName: 'acknowledgedAt', type: 'datetime');
		$this->addType(fieldName: 'acknowledgedBy', type: 'string');
		$this->addType(fieldName: 'snoozedUntil', type: 'datetime');
	}//end __construct()

	/**
	 * Serialize for owner/admin API responses.
	 *
	 * @return array<string,mixed>
	 */
	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'honeyFlagId' => $this->honeyFlagId,
			'secretId' => $this->secretId,
			'accessorType' => $this->accessorType,
			'accessorId' => $this->accessorId,
			'channel' => $this->channel,
			'ip' => $this->ip,
			'userAgent' => $this->userAgent,
			'accessCount' => $this->accessCount,
			'accessedAt' => $this->accessedAt?->format('c'),
			'acknowledgedAt' => $this->acknowledgedAt?->format('c'),
			'acknowledgedBy' => $this->acknowledgedBy,
			'snoozedUntil' => $this->snoozedUntil?->format('c'),
		];
	}//end jsonSerialize()
}//end class
