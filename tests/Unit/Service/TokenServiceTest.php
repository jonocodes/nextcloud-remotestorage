<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Tests\Unit\Service;

use OCA\RemoteStorage\Db\Token;
use OCA\RemoteStorage\Db\TokenMapper;
use OCA\RemoteStorage\Service\Scope;
use OCA\RemoteStorage\Service\TokenService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TokenServiceTest extends TestCase {
	private TokenMapper&MockObject $mapper;
	private ITimeFactory&MockObject $time;
	private TokenService $service;

	protected function setUp(): void {
		$this->mapper = $this->createMock(TokenMapper::class);
		$this->time = $this->createMock(ITimeFactory::class);
		$this->time->method('getTime')->willReturn(1790990000);
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn(str_repeat('A', 43));
		$this->service = new TokenService($this->mapper, $random, $this->time);
	}

	public function testIssueStoresOnlyTheHash(): void {
		$stored = null;
		$this->mapper->expects($this->once())->method('insert')
			->willReturnCallback(function (Token $token) use (&$stored) {
				$stored = $token;
				return $token;
			});
		$plain = $this->service->issue('alice', 'https://app.example', Scope::parse('notes:rw'));

		$this->assertSame('rs_' . str_repeat('A', 43), $plain);
		$this->assertSame(hash('sha256', $plain), $stored->getTokenHash());
		$this->assertSame('alice', $stored->getUserId());
		$this->assertSame('https://app.example', $stored->getClientId());
		$this->assertSame('notes:rw', $stored->getScope());
		$this->assertSame(1790990000, $stored->getCreatedAt());
	}

	public function testOwnsOnlyPrefixedTokens(): void {
		$this->assertTrue(TokenService::isAppToken('rs_' . str_repeat('a', 43)));
		$this->assertFalse(TokenService::isAppToken('abc'));
		$this->assertFalse(TokenService::isAppToken('rs_short'));
		$this->assertFalse(TokenService::isAppToken('rs_' . str_repeat('a', 42) . '!'));
	}

	public function testVerifyIgnoresForeignTokensWithoutTouchingTheDatabase(): void {
		$this->mapper->expects($this->never())->method('findByHash');
		$this->assertNull($this->service->verify('some-core-oauth-token'));
	}

	public function testVerifyLooksUpByHash(): void {
		$plain = 'rs_' . str_repeat('b', 43);
		$token = new Token();
		$this->mapper->expects($this->once())->method('findByHash')->with(hash('sha256', $plain))->willReturn($token);
		$this->assertSame($token, $this->service->verify($plain));
	}

	public function testVerifyUnknownToken(): void {
		$this->mapper->method('findByHash')->willThrowException(new DoesNotExistException('no'));
		$this->assertNull($this->service->verify('rs_' . str_repeat('c', 43)));
	}

	public function testTouchUpdatesLastUsedAtMostEveryFiveMinutes(): void {
		$fresh = new Token();
		$fresh->setLastUsedAt(1790990000 - 60);
		$stale = new Token();
		$stale->setLastUsedAt(1790990000 - 600);
		$this->mapper->expects($this->once())->method('update')->with($stale);
		$this->service->touch($fresh);
		$this->service->touch($stale);
		$this->assertSame(1790990000, $stale->getLastUsedAt());
	}

	public function testGroupedForMergesTokensOfTheSameApp(): void {
		$this->mapper->method('findAllForUser')->with('alice')->willReturn([
			$this->token('https://a.example', 'notes:rw', 100, 200),
			$this->token('https://a.example', 'contacts:r', 100, 300),
			$this->token('https://b.example', 'notes:r', 150, 160),
		]);

		$apps = $this->service->groupedFor('alice');

		// Newest connection first.
		$this->assertSame(['https://b.example', 'https://a.example'], array_column($apps, 'clientId'));
		$this->assertSame(1, $apps[0]['count']);
		$this->assertSame(2, $apps[1]['count']);
		$this->assertSame(['contacts:r', 'notes:rw'], $apps[1]['scopes']);
		$this->assertSame(100, $apps[1]['createdAt']);
		$this->assertSame(300, $apps[1]['lastUsedAt']);
	}

	public function testGroupedForListsEachScopeOnce(): void {
		$this->mapper->method('findAllForUser')->willReturn([
			$this->token('https://a.example', 'notes:rw', 100, 100),
			$this->token('https://a.example', 'notes:rw', 110, 120),
		]);

		$apps = $this->service->groupedFor('alice');

		$this->assertSame(['notes:rw'], $apps[0]['scopes']);
		$this->assertSame(2, $apps[0]['count']);
		$this->assertSame(100, $apps[0]['createdAt']);
		$this->assertSame(120, $apps[0]['lastUsedAt']);
	}

	public function testGroupedForWithoutTokens(): void {
		$this->mapper->method('findAllForUser')->willReturn([]);
		$this->assertSame([], $this->service->groupedFor('alice'));
	}

	public function testRevokeClientRemovesEveryTokenForThatApp(): void {
		$this->mapper->expects($this->once())->method('deleteForUserAndClient')
			->with('alice', 'https://a.example')->willReturn(2);
		$this->assertSame(2, $this->service->revokeClient('alice', 'https://a.example'));
	}

	private function token(string $clientId, string $scope, int $createdAt, int $lastUsedAt): Token {
		$token = new Token();
		$token->setClientId($clientId);
		$token->setScope($scope);
		$token->setCreatedAt($createdAt);
		$token->setLastUsedAt($lastUsedAt);
		return $token;
	}
}
