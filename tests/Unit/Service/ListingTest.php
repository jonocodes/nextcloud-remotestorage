<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Tests\Unit\Service;

use OCA\RemoteStorage\Service\Listing;
use PHPUnit\Framework\TestCase;

class ListingTest extends TestCase {
	public function testFolderDescription(): void {
		$listing = new Listing();
		$listing->addFolder('sub', '"abc"');
		$listing->addDocument('a.txt', '"def"', 'text/plain', 5, 1790990000);
		$this->assertSame([
			'@context' => 'http://remotestorage.io/spec/folder-description',
			'items' => [
				'sub/' => ['ETag' => 'abc'],
				'a.txt' => [
					'ETag' => 'def',
					'Content-Type' => 'text/plain',
					'Content-Length' => 5,
					'Last-Modified' => 'Sat, 03 Oct 2026 01:13:20 GMT',
				],
			],
		], json_decode($listing->toJson(), true));
	}

	public function testEmptyItemsIsAnObject(): void {
		$this->assertSame(
			'{"@context":"http://remotestorage.io/spec/folder-description","items":{}}',
			(new Listing())->toJson()
		);
	}

	public function testStripsWeakAndQuotedETags(): void {
		$listing = new Listing();
		$listing->addFolder('x', 'W/"123"');
		$this->assertSame('123', json_decode($listing->toJson(), true)['items']['x/']['ETag']);
	}
}
