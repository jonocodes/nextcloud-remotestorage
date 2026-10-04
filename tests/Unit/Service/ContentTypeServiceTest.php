<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Tests\Unit\Service;

use OCA\RemoteStorage\Db\ContentTypeStore;
use OCA\RemoteStorage\Service\ContentTypeService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ContentTypeServiceTest extends TestCase {
	private ContentTypeStore&MockObject $store;
	private ContentTypeService $service;

	protected function setUp(): void {
		$this->store = $this->createMock(ContentTypeStore::class);
		$this->service = new ContentTypeService($this->store);
	}

	public function testRemembersTheExactHeaderAgainstTheBareETag(): void {
		$this->store->expects($this->once())->method('save')->with(42, 'abc', 'image/jpeg; charset=binary');
		$this->service->remember(42, '"abc"', 'image/jpeg; charset=binary');
	}

	public static function invalidTypes(): array {
		return [[''], ['text'], ['text/'], ["text/plain\r\nX: y"], [str_repeat('a', 251) . '/json']];
	}

	#[DataProvider('invalidTypes')]
	public function testInvalidTypesAreForgotten(string $type): void {
		$this->store->expects($this->never())->method('save');
		$this->store->expects($this->once())->method('delete')->with(42);
		$this->service->remember(42, '"abc"', $type);
	}

	public function testStoredTypeAppliesOnlyWhileTheETagMatches(): void {
		$this->store->method('findMany')->willReturn([1 => ['etag' => 'abc', 'type' => 'application/json']]);
		$this->assertSame('application/json', $this->service->forNode(1, '"abc"'));
		$this->assertNull($this->service->forNode(1, '"changed-elsewhere"'));
	}

	public function testManyAtOnce(): void {
		$this->store->expects($this->once())->method('findMany')->with([1, 2, 3])->willReturn([
			1 => ['etag' => 'a', 'type' => 'application/json'],
			2 => ['etag' => 'old', 'type' => 'text/markdown'],
		]);
		$this->assertSame([1 => 'application/json'], $this->service->forNodes([1 => '"a"', 2 => '"b"', 3 => '"c"']));
	}
}
