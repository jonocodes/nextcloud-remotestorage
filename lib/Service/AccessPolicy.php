<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Service;

/** Decides whether an authenticated remoteStorage request may proceed. */
final class AccessPolicy {
	public const ALLOW = 'allow';
	public const FORBIDDEN = 'forbidden';
	public const METHOD_NOT_ALLOWED = 'method-not-allowed';

	public static function decide(string $method, ?StorageMatch $match, ?Scope $scope, bool $publicOnly): string {
		return self::explain($method, $match, $scope, $publicOnly)['decision'];
	}

	/**
	 * The decision and a one-line reason for it, in the words of the protocol.
	 *
	 * @return array{decision: string, reason: string}
	 */
	public static function explain(string $method, ?StorageMatch $match, ?Scope $scope, bool $publicOnly): array {
		if ($match === null) {
			return self::result(self::FORBIDDEN, "the path is not under the storage root of the token's user");
		}
		$read = in_array($method, ['GET', 'HEAD'], true);
		$write = in_array($method, ['PUT', 'DELETE'], true);
		if (!$read && !$write) {
			return self::result(self::METHOD_NOT_ALLOWED, "$method is not a remoteStorage method (GET, HEAD, PUT, DELETE)");
		}
		if ($write && $match->folder) {
			return self::result(self::METHOD_NOT_ALLOWED, 'PUT and DELETE apply to documents, not folders');
		}
		if ($publicOnly) {
			return self::explainAnonymous($write, $match);
		}
		if ($scope === null) {
			return self::result(self::FORBIDDEN, 'the login carries no scope');
		}
		$item = $scope->itemFor($match->module);
		if ($item === null) {
			return self::result(self::FORBIDDEN, $match->module === null
				? "only a '*' scope covers the storage root and /public/ themselves"
				: "no scope item covers the module '{$match->module}'");
		}
		if ($write && !str_ends_with($item, ':rw')) {
			$module = substr($item, 0, -2);
			return self::result(self::FORBIDDEN, "'$item' allows reads only; writing needs '$module:rw'");
		}
		return self::result(self::ALLOW, "'$item' covers this path");
	}

	/** @return array{decision: string, reason: string} */
	private static function explainAnonymous(bool $write, StorageMatch $match): array {
		if ($write) {
			return self::result(self::FORBIDDEN, 'anonymous requests may only read');
		}
		if (!$match->public || $match->module === null) {
			return self::result(self::FORBIDDEN, 'without a token only documents under /public/<module>/ are readable');
		}
		if ($match->folder) {
			return self::result(self::FORBIDDEN, 'anonymous requests may not list folders');
		}
		return self::result(self::ALLOW, 'anonymous requests may read public documents');
	}

	/** @return array{decision: string, reason: string} */
	private static function result(string $decision, string $reason): array {
		return ['decision' => $decision, 'reason' => $reason];
	}
}
