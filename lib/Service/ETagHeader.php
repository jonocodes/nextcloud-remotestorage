<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Service;

/**
 * Normalises If-Match / If-None-Match values back to the stored ETag.
 * Web servers that compress responses change the ETag a client sees:
 * Apache's mod_deflate appends "-gzip", nginx turns it weak ("W/").
 * Clients send those back, and without this every conditional write after
 * a compressed read would fail with 412.
 */
final class ETagHeader {
	public static function normalize(string $header): string {
		if (trim($header) === '' || trim($header) === '*') {
			return trim($header);
		}
		$tags = [];
		foreach (explode(',', $header) as $tag) {
			$tag = trim($tag);
			if ($tag === '') {
				continue;
			}
			$bare = Listing::bareETag($tag);
			$bare = preg_replace('/-(gzip|br|deflate|zstd)$/', '', $bare) ?? $bare;
			$tags[] = '"' . $bare . '"';
		}
		return implode(', ', $tags);
	}
}
