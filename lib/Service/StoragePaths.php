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
		$uidRaw = $m[1];
		$relRaw = ($m[2] ?? '') === '' ? '/' : $m[2];
		// Reject encodings this layer and the transport would read differently:
		// an encoded "/" would make us split the path differently than Sabre,
		// and an encoded NUL is never part of a valid storage path.
		if (stripos($uidRaw, '%2f') !== false || stripos($relRaw, '%2f') !== false
			|| stripos($uidRaw, '%00') !== false || stripos($relRaw, '%00') !== false) {
			return null;
		}
		$uid = rawurldecode($uidRaw);
		$rel = rawurldecode($relRaw);
		if ($uid === '.' || $uid === '..' || str_contains($uid, '/') || str_contains($uid, "\0")) {
			return null;
		}
		// Every segment must be a real name: no "." / "..", no NUL, and no
		// empty segment ("//"). The trailing empty segment of a folder is fine.
		$parts = explode('/', substr($rel, 1));
		foreach ($parts as $i => $part) {
			if (($part === '' && $i !== count($parts) - 1) || $part === '.' || $part === '..'
				|| str_contains($part, "\0")) {
				return null;
			}
		}
		$segments = explode('/', trim($rel, '/'));
		$public = str_starts_with($rel, '/public/');
		$folder = str_ends_with($rel, '/');
		$modulePos = $public ? 1 : 0;
		$module = $segments[$modulePos] ?? '';
		// A path without a trailing slash names a document, and a module scope
		// only covers /<module>/ and /public/<module>/. So when the module
		// segment is the last one and the path is not a folder, it is a document
		// at that level (the root, or /public/) that only "*" reaches.
		if ($module === '' || !($folder || isset($segments[$modulePos + 1]))) {
			$module = null;
		}
		return new StorageMatch($uid, $rel, $module, $folder, $public);
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
