<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Tests\Unit\Dav;

use OCA\RemoteStorage\Dav\RequestState;
use OCA\RemoteStorage\Dav\RsPlugin;
use OCA\RemoteStorage\Service\ContentTypeService;
use OCA\RemoteStorage\Service\StoragePaths;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\Locked;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\Server;
use Sabre\DAV\SimpleCollection;
use Sabre\DAV\SimpleFile;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\Response;

class RsPluginTest extends TestCase {
	private RsPlugin $plugin;
	private Server $server;

	protected function setUp(): void {
		$state = new RequestState();
		$state->uid = 'alice';
		$this->plugin = new RsPlugin(
			new StoragePaths('remoteStorage'),
			$state,
			$this->createMock(ContentTypeService::class),
			$this->createMock(ILockingProvider::class),
		);
		$root = new SimpleCollection('root', [
			new SimpleCollection('files', [
				new SimpleCollection('alice', [
					new SimpleCollection('remoteStorage', [
						new SimpleCollection('notes', [
							new SimpleCollection('sub', [
								new SimpleFile('a.txt', 'a'),
							]),
						]),
					]),
				]),
			]),
		]);
		$this->server = new Server($root);
		$this->plugin->initialize($this->server);
	}

	public function testAfterDeleteLeavesEmptyParentFolders(): void {
		$this->assertTrue($this->server->tree->nodeExists('files/alice/remoteStorage/notes/sub'));
		$response = new Response();
		$response->setStatus(204);
		$this->plugin->afterDelete($this->documentRequest('/notes/sub/a.txt'), $response);
		$this->assertTrue($this->server->tree->nodeExists('files/alice/remoteStorage/notes/sub'));
		$this->assertTrue($this->server->tree->nodeExists('files/alice/remoteStorage/notes'));
		$this->assertSame(200, $response->getStatus());
	}

	public function testDeleteOfAFolderWithoutTrailingSlashIsRefused(): void {
		// DELETE applies to documents; WebDAV would delete the folder and
		// everything in it.
		$this->expectException(NotFound::class);
		$this->plugin->refuseFolderDelete($this->documentRequest('/notes/sub'), new Response());
	}

	public function testDeleteOfADocumentIsLeftToWebDav(): void {
		$this->plugin->refuseFolderDelete($this->documentRequest('/notes/sub/a.txt'), new Response());
		$this->assertTrue($this->server->tree->nodeExists('files/alice/remoteStorage/notes/sub/a.txt'));
	}

	/** A plugin whose storage holds only notes/, standing in as $notes, with $locks. */
	private function pluginWithNotes(SimpleCollection $notes, ILockingProvider $locks): RsPlugin {
		$state = new RequestState();
		$state->uid = 'alice';
		$plugin = new RsPlugin(new StoragePaths('remoteStorage'), $state, $this->createMock(ContentTypeService::class), $locks);
		$plugin->initialize(new Server(new SimpleCollection('root', [
			new SimpleCollection('files', [
				new SimpleCollection('alice', [
					new SimpleCollection('remoteStorage', [$notes]),
				]),
			]),
		])));
		return $plugin;
	}

	public function testCreatesMissingParentsUnderAPerUserLock(): void {
		$notes = new WritableCollection('notes');
		$locks = $this->createMock(ILockingProvider::class);
		$locks->expects($this->once())->method('acquireLock')->with('remotestorage/parents/alice', ILockingProvider::LOCK_EXCLUSIVE);
		$locks->expects($this->once())->method('releaseLock')->with('remotestorage/parents/alice', ILockingProvider::LOCK_EXCLUSIVE);
		$this->pluginWithNotes($notes, $locks)->createParents($this->documentRequest('/notes/new/deep/a.txt'), new Response());
		$this->assertTrue($notes->getChild('new')->childExists('deep'));
	}

	public function testTakesNoLockWhenEveryParentExists(): void {
		$locks = $this->createMock(ILockingProvider::class);
		$locks->expects($this->never())->method('acquireLock');
		$this->pluginWithNotes(new WritableCollection('notes'), $locks)
			->createParents($this->documentRequest('/notes/a.txt'), new Response());
	}

	public function testDoesNotRecreateAParentAnotherRequestMadeWhileWaiting(): void {
		// Parallel PUTs into a new folder: the other request held the lock and
		// created the folder; creating it again would lock out other writers.
		$notes = new WritableCollection('notes');
		$locks = $this->createMock(ILockingProvider::class);
		$locks->method('acquireLock')->willReturnCallback(static function () use ($notes): void {
			$notes->addChild(new WritableCollection('new'));
		});
		$this->pluginWithNotes($notes, $locks)->createParents($this->documentRequest('/notes/new/a.txt'), new Response());
		$this->assertSame(0, $notes->created);
	}

