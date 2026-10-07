<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Migration;

use Closure;
use OCA\RemoteStorage\Db\AuthCodeMapper;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** One-time authorization codes for the PKCE code flow (see AuthCodeService). */
class Version1002Date20261007000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable(AuthCodeMapper::TABLE)) {
			return null;
		}
		$table = $schema->createTable(AuthCodeMapper::TABLE);
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('code_hash', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('user_id', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('client_id', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->addColumn('redirect_uri', Types::STRING, ['notnull' => true, 'length' => 2000]);
		$table->addColumn('scope', Types::STRING, ['notnull' => true, 'length' => 2000]);
		$table->addColumn('code_challenge', Types::STRING, ['notnull' => true, 'length' => 128]);
		$table->addColumn('code_challenge_method', Types::STRING, ['notnull' => true, 'length' => 10]);
		$table->addColumn('created_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('expires_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['code_hash'], 'rs_auth_codes_hash');
		$table->addIndex(['user_id'], 'rs_auth_codes_user');
		return $schema;
	}
}
