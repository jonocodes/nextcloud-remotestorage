<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Service;

/** A request URL resolved against the remoteStorage root. */
final class StorageMatch {
	/**
	 * @param string $rel path below the storage root, always starting with "/"
	 * @param ?string $module the scope module, null for the root and /public/ itself
	 */
	public function __construct(
		public readonly string $uid,
		public readonly string $rel,
		public readonly ?string $module,
		public readonly bool $folder,
		public readonly bool $public,
	) {
	}
}
