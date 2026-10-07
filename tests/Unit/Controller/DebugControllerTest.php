<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Tests\Unit\Controller;

use OCA\RemoteStorage\Controller\DebugController;
use OCA\RemoteStorage\Db\Token;
use OCA\RemoteStorage\Service\AccessPolicy;
use OCA\RemoteStorage\Service\StoragePaths;
use OCA\RemoteStorage\Service\TokenService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class DebugControllerTest extends TestCase {
	private DebugController $controller;
	private TokenService&MockObject $tokens;
	private IUserSession&MockObject $userSession;
	private IUserManager&MockObject $userManager;

	protected function setUp(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('0.2.0');
		$appManager->method('getAppInfo')->willReturn([
			'dependencies' => ['nextcloud' => ['@attributes' => ['min-version' => '34', 'max-version' => '35']]],
		]);
		$this->tokens = $this->createMock(TokenService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->controller = $this->createController($appManager);
	}

	private function createController(IAppManager $appManager): DebugController {
		return new DebugController($this->createMock(IRequest::class), new StoragePaths('rs'), $appManager,
			$this->tokens, $this->userSession, $this->userManager);
	}

	/** Explains a request for a path below alice's storage root. */
	private function explain(string $method, string $rel, string $scope = ''): array {
		$response = $this->controller->explain($method, '/remote.php/dav/files/alice/rs' . $rel, $scope);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		return $response->getData();
	}

	public function testInScopeReadIsAllowed(): void {
		$data = $this->explain('GET', '/notes/a.txt', 'notes:r');
		$this->assertSame(AccessPolicy::ALLOW, $data['decision']);
		$this->assertSame(
			['uid' => 'alice', 'rel' => '/notes/a.txt', 'module' => 'notes', 'folder' => false, 'public' => false],
			$data['match']
		);
		$this->assertSame(['notes' => 'r'], $data['scope']);
		$this->assertFalse($data['public_only']);
		$this->assertSame("'notes:r' covers this path", $data['reason']);
	}

	public static function decisions(): array {
		return [
			// method, rel, scope, expected decision, expected reason
			'in-scope write' => ['PUT', '/notes/a.txt', 'notes:rw', AccessPolicy::ALLOW, "'notes:rw' covers this path"],
			'read-only module refuses a write' => ['PUT', '/notes/a.txt', 'notes:r', AccessPolicy::FORBIDDEN,
				"'notes:r' allows reads only; writing needs 'notes:rw'"],
			'wildcard read-only refuses a write' => ['DELETE', '/x/y', '*:r', AccessPolicy::FORBIDDEN,
				"'*:r' allows reads only; writing needs '*:rw'"],
			'specific write survives a wildcard read' => ['PUT', '/notes/a.txt', '*:r notes:rw', AccessPolicy::ALLOW,
				"'notes:rw' covers this path"],
			'wildcard read covers another module' => ['GET', '/photos/a.jpg', '*:r notes:rw', AccessPolicy::ALLOW,
				"'*:r' covers this path"],
			'other module' => ['GET', '/photos/a.jpg', 'notes:rw', AccessPolicy::FORBIDDEN,
				"no scope item covers the module 'photos'"],
			'root needs wildcard' => ['GET', '/', 'notes:rw', AccessPolicy::FORBIDDEN,
				"only a '*' scope covers the storage root and /public/ themselves"],
			'root with wildcard' => ['GET', '/', '*:r', AccessPolicy::ALLOW, "'*:r' covers this path"],
			'write to a folder' => ['PUT', '/notes/', 'notes:rw', AccessPolicy::METHOD_NOT_ALLOWED,
				'PUT and DELETE apply to documents, not folders'],
			'unknown method' => ['PROPFIND', '/notes/', 'notes:rw', AccessPolicy::METHOD_NOT_ALLOWED,
				'PROPFIND is not a remoteStorage method (GET, HEAD, PUT, DELETE)'],
			'anonymous public document' => ['GET', '/public/notes/a.txt', '', AccessPolicy::ALLOW,
				'anonymous requests may read public documents'],
			'anonymous document at the root of /public' => ['GET', '/public/notes', '', AccessPolicy::ALLOW,
				'anonymous requests may read public documents'],
			'anonymous public listing' => ['GET', '/public/notes/', '', AccessPolicy::FORBIDDEN,
				'anonymous requests may not list folders'],
			'anonymous private document' => ['GET', '/notes/a.txt', '', AccessPolicy::FORBIDDEN,
				'without a token only documents under /public/ are readable'],
			'anonymous write' => ['PUT', '/public/notes/a.txt', '', AccessPolicy::FORBIDDEN,
				'anonymous requests may only read'],
		];
	}

	#[DataProvider('decisions')]
	public function testDecisionAndReason(string $method, string $rel, string $scope, string $decision, string $reason): void {
		$data = $this->explain($method, $rel, $scope);
		$this->assertSame($decision, $data['decision']);
		$this->assertSame($reason, $data['reason']);
	}

	public function testEmptyScopeMeansAnonymous(): void {
		$data = $this->explain('GET', '/public/notes/a.txt');
		$this->assertTrue($data['public_only']);
		$this->assertNull($data['scope']);
		$this->assertNull($data['input']['scope']);
	}

	public function testPathOutsideTheRootHasNoMatch(): void {
		$response = $this->controller->explain('GET', '/remote.php/dav/files/alice/other/a.txt', '*:rw');
		$data = $response->getData();
		$this->assertNull($data['match']);
		$this->assertSame(AccessPolicy::FORBIDDEN, $data['decision']);
		$this->assertSame("the path is not under the storage root of the token's user", $data['reason']);
		$this->assertSame('rs', $data['storage_root']);
	}

	public function testAcceptsAFullUrlAndNormalisesTheMethod(): void {
		$response = $this->controller->explain('get', 'https://cloud.example/remote.php/dav/files/alice/rs/notes/a.txt', 'notes:r');
		$data = $response->getData();
		$this->assertSame('GET', $data['input']['method']);
		$this->assertSame('/notes/a.txt', $data['match']['rel']);
		$this->assertSame(AccessPolicy::ALLOW, $data['decision']);
	}

	public function testMalformedScopeIsABadRequest(): void {
		$response = $this->controller->explain('GET', '/remote.php/dav/files/alice/rs/notes/a.txt', 'notes:x');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => "scope: invalid scope item 'notes:x'"], $response->getData());
	}

	public function testMissingPathIsABadRequest(): void {
		$response = $this->controller->explain('GET', '', 'notes:r');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'path is required'], $response->getData());
	}

	public function testConfigReflectsTheLiveStorageRoot(): void {
		$data = $this->controller->config()->getData();
		$this->assertSame('rs', $data['storage_root']);
		$this->assertSame('remoteStorage', $data['default_storage_root']);
		$this->assertSame('0.2.0', $data['version']);
		$this->assertSame('draft-dejong-remotestorage-22', $data['spec_version']);
		$this->assertSame('http://tools.ietf.org/id/draft-dejong-remotestorage', $data['webfinger_rel']);
		$this->assertSame(['GET', 'HEAD', 'PUT', 'DELETE', 'OPTIONS'], $data['cors']['allow_methods']);
		$this->assertContains('If-Match', $data['cors']['allow_headers']);
		$this->assertContains('ETag', $data['cors']['expose_headers']);
		$this->assertSame(['min_version' => '34', 'max_version' => '35'], $data['nextcloud']);
	}

	public function testConfigSurvivesMissingAppInfo(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('0.2.0');
		$appManager->method('getAppInfo')->willReturn(null);
		$controller = $this->createController($appManager);
		$this->assertSame(['min_version' => null, 'max_version' => null], $controller->config()->getData()['nextcloud']);
	}

	/** The endpoints are admin-only through the framework default: no attribute may relax that. */
	public function testEndpointAuthorizationAttributes(): void {
		foreach ((new ReflectionClass(DebugController::class))->getMethods() as $method) {
			if ($method->getDeclaringClass()->getName() !== DebugController::class || !$method->isPublic()) {
				continue;
			}
			$this->assertSame([], $method->getAttributes(PublicPage::class), $method->getName());
			if ($method->getName() === 'tokensMine') {
				$this->assertNotEmpty($method->getAttributes(NoAdminRequired::class), 'tokensMine must allow non-admin callers');
			} else {
				$this->assertSame([], $method->getAttributes(NoAdminRequired::class), $method->getName() . ' must stay admin-only');
			}
		}
	}

	public function testTokensMineReturnsRedactedTokensForCurrentUser(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$this->userSession->method('getUser')->willReturn($user);

		$token = $this->fakeToken(42, 'alice', 'https://app.example', 'notes:rw', 'secret_sha256_hash', 1000, 2000);
		$this->tokens->expects($this->once())->method('listFor')->with('alice')->willReturn([$token]);

		$response = $this->controller->tokensMine();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame([['id' => 42, 'clientId' => 'https://app.example', 'scope' => 'notes:rw', 'createdAt' => 1000, 'lastUsedAt' => 2000]], $data);
		$this->assertArrayNotHasKey('token_hash', $data[0]);
		$this->assertArrayNotHasKey('tokenHash', $data[0]);
		$this->assertArrayNotHasKey('userId', $data[0]);
		$this->assertSame(['id', 'clientId', 'scope', 'createdAt', 'lastUsedAt'], array_keys($data[0]));
	}

	public function testTokensMineWhenNotLoggedInReturnsUnauthorized(): void {
		$this->userSession->method('getUser')->willReturn(null);
		$response = $this->controller->tokensMine();
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
		$this->assertSame(['error' => 'not logged in'], $response->getData());
	}

	public function testTokensReturnsRedactedTokensForTargetUser(): void {
		$user = $this->createMock(IUser::class);
		$this->userManager->method('get')->with('bob')->willReturn($user);

		$token = $this->fakeToken(99, 'bob', 'https://bob.example', 'contacts:r', 'secret_hash', 500, 600);
		$this->tokens->expects($this->once())->method('listFor')->with('bob')->willReturn([$token]);

		$response = $this->controller->tokens('bob');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame([['id' => 99, 'clientId' => 'https://bob.example', 'scope' => 'contacts:r', 'createdAt' => 500, 'lastUsedAt' => 600]], $data);
		$this->assertArrayNotHasKey('token_hash', $data[0]);
	}

	public function testTokensUnknownUserReturnsNotFound(): void {
		$this->userManager->method('get')->with('ghost')->willReturn(null);
		$response = $this->controller->tokens('ghost');
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['error' => 'unknown user'], $response->getData());
	}

	public function testTokensMissingUserIsABadRequest(): void {
		$response = $this->controller->tokens('');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'user is required'], $response->getData());
	}

	private function fakeToken(int $id, string $userId, string $clientId, string $scope, string $hash, int $createdAt, int $lastUsedAt): Token {
		$token = new Token();
		$token->setId($id);
		$token->setUserId($userId);
		$token->setClientId($clientId);
		$token->setScope($scope);
		$token->setTokenHash($hash);
		$token->setCreatedAt($createdAt);
		$token->setLastUsedAt($lastUsedAt);
		return $token;
	}
}
