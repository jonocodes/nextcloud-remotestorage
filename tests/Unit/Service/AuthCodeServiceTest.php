<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Tests\Unit\Service;

use OCA\RemoteStorage\Db\AuthCode;
use OCA\RemoteStorage\Db\AuthCodeMapper;
use OCA\RemoteStorage\Service\AuthCodeService;
use OCA\RemoteStorage\Service\Scope;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

class AuthCodeServiceTest extends TestCase {
	/** sha256 of the fixture code below, an independent known value. */
	private const FIXTURE = 'codeplainvalue';
	private const FIXTURE_HASH = 'd8b9ecfdbc1208f36aea3d355561c29924a539bbe9f7b62c8b144be99cce58e7';

	public function testIssueStoresOnlyTheHashAndARecentExpiry(): void {
		$now = 1000000;
		$stored = null;
		$mapper = $this->createMock(AuthCodeMapper::class);
		$mapper->expects($this->once())->method('insert')
			->willReturnCallback(function (AuthCode $code) use (&$stored): AuthCode {
				$stored = $code;
				return $code;
			});
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn(self::FIXTURE);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now);

		$service = new AuthCodeService($mapper, $random, $time);
		$returned = $service->issue(
			'alice',
			'https://app.example',
			'https://app.example/callback',
			Scope::parse('notes:rw'),
			'S256-Challenge'
		);

		$this->assertSame(self::FIXTURE, $returned, 'the plain code is returned to the caller');
		$this->assertInstanceOf(AuthCode::class, $stored);
		$this->assertSame(self::FIXTURE_HASH, $stored->getCodeHash(), 'only the hash is stored');
		$this->assertSame('alice', $stored->getUserId());
		$this->assertSame('https://app.example', $stored->getClientId());
		$this->assertSame('https://app.example/callback', $stored->getRedirectUri());
		$this->assertSame('notes:rw', $stored->getScope());
		$this->assertSame('S256-Challenge', $stored->getCodeChallenge());
		$this->assertSame('S256', $stored->getCodeChallengeMethod());
		$this->assertSame($now, $stored->getCreatedAt());
		$this->assertSame($now + 600, $stored->getExpiresAt());
	}
}
