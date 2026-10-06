<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Tests\Unit\Dav;

use OCA\RemoteStorage\Dav\RequestState;
use OCA\RemoteStorage\Dav\TokenAuth;
use OCA\RemoteStorage\Service\StoragePaths;
use OCA\RemoteStorage\Service\TokenService;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\Response;

class TokenAuthTest extends TestCase {
	private TokenAuth $auth;

	protected function setUp(): void {
		$this->auth = new TokenAuth(
			$this->createMock(TokenService::class),
			new StoragePaths('remoteStorage'),
			new RequestState(),
			$this->createMock(IUserManager::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IThrottler::class),
			$this->createMock(IRequest::class),
		);
	}

	private function request(?string $authorization): RequestInterface {
		$request = $this->createMock(RequestInterface::class);
		$request->method('getHeader')->with('Authorization')->willReturn($authorization);
		return $request;
	}

	public function testChallengeAdvertisesBearerForARejectedToken(): void {
		$response = new Response();
		$this->auth->challenge($this->request('Bearer rs_' . str_repeat('a', 43)), $response);
		$challenge = (string)$response->getHeader('WWW-Authenticate');
		$this->assertStringContainsString('Bearer realm="remoteStorage"', $challenge);
		$this->assertStringContainsString('error="invalid_token"', $challenge);
	}

	public function testChallengeKeepsCoresChallenge(): void {
		$response = new Response();
		$response->setHeader('WWW-Authenticate', 'Basic realm="Nextcloud"');
		$this->auth->challenge($this->request('Bearer rs_' . str_repeat('a', 43)), $response);
		$challenge = (string)$response->getHeader('WWW-Authenticate');
		$this->assertStringContainsString('Basic realm="Nextcloud"', $challenge);
		$this->assertStringContainsString('Bearer realm="remoteStorage"', $challenge);
	}

	public function testChallengeLeavesBasicAuthToCore(): void {
		$response = new Response();
		$this->auth->challenge($this->request('Basic dXNlcjpwYXNz'), $response);
		$this->assertNull($response->getHeader('WWW-Authenticate'));
	}

	public function testChallengeLeavesAnonymousRequestsToCore(): void {
		$response = new Response();
		$this->auth->challenge($this->request(null), $response);
		$this->assertNull($response->getHeader('WWW-Authenticate'));
	}

	public function testChallengeLeavesForeignBearerTokensToCore(): void {
		$response = new Response();
		$this->auth->challenge($this->request('Bearer some-core-oauth-token'), $response);
		$this->assertNull($response->getHeader('WWW-Authenticate'));
	}

	public static function appTokenSchemes(): array {
		return [
			'canonical' => ['Bearer rs_'],
			'lowercase' => ['bearer rs_'],
			'mixed-case' => ['BeArEr rs_'],
		];
	}

	#[DataProvider('appTokenSchemes')]
	public function testCheckAuthenticatesAppTokensRegardlessOfSchemeCasing(string $scheme): void {
		// Reaching the token store (rather than "no remoteStorage credentials")
		// proves a non-canonical scheme is still parsed as Bearer.
		$result = $this->auth->check($this->request($scheme . str_repeat('a', 43)), new Response());
		$this->assertSame([false, 'unknown remoteStorage token'], $result);
	}

	#[DataProvider('appTokenSchemes')]
	public function testChallengeAdvertisesBearerRegardlessOfSchemeCasing(string $scheme): void {
		$response = new Response();
		$this->auth->challenge($this->request($scheme . str_repeat('a', 43)), $response);
		$this->assertStringContainsString('Bearer realm="remoteStorage"', (string)$response->getHeader('WWW-Authenticate'));
	}
}
