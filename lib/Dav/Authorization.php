<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Dav;

/**
 * Parses the Authorization header's scheme once, so authentication, the Bearer
 * challenge and the CORS decision all agree that "Bearer" is case-insensitive
 * (RFC 7235 §2.1) and malformed values are rejected the same way.
 */
final class Authorization {
	/**
	 * @return ?string the Bearer credential, or null when the scheme is not
	 *                 "Bearer" (in any casing). An empty string means a Bearer
	 *                 scheme was named without a credential.
	 */
	public static function bearer(string $header): ?string {
		if (preg_match('/^Bearer\s+(.*)$/i', ltrim($header), $matches) !== 1) {
			return null;
		}
		return $matches[1];
	}
}
