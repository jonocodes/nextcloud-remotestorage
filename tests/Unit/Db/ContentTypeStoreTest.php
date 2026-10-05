<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Tests\Unit\Db;

use Fiber;
use OCA\RemoteStorage\Db\ContentTypeStore;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/DatabaseStubs.php';

/**
 * The stand-in table yields once per statement, so two saves run in lockstep.
 * delete-then-insert then trips the primary key; one upsert per save does not.
 */
class ContentTypeStoreTest extends TestCase {
	/** @var array<int, array{etag:string, content_type:string}> */
	private array $rows = [];
	private ContentTypeStore $store;

	protected function setUp(): void {
		$this->rows = [];
		$this->store = new ContentTypeStore($this->connection());
	}

	public function testConcurrentSavesForOneFileLeaveOneCompletePair(): void {
		$this->interleave(
			fn () => $this->store->save(7, 'etag-a', 'application/json'),
			fn () => $this->store->save(7, 'etag-b', 'text/plain'),
		);

		$this->assertSame(
			[7 => ['etag' => 'etag-b', 'type' => 'text/plain']],
			$this->store->findMany([7]),
		);
	}

	public function testReplacingAnExistingRowStoresTheNewPair(): void {
		$this->store->save(7, 'etag-a', 'application/json');
		$this->store->save(7, 'etag-b', 'text/plain');

		$this->assertSame(
			[7 => ['etag' => 'etag-b', 'type' => 'text/plain']],
			$this->store->findMany([7]),
		);
	}

	private function interleave(callable $first, callable $second): void {
		$fibers = [new Fiber($first), new Fiber($second)];
		while ($fibers !== []) {
			foreach ($fibers as $key => $fiber) {
				if ($fiber->isTerminated()) {
					unset($fibers[$key]);
					continue;
				}
				$fiber->isStarted() ? $fiber->resume() : $fiber->start();
				if ($fiber->isTerminated()) {
					unset($fibers[$key]);
				}
			}
		}
	}

	private function pause(): void {
		if (Fiber::getCurrent() !== null) {
			Fiber::suspend();
		}
	}

	private function connection(): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->queryBuilder());
		$db->method('setValues')->willReturnCallback(function ($table, array $keys, array $values): int {
			$fileId = (int)$keys['fileid'];
			$inserted = !isset($this->rows[$fileId]);
			$this->rows[$fileId] = [
				'etag' => (string)$values['etag'],
				'content_type' => (string)$values['content_type'],
			];
			$this->pause();
			return $inserted ? 1 : 0;
		});
		return $db;
	}

	private function queryBuilder(): IQueryBuilder {
		$state = (object)['op' => null, 'params' => [], 'values' => [], 'whereParam' => null];
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('insert')->willReturnCallback(function (...$args) use ($qb, $state) {
			$state->op = 'insert';
			return $qb;
		});
		$qb->method('delete')->willReturnCallback(function (...$args) use ($qb, $state) {
			$state->op = 'delete';
			return $qb;
		});
		$qb->method('select')->willReturnCallback(function (...$args) use ($qb, $state) {
			$state->op = 'select';
			return $qb;
		});
		foreach (['from', 'where'] as $fluent) {
			$qb->method($fluent)->willReturnCallback(function (...$args) use ($qb) {
				return $qb;
			});
		}
		$qb->method('values')->willReturnCallback(function (array $values, ...$args) use ($qb, $state) {
			$state->values = $values;
			return $qb;
		});
		$qb->method('createNamedParameter')->willReturnCallback(function (mixed $value, ...$args) use ($state): string {
			$name = 'p' . count($state->params);
			$state->params[$name] = $value;
			return ':' . $name;
		});
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(function ($x, $y, ...$args) use ($state): string {
			$state->whereParam = $y;
			return 'eq';
		});
		$expr->method('in')->willReturnCallback(function ($x, $y, ...$args) use ($state): string {
			$state->whereParam = $y;
			return 'in';
		});
		$qb->method('expr')->willReturn($expr);
		$qb->method('executeStatement')->willReturnCallback(function () use ($state): int {
			if ($state->op === 'delete') {
				unset($this->rows[(int)$this->bound($state, $state->whereParam)]);
				$this->pause();
				return 1;
			}
			$row = [];
			foreach ($state->values as $column => $placeholder) {
				$row[$column] = $this->bound($state, $placeholder);
			}
			$fileId = (int)$row['fileid'];
			if (isset($this->rows[$fileId])) {
				throw new \OCP\DB\Exception('duplicate primary key');
			}
			$this->rows[$fileId] = [
				'etag' => (string)$row['etag'],
				'content_type' => (string)$row['content_type'],
			];
			$this->pause();
			return 1;
		});
		$qb->method('executeQuery')->willReturnCallback(function () use ($state): IResult {
			$ids = $this->bound($state, $state->whereParam);
			$matched = [];
			foreach ((array)$ids as $id) {
				$id = (int)$id;
				if (isset($this->rows[$id])) {
					$matched[] = [
						'fileid' => $id,
						'etag' => $this->rows[$id]['etag'],
						'content_type' => $this->rows[$id]['content_type'],
					];
				}
			}
			$result = $this->createMock(IResult::class);
			$index = 0;
			$result->method('fetch')->willReturnCallback(function () use ($matched, &$index) {
				return $matched[$index++] ?? false;
			});
			$result->method('closeCursor')->willReturn(true);
			return $result;
		});
		return $qb;
	}

	private function bound(object $state, mixed $placeholder): mixed {
		$name = ltrim((string)$placeholder, ':');
		return $state->params[$name];
	}
}
