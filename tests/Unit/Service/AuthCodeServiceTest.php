<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Tests\Unit\Service;

use OCA\RemoteStorage\Db\AuthCode;
use OCA\RemoteStorage\Db\AuthCodeMapper;
use OCA\RemoteStorage\Service\AuthCodeService;
use OCA\RemoteStorage\Service\Scope;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AuthCodeServiceTest extends TestCase {
	/** sha256 of the fixture code below, an independent known value. */
	private const FIXTURE = 'codeplainvalue';
	private const FIXTURE_HASH = 'd8b9ecfdbc1208f36aea3d355561c29924a539bbe9f7b62c8b144be99cce58e7';
	/** RFC 7636 appendix B verifier and its challenge. */
	private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
	private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

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

	private function storedCode(int $expiresAt = 1000600): AuthCode {
		$code = new AuthCode();
		$code->setCodeHash(hash('sha256', self::FIXTURE));
		$code->setUserId('alice');
		$code->setClientId('https://app.example');
		$code->setRedirectUri('https://app.example/callback');
		$code->setScope('notes:rw');
		$code->setCodeChallenge(self::CHALLENGE);
		$code->setCodeChallengeMethod('S256');
		$code->setCreatedAt(1000000);
		$code->setExpiresAt($expiresAt);
		return $code;
	}

	private function service(AuthCodeMapper $mapper, int $now = 1000000): AuthCodeService {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn($now);
		return new AuthCodeService($mapper, $this->createMock(ISecureRandom::class), $time);
	}

	public function testRedeemReturnsTheCodeAndConsumesIt(): void {
		$code = $this->storedCode();
		$mapper = $this->createMock(AuthCodeMapper::class);
		$mapper->method('findByHash')->with(self::FIXTURE_HASH)->willReturn($code);
		$mapper->expects($this->once())->method('deleteByHash')->with(self::FIXTURE_HASH)->willReturn(1);

		$result = $this->service($mapper)->redeem(
			self::FIXTURE,
			'https://app.example',
			'https://app.example/callback',
			self::VERIFIER
		);

		$this->assertSame($code, $result);
	}

	public function testRedeemRejectsAnUnknownCode(): void {
		$mapper = $this->createMock(AuthCodeMapper::class);
		$mapper->method('findByHash')->willThrowException(new DoesNotExistException('miss'));
		$mapper->expects($this->never())->method('deleteByHash');

		$this->assertNull($this->service($mapper)->redeem(self::FIXTURE, 'https://app.example', 'https://app.example/callback', self::VERIFIER));
	}

	public function testRedeemRejectsAnExpiredCode(): void {
		$mapper = $this->createMock(AuthCodeMapper::class);
		$mapper->method('findByHash')->willReturn($this->storedCode(999999));
		$mapper->expects($this->never())->method('deleteByHash');

		$this->assertNull($this->service($mapper)->redeem(self::FIXTURE, 'https://app.example', 'https://app.example/callback', self::VERIFIER));
	}

	public function testRedeemRejectsACodeExactlyAtItsExpiryInstant(): void {
		$mapper = $this->createMock(AuthCodeMapper::class);
		$mapper->method('findByHash')->willReturn($this->storedCode(1000000));
		$mapper->expects($this->never())->method('deleteByHash');

		$this->assertNull($this->service($mapper)->redeem(self::FIXTURE, 'https://app.example', 'https://app.example/callback', self::VERIFIER));
	}

	public static function mismatches(): array {
		return [
			'wrong verifier' => ['https://app.example', 'https://app.example/callback', 'wrong-verifier'],
			'wrong client' => ['https://evil.example', 'https://app.example/callback', self::VERIFIER],
			'wrong redirect' => ['https://app.example', 'https://app.example/other', self::VERIFIER],
		];
	}

	#[DataProvider('mismatches')]
	public function testRedeemRejectsABadVerifierClientOrRedirect(string $client, string $redirect, string $verifier): void {
		$mapper = $this->createMock(AuthCodeMapper::class);
		$mapper->method('findByHash')->willReturn($this->storedCode());
		$mapper->expects($this->never())->method('deleteByHash');

		$this->assertNull($this->service($mapper)->redeem(self::FIXTURE, $client, $redirect, $verifier));
	}

	public function testRedeemRejectsALostRaceForTheSameCode(): void {
		$mapper = $this->createMock(AuthCodeMapper::class);
		$mapper->method('findByHash')->willReturn($this->storedCode());
		$mapper->method('deleteByHash')->willReturn(0);

		$this->assertNull($this->service($mapper)->redeem(self::FIXTURE, 'https://app.example', 'https://app.example/callback', self::VERIFIER));
	}
}
