<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/** @template-extends QBMapper<AuthCode> */
class AuthCodeMapper extends QBMapper {
	public const TABLE = 'remotestorage_auth_codes';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, AuthCode::class);
	}

	/** @throws \OCP\AppFramework\Db\DoesNotExistException */
	public function findByHash(string $hash): AuthCode {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('code_hash', $qb->createNamedParameter($hash)));
		return $this->findEntity($qb);
	}

	/** Deletes the row and returns how many were removed (0 if already consumed). */
	public function deleteByHash(string $hash): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)->where($qb->expr()->eq('code_hash', $qb->createNamedParameter($hash)));
		return $qb->executeStatement();
	}

	public function deleteForUser(string $uid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)));
		$qb->executeStatement();
	}
}
