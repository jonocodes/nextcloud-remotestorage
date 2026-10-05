<?php

declare(strict_types=1);

/**
 * Nextcloud's OCP stubs mention Doctrine and private OC classes in type
 * signatures. Those packages are not installed for unit tests; the constants
 * below exist only so the interfaces can load.
 */

namespace Doctrine\DBAL {
	if (!class_exists(ParameterType::class, false)) {
		class ParameterType {
			public const NULL = 0;
			public const INTEGER = 1;
			public const STRING = 2;
			public const LARGE_OBJECT = 3;
			public const BOOLEAN = 5;
		}
	}

	if (!class_exists(ArrayParameterType::class, false)) {
		class ArrayParameterType {
			public const INTEGER = 101;
			public const STRING = 102;
		}
	}
}

namespace Doctrine\DBAL\Types {
	if (!class_exists(Types::class, false)) {
		class Types {
			public const BOOLEAN = 'boolean';
			public const DATETIME_MUTABLE = 'datetime';
			public const DATETIME_IMMUTABLE = 'datetime_immutable';
			public const DATETIMETZ_MUTABLE = 'datetimetz';
			public const DATETIMETZ_IMMUTABLE = 'datetimetz_immutable';
			public const DATE_MUTABLE = 'date';
			public const DATE_IMMUTABLE = 'date_immutable';
			public const TIME_MUTABLE = 'time';
			public const TIME_IMMUTABLE = 'time_immutable';
		}
	}
}

namespace Doctrine\DBAL\Schema {
	if (!class_exists(Schema::class, false)) {
		class Schema {
		}
	}
}

namespace Doctrine\DBAL\Query\Expression {
	if (!class_exists(ExpressionBuilder::class, false)) {
		class ExpressionBuilder {
			public const EQ = '=';
			public const NEQ = '<>';
			public const LT = '<';
			public const LTE = '<=';
			public const GT = '>';
			public const GTE = '>=';
		}
	}
}

namespace OC\DB\QueryBuilder\Sharded {
	if (!class_exists(ShardDefinition::class, false)) {
		class ShardDefinition {
		}
	}

	if (!class_exists(CrossShardMoveHelper::class, false)) {
		class CrossShardMoveHelper {
		}
	}
}
