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
		return $this->itemFor($module, $write) !== null;
	}

	/** The storage root and /public/ itself are only covered by "*". */
	public function allowsRoot(bool $write): bool {
		return $this->itemFor(null, $write) !== null;
	}

	/**
	 * The scope item that grants the requested access, e.g. "notes:rw", or null.
	 * A token's access is the sum of its items (spec section 9), so any matching
	 * item suffices and a specific item is not overridden by "*"; the module's
	 * own item is preferred for the debug reason. For null (the storage root and
	 * /public/ themselves) only "*" can grant it.
	 */
	public function itemFor(?string $module, bool $write): ?string {
		foreach ($module === null ? ['*'] : [$module, '*'] as $key) {
			$level = $this->levels[$key] ?? null;
			if ($level !== null && (!$write || $level === 'rw')) {
				return $key . ':' . $level;
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
