<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getClientId()
 * @method void setClientId(string $clientId)
 * @method string getScope()
 * @method void setScope(string $scope)
 * @method string getTokenHash()
 * @method void setTokenHash(string $tokenHash)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getLastUsedAt()
 * @method void setLastUsedAt(int $lastUsedAt)
 */
class Token extends Entity {
	protected $userId;
	protected $clientId;
	protected $scope;
	protected $tokenHash;
	protected $createdAt;
	protected $lastUsedAt;

	public function __construct() {
		$this->addType('createdAt', 'integer');
		$this->addType('lastUsedAt', 'integer');
	}
}
