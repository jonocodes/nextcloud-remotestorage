<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Service;

use OCA\RemoteStorage\Db\ContentTypeStore;

/**
 * remoteStorage requires a document's Content-Type to be the one sent with
 * its PUT; Nextcloud only guesses from the file name. The app stores the PUT
 * Content-Type per file id, together with the ETag it was stored for, and
 * applies it only while that ETag is current, so edits made outside
 * remoteStorage fall back to Nextcloud's guess.
 */
class ContentTypeService {
	/** RFC 9110 media type: token "/" token *( OWS ";" OWS parameter ) */
	private const MEDIA_TYPE = '#^[!\#$%&\'*+.^_`|~0-9A-Za-z-]+/[!\#$%&\'*+.^_`|~0-9A-Za-z-]+(\s*;\s*[!\#$%&\'*+.^_`|~0-9A-Za-z-]+=("[^"\r\n]*"|[!\#$%&\'*+.^_`|~0-9A-Za-z-]+))*$#';
	private const MAX_LENGTH = 255;

	public function __construct(
		private ContentTypeStore $store,
	) {
	}

	public function remember(int $fileId, string $etag, string $contentType): void {
		$contentType = trim($contentType);
		if (strlen($contentType) > self::MAX_LENGTH || !preg_match(self::MEDIA_TYPE, $contentType)) {
			$this->store->delete($fileId);
			return;
		}
		$this->store->save($fileId, Listing::bareETag($etag), $contentType);
	}

	public function forNode(int $fileId, string $etag): ?string {
		return $this->forNodes([$fileId => $etag])[$fileId] ?? null;
	}

	/**
	 * @param array<int,string> $etags file id => current ETag
	 * @return array<int,string> file id => stored Content-Type, for current ETags only
	 */
	public function forNodes(array $etags): array {
		if ($etags === []) {
			return [];
		}
		$types = [];
		foreach ($this->store->findMany(array_keys($etags)) as $fileId => $row) {
			if (isset($etags[$fileId]) && $row['etag'] === Listing::bareETag($etags[$fileId])) {
				$types[$fileId] = $row['type'];
			}
		}
		return $types;
	}

	public function forget(int $fileId): void {
		$this->store->delete($fileId);
	}
}