	public function testAParentOnDiskButNotYetIndexedCountsAsMissing(): void {
		// Another request has just made the folder on disk but not yet in the file
		// cache WebDAV resolves paths with; taking it for done would 404 the PUT.
		$notes = new WritableCollection('notes');
		$notes->addChild(new WritableCollection('new'));
		$notes->unindexed = 'new';
		$locks = $this->createMock(ILockingProvider::class);
		$locks->expects($this->once())->method('acquireLock')->willReturnCallback(static function () use ($notes): void {
			$notes->unindexed = null;   // the other request finished while we waited
		});
		$this->pluginWithNotes($notes, $locks)->createParents($this->documentRequest('/notes/new/a.txt'), new Response());
		$this->assertSame(0, $notes->created);
	}

	public function testWaitsWhileAnotherRequestHoldsTheLock(): void {
		$notes = new WritableCollection('notes');
		$locks = $this->createMock(ILockingProvider::class);
		$calls = 0;
		$locks->method('acquireLock')->willReturnCallback(static function () use (&$calls): void {
			if (++$calls === 1) {
				throw new LockedException('remotestorage/parents/alice');
			}
		});
		$this->pluginWithNotes($notes, $locks)->createParents($this->documentRequest('/notes/new/a.txt'), new Response());
		$this->assertSame(2, $calls);
		$this->assertTrue($notes->childExists('new'));
	}

	public function testAnswers423WhenTheLockStaysTaken(): void {
		$locks = $this->createMock(ILockingProvider::class);
		$locks->method('acquireLock')->willThrowException(new LockedException('remotestorage/parents/alice'));
		$locks->expects($this->never())->method('releaseLock');
		$this->expectException(Locked::class);
		$this->pluginWithNotes(new WritableCollection('notes'), $locks)
			->createParents($this->documentRequest('/notes/new/a.txt'), new Response());
	}

	public function testReleasesTheLockWhenCreatingAParentFails(): void {
		$locks = $this->createMock(ILockingProvider::class);
		$locks->expects($this->once())->method('releaseLock');
		$this->expectException(Forbidden::class);
		// A plain SimpleCollection refuses to create folders.
		$this->pluginWithNotes(new SimpleCollection('notes'), $locks)
			->createParents($this->documentRequest('/notes/new/a.txt'), new Response());
	}

	private function documentRequest(string $rel): RequestInterface {
		$request = $this->createMock(RequestInterface::class);
		$request->method('getUrl')->willReturn('http://example.test/remote.php/dav/files/alice/remoteStorage' . $rel);
		$request->method('getPath')->willReturn('files/alice/remoteStorage' . $rel);
		return $request;
	}

	/** Runs the CORS hook for a request under alice's storage root. */
	private function cors(string $authorization): Response {
		$request = $this->createMock(RequestInterface::class);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): ?string => match ($name) {
				'Origin' => 'https://app.example',
				'Authorization' => $authorization,
				default => null,
			}
		);
		$request->method('getUrl')->willReturn('https://cloud.example/remote.php/dav/files/alice/remoteStorage/notes/a.txt');
		$response = new Response();
		$this->plugin->cors($request, $response);
		return $response;
	}

	public static function bearerSchemes(): array {
		return [
			'canonical' => ['Bearer rs_'],
			'lowercase' => ['bearer rs_'],
			'mixed-case' => ['BeArEr rs_'],
		];
	}

	#[DataProvider('bearerSchemes')]
	public function testCorsAppliesToBearerRegardlessOfSchemeCasing(string $scheme): void {
		$response = $this->cors($scheme . str_repeat('a', 43));
		$this->assertSame('https://app.example', $response->getHeader('Access-Control-Allow-Origin'));
	}

	public function testCorsIsLeftToCoreForBasicAuth(): void {
		$response = $this->cors('Basic dXNlcjpwYXNz');
		$this->assertNull($response->getHeader('Access-Control-Allow-Origin'));
	}
}

/**
 * A folder that can create subfolders, counting how many it created. A child
 * named $unindexed exists on disk (childExists) but cannot be looked up yet,
 * like a folder another request has made but not yet put in the file cache.
 */
final class WritableCollection extends SimpleCollection {
	public int $created = 0;
	public ?string $unindexed = null;

	public function createDirectory($name): void {
		$this->created++;
		$this->addChild(new self($name));
	}

	/** Like Nextcloud's Directory::childExists, a look at the disk. */
	public function childExists($name): bool {
		return $name === $this->unindexed || parent::childExists($name);
	}

	public function getChild($name) {
		if ($name === $this->unindexed) {
			throw new NotFound("$name is not in the file cache yet");
		}
		return parent::getChild($name);
	}
}
