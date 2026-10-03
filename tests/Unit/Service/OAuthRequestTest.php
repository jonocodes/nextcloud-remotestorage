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

	public static function notRedirectable(): array {
		return [
			'missing redirect_uri' => [['redirect_uri' => null]],
			'relative redirect_uri' => [['redirect_uri' => '/index.html']],
			'javascript redirect_uri' => [['redirect_uri' => 'javascript:alert(1)']],
			'redirect_uri with fragment' => [['redirect_uri' => 'https://app.example/#x']],
			'redirect_uri with credentials' => [['redirect_uri' => 'https://user@app.example/']],
			'missing client_id' => [['client_id' => null]],
			'client_id not the redirect origin' => [['client_id' => 'https://evil.example']],
			'client_id on another port' => [['client_id' => 'https://app.example:444']],
		];
	}

	#[DataProvider('notRedirectable')]
	public function testErrorsThatMustNotRedirect(array $override): void {
		try {
			OAuthRequest::fromParams(self::params($override));
			$this->fail('expected OAuthError');
		} catch (OAuthError $e) {
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
