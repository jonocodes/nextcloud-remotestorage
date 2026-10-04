<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Tests\Unit\Service;

use OCA\RemoteStorage\Service\ETagHeader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ETagHeaderTest extends TestCase {
	public static function cases(): array {
		return [
			'plain' => ['"abc"', '"abc"'],
			'apache mod_deflate suffix' => ['"abc-gzip"', '"abc"'],
			'brotli suffix' => ['"abc-br"', '"abc"'],
			'nginx weak' => ['W/"abc"', '"abc"'],
			'weak and suffixed' => ['W/"abc-gzip"', '"abc"'],
			'unquoted (some clients)' => ['abc', '"abc"'],
			'wildcard' => ['*', '*'],
			'list' => ['"a-gzip", W/"b" ,"c"', '"a", "b", "c"'],
			'empty' => ['', ''],
		];
	}

	#[DataProvider('cases')]
	public function testNormalize(string $in, string $out): void {
		$this->assertSame($out, ETagHeader::normalize($in));
	}
}
