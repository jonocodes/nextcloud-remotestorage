<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Service;

use InvalidArgumentException;

/**
 * A validated remoteStorage OAuth request, either the implicit grant
 * (RFC 6749 §4.2) or the authorization code grant with PKCE (RFC 7636),
 * chosen by response_type.
 */
final class OAuthRequest {
	public const IMPLICIT = 'token';
	public const CODE = 'code';
	private const MAX_CLIENT_ID_BYTES = 255;
	/** S256 code challenge: base64url(sha256(verifier)), uncoded, 43 characters. */
	private const S256_CHALLENGE = '/^[A-Za-z0-9_-]{43}$/';

	private function __construct(
		public readonly string $responseType,
		public readonly string $clientId,
		public readonly string $redirectUri,
		public readonly Scope $scope,
		public readonly ?string $state,
		public readonly ?string $codeChallenge,
		public readonly ?string $codeChallengeMethod,
	) {
	}

	/**
	 * @param array<string,mixed> $params
	 * @throws OAuthError
	 */
	public static function fromParams(array $params): self {
		$redirectUri = self::string($params, 'redirect_uri');
		$clientId = self::string($params, 'client_id');
		$state = self::string($params, 'state');

		// Until redirect_uri and client_id check out, never redirect anywhere.
		$origin = $redirectUri === null ? null : self::originOf($redirectUri);
		if ($origin === null) {
			throw new OAuthError('invalid_redirect_uri');
		}
		if ($clientId === null || strlen($clientId) > self::MAX_CLIENT_ID_BYTES || $clientId !== $origin) {
			throw new OAuthError('invalid_client');
		}

		$responseType = self::string($params, 'response_type') ?? '';
		$isCode = $responseType === self::CODE;
		$back = static fn (string $code): string => self::backUrl(
			$redirectUri,
			$isCode,
			array_filter(['error' => $code, 'state' => $state], static fn (?string $v): bool => $v !== null)
		);
		if (!in_array($responseType, [self::IMPLICIT, self::CODE], true)) {
			throw new OAuthError('unsupported_response_type', $back('unsupported_response_type'));
		}
		try {
			$scope = Scope::parse((string)self::string($params, 'scope'));
		} catch (InvalidArgumentException) {
			throw new OAuthError('invalid_scope', $back('invalid_scope'));
		}

		$challenge = null;
		$method = null;
		if ($isCode) {
			$challenge = self::string($params, 'code_challenge');
			$method = self::string($params, 'code_challenge_method');
			// We support S256 only (spec §10.1): reject a missing challenge,
			// the "plain" method, and anything not shaped like an S256 digest.
			if ($method !== 'S256' || $challenge === null || preg_match(self::S256_CHALLENGE, $challenge) !== 1) {
				throw new OAuthError('invalid_request', $back('invalid_request'));
			}
		}
		return new self($responseType, $clientId, $redirectUri, $scope, $state, $challenge, $method);
	}

	public function origin(): string {
		return $this->clientId;
	}

	public function isCodeFlow(): bool {
		return $this->responseType === self::CODE;
	}

	/** Implicit grant: the token goes in the fragment (RFC 6749 §4.2.2). */
	public function successRedirect(string $token): string {
		return self::fragmentUrl($this->redirectUri, array_filter([
			'access_token' => $token,
			'token_type' => 'bearer',
			'scope' => (string)$this->scope,
			'state' => $this->state,
		], static fn (?string $v): bool => $v !== null));
	}

	/** Code grant: the code goes in the query (RFC 6749 §4.1.2). */
	public function codeRedirect(string $code): string {
		return self::queryUrl($this->redirectUri, array_filter([
			'code' => $code,
			'state' => $this->state,
		], static fn (?string $v): bool => $v !== null));
	}

	public function errorRedirect(string $code): string {
		return self::backUrl($this->redirectUri, $this->isCodeFlow(), array_filter(
			['error' => $code, 'state' => $this->state],
			static fn (?string $v): bool => $v !== null
		));
	}

	/** @param array<string,string> $params */
	private static function backUrl(string $redirectUri, bool $codeFlow, array $params): string {
		return $codeFlow ? self::queryUrl($redirectUri, $params) : self::fragmentUrl($redirectUri, $params);
	}

	/** Origin of an absolute http(s) URL without fragment or credentials, else null. */
	private static function originOf(string $url): ?string {
		$parts = parse_url($url);
		if ($parts === false || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
			|| empty($parts['host']) || isset($parts['fragment']) || isset($parts['user']) || isset($parts['pass'])) {
			return null;
		}
		if ($parts['scheme'] === 'http' && !self::isLoopbackHost($parts['host'])) {
			return null;
		}
		return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
	}

	private static function isLoopbackHost(string $host): bool {
		if (strtolower($host) === 'localhost') {
			return true;
		}
		$literal = str_starts_with($host, '[') && str_ends_with($host, ']') ? substr($host, 1, -1) : $host;
		$address = filter_var($literal, FILTER_VALIDATE_IP);
		if (!is_string($address)) {
			return false;
		}
		$packed = inet_pton($address);
		return $packed === inet_pton('::1') || (is_string($packed) && strlen($packed) === 4 && ord($packed[0]) === 127);
	}

	/** @param array<string,string> $params */
	private static function fragmentUrl(string $url, array $params): string {
		return $url . '#' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
	}

	/** @param array<string,string> $params */
	private static function queryUrl(string $url, array $params): string {
		return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
	}

	private static function string(array $params, string $key): ?string {
		$value = $params[$key] ?? null;
		return is_string($value) && $value !== '' ? $value : null;
	}
}
