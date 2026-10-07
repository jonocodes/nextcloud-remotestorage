<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Tests\Unit\Service;

use OCA\RemoteStorage\Service\Cors;
use PHPUnit\Framework\TestCase;

class CorsTest extends TestCase {
	public function testHeadersEchoTheOrigin(): void {
		$this->assertSame(
			['Access-Control-Allow-Origin' => 'https://app.example', 'Vary' => 'Origin'],
			Cors::headers('https://app.example')
		);
	}

	public function testHeadersAreEmptyWithoutAnOrigin(): void {
		$this->assertSame([], Cors::headers(''));
	}

	public function testPreflightAllowsTheTokenPostWithoutCredentials(): void {
		$headers = Cors::preflight('https://app.example');
		$this->assertSame('https://app.example', $headers['Access-Control-Allow-Origin']);
		$this->assertSame('POST, OPTIONS', $headers['Access-Control-Allow-Methods']);
		$this->assertSame('Content-Type', $headers['Access-Control-Allow-Headers']);
		$this->assertSame('Origin', $headers['Vary']);
		$this->assertArrayNotHasKey('Access-Control-Allow-Credentials', $headers);
	}
}
