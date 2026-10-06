<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Service;

use OCA\RemoteStorage\Db\Token;
use OCA\RemoteStorage\Db\TokenMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ISecureRandom;

/**
 * Issues and checks the app's bearer tokens: "rs_" + 43 alphanumerics
 * (~256 bits). Only the SHA-256 is stored. The prefix lets the DAV auth
 * backend ignore other bearer tokens (core OAuth2, SSO apps) entirely.
 */
class TokenService {
	public const PREFIX = 'rs_';
	private const TOUCH_INTERVAL = 300;

	public function __construct(
		private TokenMapper $mapper,
		private ISecureRandom $random,
		private ITimeFactory $time,
	) {
	}

	public static function isAppToken(string $bearer): bool {
		return (bool)preg_match('/^rs_[A-Za-z0-9]{43}$/', $bearer);
	}

	public function issue(string $uid, string $clientId, Scope $scope): string {
		$plain = self::PREFIX . $this->random->generate(43, ISecureRandom::CHAR_ALPHANUMERIC);
		$token = new Token();
		$token->setUserId($uid);
		$token->setClientId($clientId);
		$token->setScope((string)$scope);
		$token->setTokenHash(hash('sha256', $plain));
		$token->setCreatedAt($this->time->getTime());
		$token->setLastUsedAt($this->time->getTime());
		$this->mapper->insert($token);
		return $plain;
	}

	public function verify(string $bearer): ?Token {
		if (!self::isAppToken($bearer)) {
			return null;
		}
		try {
			return $this->mapper->findByHash(hash('sha256', $bearer));
		} catch (DoesNotExistException) {
			return null;
		}
	}

	public function touch(Token $token): void {
		$now = $this->time->getTime();
		if ($now - (int)$token->getLastUsedAt() >= self::TOUCH_INTERVAL) {
			$token->setLastUsedAt($now);
			$this->mapper->update($token);
		}
	}

	/**
	 * The user's connected apps: one entry per client, so several tokens from
	 * the same app (a reconnect, a second device) read as one connection.
	 *
	 * @return list<array{clientId: string, scopes: list<string>, createdAt: int, lastUsedAt: int, count: int}>
	 */
	public function groupedFor(string $uid): array {
		$groups = [];
		foreach ($this->mapper->findAllForUser($uid) as $token) {
			$clientId = $token->getClientId();
			if (!isset($groups[$clientId])) {
				$groups[$clientId] = [
					'clientId' => $clientId,
					'scopes' => [],
					'createdAt' => $token->getCreatedAt(),
					'lastUsedAt' => $token->getLastUsedAt(),
					'count' => 0,
				];
			}
			$groups[$clientId]['scopes'][$token->getScope()] = true;
			$groups[$clientId]['createdAt'] = min($groups[$clientId]['createdAt'], $token->getCreatedAt());
			$groups[$clientId]['lastUsedAt'] = max($groups[$clientId]['lastUsedAt'], $token->getLastUsedAt());
			$groups[$clientId]['count']++;
		}
		$apps = [];
		foreach ($groups as $group) {
			$scopes = array_keys($group['scopes']);
			sort($scopes);
			$group['scopes'] = $scopes;
			$apps[] = $group;
		}
		usort($apps, static fn (array $a, array $b): int => [$b['createdAt'], $a['clientId']] <=> [$a['createdAt'], $b['clientId']]);
		return $apps;
	}

	/** @return list<Token> */
	public function listFor(string $uid): array {
		return $this->mapper->findAllForUser($uid);
	}

	/** Disconnect an app: revoke every token issued to it for this user. */
	public function revokeClient(string $uid, string $clientId): int {
		return $this->mapper->deleteForUserAndClient($uid, $clientId);
	}
}
