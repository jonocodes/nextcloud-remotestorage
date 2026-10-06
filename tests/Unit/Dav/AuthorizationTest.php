<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Tests\Unit\Dav;

use OCA\RemoteStorage\Dav\Authorization;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AuthorizationTest extends TestCase {
	public static function headers(): array {
		return [
			'canonical' => ['Bearer abc', 'abc'],
			'lowercase scheme' => ['bearer abc', 'abc'],
			'uppercase scheme' => ['BEARER abc', 'abc'],
			'mixed-case scheme' => ['BeArEr abc', 'abc'],
			'extra whitespace after the scheme' => ['bearer   abc', 'abc'],
			'leading whitespace' => ['  Bearer abc', 'abc'],
			'empty credential' => ['Bearer ', ''],
			'credential keeps trailing whitespace' => ['Bearer abc ', 'abc '],
			'scheme without a credential' => ['Bearer', null],
			'basic is not bearer' => ['Basic dXNlcjpwYXNz', null],
			'digest is not bearer' => ['Digest realm="nextcloud"', null],
			'no scheme' => ['abc', null],
			'empty header' => ['', null],
		];
	}

	#[DataProvider('headers')]
	public function testBearer(string $header, ?string $expected): void {
		$this->assertSame($expected, Authorization::bearer($header));
	}
}
