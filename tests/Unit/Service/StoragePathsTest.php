<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Tests\Unit\Service;

use OCA\RemoteStorage\Service\StoragePaths;
use PHPUnit\Framework\TestCase;

class StoragePathsTest extends TestCase {
	private StoragePaths $paths;

	protected function setUp(): void {
		$this->paths = new StoragePaths('remoteStorage');
	}

	public function testDocument(): void {
		$m = $this->paths->match('/remote.php/dav/files/alice/remoteStorage/notes/a.txt');
		$this->assertSame('alice', $m->uid);
		$this->assertSame('/notes/a.txt', $m->rel);
		$this->assertSame('notes', $m->module);
		$this->assertFalse($m->folder);
		$this->assertFalse($m->public);
	}

	public function testTrailingSlashMakesAFolder(): void {
		$this->assertTrue($this->paths->match('/remote.php/dav/files/alice/remoteStorage/notes/')->folder);
		$this->assertFalse($this->paths->match('/remote.php/dav/files/alice/remoteStorage/notes')->folder);
	}

	public function testSlashlessRootDocumentIsNotAModule(): void {
		$m = $this->paths->match('/remote.php/dav/files/alice/remoteStorage/notes');
		$this->assertSame('/notes', $m->rel);
		$this->assertFalse($m->folder);
		$this->assertNull($m->module, 'a document at the root is not inside a module folder');
	}

	public function testSlashlessPublicRootDocumentIsNotAModule(): void {
		$m = $this->paths->match('/remote.php/dav/files/alice/remoteStorage/public/notes');
		$this->assertTrue($m->public);
		$this->assertFalse($m->folder);
		$this->assertNull($m->module);
	}

	public function testRoot(): void {
		foreach (['/remote.php/dav/files/alice/remoteStorage', '/remote.php/dav/files/alice/remoteStorage/'] as $url) {
			$m = $this->paths->match($url);
			$this->assertSame('/', $m->rel);
			$this->assertTrue($m->folder);
			$this->assertNull($m->module);
		}
	}

	public function testPublicPathsUseTheSecondSegmentAsModule(): void {
		$m = $this->paths->match('/remote.php/dav/files/alice/remoteStorage/public/notes/a.txt');
		$this->assertTrue($m->public);
		$this->assertSame('notes', $m->module);
		$public = $this->paths->match('/remote.php/dav/files/alice/remoteStorage/public/');
		$this->assertTrue($public->public);
		$this->assertNull($public->module);
	}

	public function testDecodesPercentEncoding(): void {
		$m = $this->paths->match('/remote.php/dav/files/al%20ice/remoteStorage/notes/a%20b.txt');
		$this->assertSame('al ice', $m->uid);
		$this->assertSame('/notes/a b.txt', $m->rel);
	}

	public function testIgnoresTheQueryString(): void {
		$this->assertSame('/notes/a.txt', $this->paths->match('/remote.php/dav/files/alice/remoteStorage/notes/a.txt?x=1')->rel);
	}

	public function testOnlyMatchesRightAfterTheDavBase(): void {
		// WebDAV resolves these against its own base, so a storage root further
		// down the URL would be checked against a different path than it serves.
		$this->assertNull($this->paths->match('/remote.php/dav/files/alice/Documents/remote.php/dav/files/alice/remoteStorage/notes/a'));
		$this->assertNull($this->paths->match('/remote%2Ephp/dav/files/alice/Documents/remote.php/dav/files/alice/remoteStorage/notes/a'));
		$this->assertNull($this->paths->match('/x/../remote.php/dav/files/alice/remoteStorage/notes/a'));
		$this->assertNull($this->paths->match('//remote.php/dav/files/alice/remoteStorage/notes/a'));
	}

	public function testHonoursTheWebroot(): void {
		$paths = new StoragePaths('remoteStorage', '/nextcloud');
		$this->assertSame('/notes/a', $paths->match('https://cloud.example/nextcloud/remote.php/dav/files/alice/remoteStorage/notes/a')->rel);
		$this->assertNull($paths->match('/remote.php/dav/files/alice/remoteStorage/notes/a'));
		$this->assertNull($paths->match('/other/remote.php/dav/files/alice/remoteStorage/notes/a'));
	}

	public function testOutsideTheRootIsNull(): void {
		$this->assertNull($this->paths->match('/remote.php/dav/files/alice/other/a.txt'));
		$this->assertNull($this->paths->match('/remote.php/dav/files/alice/remoteStorageX/a.txt'));
		$this->assertNull($this->paths->match('/remote.php/dav/files/alice/'));
		$this->assertNull($this->paths->match('/remote.php/dav/calendars/alice/remoteStorage/'));
	}

	public function testRejectsDotSegments(): void {
		$this->assertNull($this->paths->match('/remote.php/dav/files/alice/remoteStorage/notes/../../secret'));
		$this->assertNull($this->paths->match('/remote.php/dav/files/alice/remoteStorage/./notes'));
	}

	public function testRejectsEncodedDotSegments(): void {
		$this->assertNull($this->paths->match('/remote.php/dav/files/alice/remoteStorage/notes/%2e%2e/secret'));
		$this->assertNull($this->paths->match('/remote.php/dav/files/alice/remoteStorage/notes/%2E%2E/secret'));
	}

	public function testRejectsEncodedSlash(): void {
		// An encoded "/" would make this layer split the path differently than
		// the transport, so it is never accepted.
		$this->assertNull($this->paths->match('/remote.php/dav/files/alice/remoteStorage/notes%2fa.txt'));
		$this->assertNull($this->paths->match('/remote.php/dav/files/alice/remoteStorage/notes%2F..%2Fsecret'));
		$this->assertNull($this->paths->match('/remote.php/dav/files/al%2fice/remoteStorage/notes/a.txt'));
	}

	public function testRejectsEncodedNullByte(): void {
		$this->assertNull($this->paths->match('/remote.php/dav/files/alice/remoteStorage/notes/a%00b.txt'));
		$this->assertNull($this->paths->match('/remote.php/dav/files/al%00ice/remoteStorage/notes/a.txt'));
	}

	public function testRejectsEmptyPathSegment(): void {
		$this->assertNull($this->paths->match('/remote.php/dav/files/alice/remoteStorage/notes//a.txt'));
		$this->assertNull($this->paths->match('/remote.php/dav/files/alice/remoteStorage//notes/a.txt'));
		$this->assertNull($this->paths->match('/remote.php/dav/files/alice/remoteStorage//'));
	}

	public function testRejectsDotOrDotDotUser(): void {
		$this->assertNull($this->paths->match('/remote.php/dav/files/../remoteStorage/notes/a.txt'));
		$this->assertNull($this->paths->match('/remote.php/dav/files/./remoteStorage/notes/a.txt'));
	}

	public function testAllowsAnEncodedPercentInAName(): void {
		// A literal "%" in a filename is valid and must survive decoding.
		$m = $this->paths->match('/remote.php/dav/files/alice/remoteStorage/notes/50%25.txt');
		$this->assertSame('/notes/50%.txt', $m->rel);
	}

	public function testStorageUrlPath(): void {
		$this->assertSame('/remote.php/dav/files/al%20ice/remoteStorage', $this->paths->storagePath('al ice'));
	}

	public function testDavPathsForParentsOfADocument(): void {
		$m = $this->paths->match('/remote.php/dav/files/alice/remoteStorage/notes/a/b.txt');
		$this->assertSame(
			['files/alice/remoteStorage', 'files/alice/remoteStorage/notes', 'files/alice/remoteStorage/notes/a'],
			$this->paths->parentDavPaths($m)
		);
	}
}
