<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Tests\Unit\Service;

use OCA\RemoteStorage\Service\OAuthError;
use OCA\RemoteStorage\Service\OAuthRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OAuthRequestTest extends TestCase {
	private static function params(array $override = []): array {
		return array_merge([
			'client_id' => 'https://app.example',
			'redirect_uri' => 'https://app.example/index.html',
			'scope' => 'notes:rw',
			'response_type' => 'token',
			'state' => 'xyz',
		], $override);
	}

	public function testValidRequest(): void {
		$request = OAuthRequest::fromParams(self::params());
		$this->assertSame('https://app.example', $request->clientId);
		$this->assertSame('https://app.example', $request->origin());
		$this->assertSame('notes:rw', (string)$request->scope);
	}

	public function testSuccessRedirectPutsTheTokenInTheFragment(): void {
		$request = OAuthRequest::fromParams(self::params());
		$this->assertSame(
			'https://app.example/index.html#access_token=rs_abc&token_type=bearer&scope=notes%3Arw&state=xyz',
			$request->successRedirect('rs_abc')
		);
	}

	public function testErrorRedirect(): void {
		$request = OAuthRequest::fromParams(self::params(['state' => null]));
		$this->assertSame('https://app.example/index.html#error=access_denied', $request->errorRedirect('access_denied'));
	}

	public function testOriginKeepsANonDefaultPort(): void {
		$request = OAuthRequest::fromParams(self::params([
			'client_id' => 'http://localhost:8081',
			'redirect_uri' => 'http://localhost:8081/',
		]));
		$this->assertSame('http://localhost:8081', $request->origin());
	}

	public static function loopbackHttpOrigins(): array {
		return [
			'localhost' => ['http://localhost'],
			'IPv4 loopback' => ['http://127.0.0.1:8081'],
			'IPv6 loopback' => ['http://[::1]:8081'],
			'expanded IPv6 loopback' => ['http://[0:0:0:0:0:0:0:1]:8081'],
		];
	}

	#[DataProvider('loopbackHttpOrigins')]
	public function testAcceptsHttpForLoopbackOrigins(string $origin): void {
		$request = OAuthRequest::fromParams(self::params([
			'client_id' => $origin,
			'redirect_uri' => $origin . '/',
		]));
		$this->assertSame($origin, $request->origin());
	}

	private static function longOrigin(int $finalLabelLength): string {
		return 'https://' . implode('.', [
			str_repeat('a', 63),
			str_repeat('b', 63),
			str_repeat('c', 63),
			str_repeat('d', $finalLabelLength),
		]);
	}

	public function testAcceptsClientIdAtDatabaseLimit(): void {
		$origin = self::longOrigin(55);
		$request = OAuthRequest::fromParams(self::params([
			'client_id' => $origin,
			'redirect_uri' => $origin . '/',
		]));
		$this->assertSame(255, strlen($request->clientId));
	}

	public static function notRedirectable(): array {
		$oversizedOrigin = self::longOrigin(56);
		return [
			'missing redirect_uri' => [['redirect_uri' => null], 'invalid_redirect_uri'],
			'relative redirect_uri' => [['redirect_uri' => '/index.html'], 'invalid_redirect_uri'],
			'javascript redirect_uri' => [['redirect_uri' => 'javascript:alert(1)'], 'invalid_redirect_uri'],
			'redirect_uri with fragment' => [['redirect_uri' => 'https://app.example/#x'], 'invalid_redirect_uri'],
			'redirect_uri with credentials' => [['redirect_uri' => 'https://user@app.example/'], 'invalid_redirect_uri'],
			'remote HTTP origin' => [['client_id' => 'http://app.example', 'redirect_uri' => 'http://app.example/'], 'invalid_redirect_uri'],
			'localhost suffix over HTTP' => [['client_id' => 'http://localhost.evil.example', 'redirect_uri' => 'http://localhost.evil.example/'], 'invalid_redirect_uri'],
			'non-loopback IPv4 over HTTP' => [['client_id' => 'http://192.0.2.1', 'redirect_uri' => 'http://192.0.2.1/'], 'invalid_redirect_uri'],
			'missing client_id' => [['client_id' => null], 'invalid_client'],
			'oversized client_id' => [['client_id' => $oversizedOrigin, 'redirect_uri' => $oversizedOrigin . '/'], 'invalid_client'],
			'client_id not the redirect origin' => [['client_id' => 'https://evil.example'], 'invalid_client'],
			'client_id on another port' => [['client_id' => 'https://app.example:444'], 'invalid_client'],
		];
	}

	#[DataProvider('notRedirectable')]
	public function testErrorsThatMustNotRedirect(array $override, string $code): void {
		try {
			OAuthRequest::fromParams(self::params($override));
			$this->fail('expected OAuthError');
		} catch (OAuthError $e) {
			$this->assertSame($code, $e->getMessage());
			$this->assertNull($e->redirect);
		}
	}

	public static function redirectable(): array {
		return [
			'code flow' => [['response_type' => 'code'], 'unsupported_response_type'],
			'missing response_type' => [['response_type' => null], 'unsupported_response_type'],
			'missing scope' => [['scope' => null], 'invalid_scope'],
			'bad scope' => [['scope' => 'notes'], 'invalid_scope'],
		];
	}

	#[DataProvider('redirectable')]
	public function testErrorsThatRedirectBack(array $override, string $code): void {
		try {
			OAuthRequest::fromParams(self::params($override));
			$this->fail('expected OAuthError');
		} catch (OAuthError $e) {
			$this->assertSame($code, $e->getMessage());
			$this->assertSame('https://app.example/index.html#error=' . $code . '&state=xyz', $e->redirect);
		}
	}
}
