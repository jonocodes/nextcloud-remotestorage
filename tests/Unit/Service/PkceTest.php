<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Tests\Unit\Service;

use OCA\RemoteStorage\Service\Pkce;
use PHPUnit\Framework\TestCase;

class PkceTest extends TestCase {
	/** RFC 7636 appendix B known verifier and its S256 challenge. */
	private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
	private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

	public function testChallengeMatchesTheSpecVector(): void {
		$this->assertSame(self::CHALLENGE, Pkce::challenge(self::VERIFIER));
	}

	public function testVerifyAcceptsTheMatchingVerifier(): void {
		$this->assertTrue(Pkce::verify(self::VERIFIER, self::CHALLENGE));
	}

	public function testVerifyRejectsAWrongVerifierOrChallenge(): void {
		$this->assertFalse(Pkce::verify('not-the-verifier', self::CHALLENGE));
		$this->assertFalse(Pkce::verify(self::VERIFIER, 'not-the-challenge'));
	}
}
