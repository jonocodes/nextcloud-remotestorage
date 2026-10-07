<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Service;

/**
 * CORS headers for the OAuth endpoints. The origin is echoed, never with
 * Access-Control-Allow-Credentials, so no cookies ever accompany these calls.
 */
final class Cors {
	/** @return array<string,string> */
	public static function headers(string $origin): array {
		return $origin === '' ? [] : [
			'Access-Control-Allow-Origin' => $origin,
			'Vary' => 'Origin',
		];
	}

	/** @return array<string,string> */
	public static function preflight(string $origin): array {
		return [
			'Access-Control-Allow-Origin' => $origin,
			'Access-Control-Allow-Methods' => 'POST, OPTIONS',
			'Access-Control-Allow-Headers' => 'Content-Type',
			'Access-Control-Max-Age' => '600',
			'Vary' => 'Origin',
		];
	}
}
