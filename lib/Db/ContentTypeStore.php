<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** file id → (ETag, Content-Type) as sent with the remoteStorage PUT. */
class ContentTypeStore {
	public const TABLE = 'remotestorage_ctypes';

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function save(int $fileId, string $etag, string $contentType): void {
		$this->delete($fileId);
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)->values([
			'fileid' => $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT),
			'etag' => $qb->createNamedParameter($etag),
			'content_type' => $qb->createNamedParameter($contentType),
		])->executeStatement();
	}

	public function delete(int $fileId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('fileid', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * @param list<int> $fileIds
	 * @return array<int,array{etag:string,type:string}>
	 */
	public function findMany(array $fileIds): array {
		$rows = [];
		foreach (array_chunk($fileIds, 1000) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('fileid', 'etag', 'content_type')->from(self::TABLE)
				->where($qb->expr()->in('fileid', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$rows[(int)$row['fileid']] = ['etag' => (string)$row['etag'], 'type' => (string)$row['content_type']];
			}
			$result->closeCursor();
		}
		return $rows;
	}
}
