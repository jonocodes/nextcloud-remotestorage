<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Migration;

use Closure;
use OCA\RemoteStorage\Db\TokenMapper;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20261003000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable(TokenMapper::TABLE)) {
			return null;
		}
		$table = $schema->createTable(TokenMapper::TABLE);
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('client_id', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('scope', Types::STRING, ['notnull' => true, 'length' => 2000]);
		$table->addColumn('token_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('last_used_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['token_hash'], 'rs_tokens_hash');
		$table->addIndex(['user_id'], 'rs_tokens_user');
		return $schema;
	}
}
