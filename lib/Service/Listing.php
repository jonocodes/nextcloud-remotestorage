<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Service;

/** Builds a remoteStorage folder description (JSON-LD). */
final class Listing {
	/** @var array<string,array<string,string|int>> */
	private array $items = [];

	public function addFolder(string $name, string $etag): void {
		$this->items[$name . '/'] = ['ETag' => self::bareETag($etag)];
	}

	public function addDocument(string $name, string $etag, string $contentType, int $size, int $mtime): void {
		$this->items[$name] = [
			'ETag' => self::bareETag($etag),
			'Content-Type' => $contentType,
			'Content-Length' => $size,
			'Last-Modified' => gmdate('D, d M Y H:i:s \G\M\T', $mtime),
		];
	}

	public function toJson(): string {
		return json_encode([
			'@context' => 'http://remotestorage.io/spec/folder-description',
			'items' => (object)$this->items,
		], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
	}

	/** Listing ETags carry no quotes (and no weak prefix). */
	public static function bareETag(string $etag): string {
		return trim(preg_replace('#^W/#', '', $etag) ?? $etag, '"');
	}
}
