<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Token> */
class TokenMapper extends QBMapper {
	public const TABLE = 'remotestorage_tokens';

	public function __construct(IDBConnection $db) {
		parent::__construct($db, self::TABLE, Token::class);
	}

	public function findByHash(string $hash): Token {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('token_hash', $qb->createNamedParameter($hash)));
		return $this->findEntity($qb);
	}

	public function findForUser(int $id, string $uid): Token {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)));
		return $this->findEntity($qb);
	}

	/** @return list<Token> */
	public function findAllForUser(string $uid): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)))
			->orderBy('created_at', 'DESC');
		return $this->findEntities($qb);
	}

	public function deleteForUser(string $uid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)->where($qb->expr()->eq('user_id', $qb->createNamedParameter($uid)));
		$qb->executeStatement();
	}
}
