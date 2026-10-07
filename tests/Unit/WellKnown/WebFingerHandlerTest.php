<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Tests\Unit\WellKnown;

use OCA\RemoteStorage\Service\StoragePaths;
use OCA\RemoteStorage\WellKnown\CorsJrdResponse;
use OCA\RemoteStorage\WellKnown\WebFingerHandler;
use OCP\Http\WellKnown\IRequestContext;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class WebFingerHandlerTest extends TestCase {
	private function handle(): array {
		$user = $this->createMock(IUser::class);
		$user->method('isEnabled')->willReturn(true);
		$user->method('getUID')->willReturn('alice');
		$users = $this->createMock(IUserManager::class);
		$users->method('get')->with('alice')->willReturn($user);

		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://cloud.example' . $path);
		$urls->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route): string => match ($route) {
				'remotestorage.oauth.authorize' => 'https://cloud.example/apps/remotestorage/oauth',
				'remotestorage.oauth.token' => 'https://cloud.example/apps/remotestorage/oauth/token',
				default => 'https://cloud.example/unknown',
			}
		);

		$http = $this->createMock(IRequest::class);
		$http->method('getParam')->with('resource', '')->willReturn('acct:alice@cloud.example');
		$http->method('getServerHost')->willReturn('cloud.example');
		$context = $this->createMock(IRequestContext::class);
		$context->method('getHttpRequest')->willReturn($http);

		$handler = new WebFingerHandler($users, $urls, new StoragePaths('remoteStorage'));
		$response = $handler->handle('webfinger', $context, null);

		$this->assertInstanceOf(CorsJrdResponse::class, $response);
		return $response->jrd->toHttpResponse()->getData();
	}

	/** @return array<string,string|null> */
	private function properties(array $data): array {
		foreach ($data['links'] as $link) {
			if ($link['rel'] === WebFingerHandler::REL) {
				return $link['properties'];
			}
		}
		$this->fail('no remoteStorage link in the WebFinger record');
	}

	public function testAdvertisesThePkceEndpoints(): void {
		$properties = $this->properties($this->handle());
		$this->assertSame('https://cloud.example/apps/remotestorage/oauth', $properties['http://tools.ietf.org/html/rfc6749#section-3.1']);
		$this->assertSame('https://cloud.example/apps/remotestorage/oauth/token', $properties['http://tools.ietf.org/html/rfc6749#section-3.2']);
		$this->assertSame('S256', $properties['http://tools.ietf.org/html/rfc7636']);
	}

	public function testStillAdvertisesTheImplicitDialogAndStorage(): void {
		$data = $this->handle();
		$properties = $this->properties($data);
		$this->assertSame('https://cloud.example/apps/remotestorage/oauth', $properties['http://tools.ietf.org/html/rfc6749#section-4.2']);
		foreach ($data['links'] as $link) {
			if ($link['rel'] === WebFingerHandler::REL) {
				$this->assertSame('https://cloud.example/remote.php/dav/files/alice/remoteStorage', $link['href']);
				return;
			}
		}
		$this->fail('no remoteStorage link in the WebFinger record');
	}
}
