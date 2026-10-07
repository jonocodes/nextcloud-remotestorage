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

	public function deleteForUser(string $uid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)));
		$qb->executeStatement();
	}
}
