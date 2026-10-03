<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\RemoteStorage\Service\Scope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ScopeTest extends TestCase {
	public function testParsesModulesAndLevels(): void {
		$scope = Scope::parse('notes:rw contacts:r');
		$this->assertTrue($scope->allows('notes', true));
		$this->assertTrue($scope->allows('contacts', false));
		$this->assertFalse($scope->allows('contacts', true));
		$this->assertFalse($scope->allows('photos', false));
	}

	public function testWildcardCoversEveryModuleAndTheRoot(): void {
		$scope = Scope::parse('*:r');
		$this->assertTrue($scope->allows('anything', false));
		$this->assertTrue($scope->allowsRoot(false));
		$this->assertFalse($scope->allowsRoot(true));
		$this->assertFalse(Scope::parse('notes:rw')->allowsRoot(false));
	}

	public function testDuplicateModuleKeepsTheStrongerLevel(): void {
		$this->assertTrue(Scope::parse('notes:r notes:rw')->allows('notes', true));
		$this->assertTrue(Scope::parse('notes:rw notes:r')->allows('notes', true));
	}

	public function testToStringIsNormalised(): void {
		$this->assertSame('contacts:r notes:rw', (string)Scope::parse('  notes:rw   contacts:r notes:r '));
	}

	public function testAcceptsCommaSeparatedScopes(): void {
		// Some clients join scopes with commas; remoteStorage.js uses spaces.
		$this->assertSame('contacts:r notes:rw', (string)Scope::parse('notes:rw,contacts:r'));
	}

	public static function invalidScopes(): array {
		return [
			'empty' => [''],
			'no level' => ['notes'],
			'bad level' => ['notes:x'],
			'path traversal' => ['../etc:rw'],
			'slash' => ['a/b:rw'],
			'uppercase' => ['Notes:rw'],
			'public is not a module' => ['public:rw'],
		];
	}

	#[DataProvider('invalidScopes')]
	public function testRejectsInvalidScopes(string $raw): void {
		$this->expectException(InvalidArgumentException::class);
		Scope::parse($raw);
	}
}
