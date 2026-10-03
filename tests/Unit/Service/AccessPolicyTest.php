<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Tests\Unit\Service;

use OCA\RemoteStorage\Service\AccessPolicy;
use OCA\RemoteStorage\Service\Scope;
use OCA\RemoteStorage\Service\StoragePaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AccessPolicyTest extends TestCase {
	private static function decide(string $method, string $rel, ?string $scope, bool $publicOnly = false): string {
		$m = (new StoragePaths('rs'))->match('/remote.php/dav/files/alice/rs' . $rel);
		return AccessPolicy::decide($method, $m, $scope === null ? null : Scope::parse($scope), $publicOnly);
	}

	public static function cases(): array {
		return [
			// method, rel, scope, publicOnly, expected
			'read own module' => ['GET', '/notes/a.txt', 'notes:r', false, AccessPolicy::ALLOW],
			'list own module' => ['GET', '/notes/', 'notes:r', false, AccessPolicy::ALLOW],
			'head' => ['HEAD', '/notes/a.txt', 'notes:r', false, AccessPolicy::ALLOW],
			'write needs rw' => ['PUT', '/notes/a.txt', 'notes:r', false, AccessPolicy::FORBIDDEN],
			'write with rw' => ['PUT', '/notes/a.txt', 'notes:rw', false, AccessPolicy::ALLOW],
			'delete with rw' => ['DELETE', '/notes/a.txt', 'notes:rw', false, AccessPolicy::ALLOW],
			'other module' => ['GET', '/photos/a.jpg', 'notes:rw', false, AccessPolicy::FORBIDDEN],
			'public of own module' => ['PUT', '/public/notes/a.txt', 'notes:rw', false, AccessPolicy::ALLOW],
			'public of other module' => ['GET', '/public/photos/a.jpg', 'notes:rw', false, AccessPolicy::FORBIDDEN],
			'root needs wildcard' => ['GET', '/', 'notes:rw', false, AccessPolicy::FORBIDDEN],
			'root with wildcard' => ['GET', '/', '*:r', false, AccessPolicy::ALLOW],
			'public root needs wildcard' => ['GET', '/public/', 'notes:rw', false, AccessPolicy::FORBIDDEN],
			'wildcard writes anywhere' => ['PUT', '/x/y.txt', '*:rw', false, AccessPolicy::ALLOW],
			'put to folder' => ['PUT', '/notes/', 'notes:rw', false, AccessPolicy::METHOD_NOT_ALLOWED],
			'delete folder' => ['DELETE', '/notes/', 'notes:rw', false, AccessPolicy::METHOD_NOT_ALLOWED],
			'propfind' => ['PROPFIND', '/notes/', 'notes:rw', false, AccessPolicy::METHOD_NOT_ALLOWED],
			'mkcol' => ['MKCOL', '/notes/x', 'notes:rw', false, AccessPolicy::METHOD_NOT_ALLOWED],
			'anonymous public document' => ['GET', '/public/notes/a.txt', null, true, AccessPolicy::ALLOW],
			'anonymous public listing' => ['GET', '/public/notes/', null, true, AccessPolicy::FORBIDDEN],
			'anonymous public write' => ['PUT', '/public/notes/a.txt', null, true, AccessPolicy::FORBIDDEN],
			'anonymous private' => ['GET', '/notes/a.txt', null, true, AccessPolicy::FORBIDDEN],
		];
	}

	#[DataProvider('cases')]
	public function testDecide(string $method, string $rel, ?string $scope, bool $publicOnly, string $expected): void {
		$this->assertSame($expected, self::decide($method, $rel, $scope, $publicOnly));
	}

	public function testOutsideTheRootIsForbidden(): void {
		$this->assertSame(AccessPolicy::FORBIDDEN, AccessPolicy::decide('GET', null, Scope::parse('*:rw'), false));
	}

	public function testLoginWithoutScopeIsForbidden(): void {
		$this->assertSame(AccessPolicy::FORBIDDEN, self::decide('GET', '/notes/a.txt', null, false));
	}
}
