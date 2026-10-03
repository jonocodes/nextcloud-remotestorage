<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Service;

/**
 * Maps request URLs under /remote.php/dav/files/<uid>/<root>/ to remoteStorage
 * paths. Works on the raw URL: Sabre's getPath() strips the trailing slash, and
 * in remoteStorage that slash is what makes a path a folder.
 */
final class StoragePaths {
	public function __construct(
		private string $root,
	) {
	}

	public function root(): string {
		return $this->root;
	}

	public function match(string $url): ?StorageMatch {
		$path = (string)parse_url($url, PHP_URL_PATH);
		$pattern = '#^(?:.*)/remote\.php/dav/files/([^/]+)/' . preg_quote($this->root, '#') . '(/.*)?$#';
		if (!preg_match($pattern, $path, $m)) {
			return null;
		}
		$rel = rawurldecode(($m[2] ?? '') === '' ? '/' : $m[2]);
		$segments = explode('/', trim($rel, '/'));
		if (in_array('.', $segments, true) || in_array('..', $segments, true)) {
			return null;
		}
		$public = ($segments[0] ?? '') === 'public';
		$module = $public ? ($segments[1] ?? '') : ($segments[0] ?? '');
		$folder = str_ends_with($rel, '/');
		// "/notes" without the slash names a document; "/public/" or "/" have no module.
		if ($module === '' || ($folder && count(array_filter($segments)) === ($public ? 1 : 0))) {
			$module = null;
		}
		return new StorageMatch(rawurldecode($m[1]), $rel, $module, $folder, $public);
	}

	/** URL path of a user's storage root, as advertised in WebFinger. */
	public function storagePath(string $uid): string {
		return '/remote.php/dav/files/' . rawurlencode($uid) . '/' . $this->root;
	}

	/**
	 * Sabre tree paths of every folder above a document, from the storage root
	 * down to its direct parent.
	 *
	 * @return list<string>
	 */
	public function parentDavPaths(StorageMatch $match): array {
		$base = 'files/' . $match->uid . '/' . $this->root;
		$paths = [$base];
		$segments = explode('/', trim($match->rel, '/'));
		array_pop($segments);
		foreach ($segments as $segment) {
			$base .= '/' . $segment;
			$paths[] = $base;
		}
		return $paths;
	}
}
