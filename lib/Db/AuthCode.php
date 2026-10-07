<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A one-time authorization code for the PKCE code flow. Only its SHA-256 is
 * stored; the plain code is returned once and then discarded.
 *
 * @method string getCodeHash()
 * @method void setCodeHash(string $codeHash)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getClientId()
 * @method void setClientId(string $clientId)
 * @method string getRedirectUri()
 * @method void setRedirectUri(string $redirectUri)
 * @method string getScope()
 * @method void setScope(string $scope)
 * @method string getCodeChallenge()
 * @method void setCodeChallenge(string $codeChallenge)
 * @method string getCodeChallengeMethod()
 * @method void setCodeChallengeMethod(string $codeChallengeMethod)
 * @method int getCreatedAt()
 * @method void setCreatedAt(int $createdAt)
 * @method int getExpiresAt()
 * @method void setExpiresAt(int $expiresAt)
 */
class AuthCode extends Entity {
	protected $codeHash;
	protected $userId;
	protected $clientId;
	protected $redirectUri;
	protected $scope;
	protected $codeChallenge;
	protected $codeChallengeMethod;
	protected $createdAt;
	protected $expiresAt;

	public function __construct() {
		$this->addType('createdAt', 'integer');
		$this->addType('expiresAt', 'integer');
	}
}
