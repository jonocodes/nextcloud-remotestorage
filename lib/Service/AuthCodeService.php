<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Service;

use OCA\RemoteStorage\Db\AuthCode;
use OCA\RemoteStorage\Db\AuthCodeMapper;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ISecureRandom;

/**
 * Issues the one-time authorization codes of the PKCE code flow. The plain
 * code leaves the server only in the redirect; only its SHA-256 is stored,
 * and it expires quickly.
 */
class AuthCodeService {
	private const TTL = 600;

	public function __construct(
		private AuthCodeMapper $mapper,
		private ISecureRandom $random,
		private ITimeFactory $time,
	) {
	}

	public function issue(string $uid, string $clientId, string $redirectUri, Scope $scope, string $challenge): string {
		$plain = $this->random->generate(43, ISecureRandom::CHAR_ALPHANUMERIC);
		$now = $this->time->getTime();
		$code = new AuthCode();
		$code->setCodeHash(hash('sha256', $plain));
		$code->setUserId($uid);
		$code->setClientId($clientId);
		$code->setRedirectUri($redirectUri);
		$code->setScope((string)$scope);
		$code->setCodeChallenge($challenge);
		$code->setCodeChallengeMethod('S256');
		$code->setCreatedAt($now);
		$code->setExpiresAt($now + self::TTL);
		$this->mapper->insert($code);
		return $plain;
	}
}
