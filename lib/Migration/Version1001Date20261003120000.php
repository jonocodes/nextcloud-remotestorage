<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Migration;

use Closure;
use OCA\RemoteStorage\Db\ContentTypeStore;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/** Content-Types as sent with remoteStorage PUTs (see ContentTypeService). */
class Version1001Date20261003120000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable(ContentTypeStore::TABLE)) {
			return null;
		}
		$table = $schema->createTable(ContentTypeStore::TABLE);
		$table->addColumn('fileid', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->addColumn('etag', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('content_type', Types::STRING, ['notnull' => true, 'length' => 255]);
		$table->setPrimaryKey(['fileid']);
		return $schema;
	}
}
