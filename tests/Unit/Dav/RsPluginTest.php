<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Tests\Unit\Dav;

use OCA\RemoteStorage\Dav\RequestState;
use OCA\RemoteStorage\Dav\RsPlugin;
use OCA\RemoteStorage\Service\ContentTypeService;
use OCA\RemoteStorage\Service\StoragePaths;
use PHPUnit\Framework\TestCase;
use Sabre\DAV\Server;
use Sabre\DAV\SimpleCollection;
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
		);
		$root = new SimpleCollection('root', [
			new SimpleCollection('files', [
				new SimpleCollection('alice', [
					new SimpleCollection('remoteStorage', [
						new SimpleCollection('notes', [
							new SimpleCollection('sub', []),
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

	private function documentRequest(string $rel): RequestInterface {
		$request = $this->createMock(RequestInterface::class);
		$request->method('getUrl')->willReturn('http://example.test/remote.php/dav/files/alice/remoteStorage' . $rel);
		return $request;
	}
}
