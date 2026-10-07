<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Service;

/**
 * RFC 7636 PKCE, S256 only: the client sends
 * code_challenge = BASE64URL(SHA256(code_verifier)) and later proves it by
 * sending the verifier.
 */
final class Pkce {
	public static function challenge(string $verifier): string {
		return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
	}

	public static function verify(string $verifier, string $challenge): bool {
		return hash_equals($challenge, self::challenge($verifier));
	}
}
