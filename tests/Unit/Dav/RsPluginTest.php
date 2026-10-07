<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Tests\Unit\Dav;

use OCA\RemoteStorage\Dav\RequestState;
use OCA\RemoteStorage\Dav\RsPlugin;
use OCA\RemoteStorage\Service\ContentTypeService;
use OCA\RemoteStorage\Service\StoragePaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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
