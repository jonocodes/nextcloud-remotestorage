<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Service;

use InvalidArgumentException;

/**
 * A remoteStorage OAuth scope such as "notes:rw contacts:r" or "*:rw".
 * "<module>:<level>" covers /<module>/ and /public/<module>/; "*" covers everything.
 */
final class Scope implements \Stringable {
	private const MODULE = '/^(?:\*|[a-z0-9_-]+)$/';
	private const MAX_NORMALISED_BYTES = 2000;

	/** @param array<string,'r'|'rw'> $levels */
	private function __construct(
		private array $levels,
	) {
	}

	/** @throws InvalidArgumentException on any malformed or empty scope */
	public static function parse(string $raw): self {
		$levels = [];
		foreach (preg_split('/[\s,]+/', trim($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $item) {
			$parts = explode(':', $item);
			if (count($parts) !== 2 || !preg_match(self::MODULE, $parts[0])
				|| !in_array($parts[1], ['r', 'rw'], true) || $parts[0] === 'public') {
				throw new InvalidArgumentException("invalid scope item '$item'");
			}
			[$module, $level] = $parts;
			if (($levels[$module] ?? null) !== 'rw') {
				$levels[$module] = $level;
			}
		}
		if ($levels === []) {
			throw new InvalidArgumentException('empty scope');
		}
		ksort($levels);
		$scope = new self($levels);
		if (strlen((string)$scope) > self::MAX_NORMALISED_BYTES) {
			throw new InvalidArgumentException('normalised scope is too long');
		}
		return $scope;
	}

	public function allows(string $module, bool $write): bool {
		$level = $this->levels['*'] ?? $this->levels[$module] ?? null;
		return $level !== null && (!$write || $level === 'rw');
	}

	/** The storage root and /public/ itself are only covered by "*". */
	public function allowsRoot(bool $write): bool {
		$level = $this->levels['*'] ?? null;
		return $level !== null && (!$write || $level === 'rw');
	}

	/**
	 * The item that covers a module ("*" wins), e.g. "notes:rw"; for null, the
	 * one covering the storage root. Null when nothing does.
	 */
	public function itemFor(?string $module): ?string {
		foreach (['*', $module] as $key) {
			if ($key !== null && isset($this->levels[$key])) {
				return $key . ':' . $this->levels[$key];
			}
		}
		return null;
	}

	/** @return array<string,'r'|'rw'> */
	public function levels(): array {
		return $this->levels;
	}

	public function __toString(): string {
		return implode(' ', array_map(
			static fn (string $module, string $level): string => "$module:$level",
			array_keys($this->levels),
			$this->levels
		));
	}
}
