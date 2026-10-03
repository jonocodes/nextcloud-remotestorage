<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Service;

/** Decides whether an authenticated remoteStorage request may proceed. */
final class AccessPolicy {
	public const ALLOW = 'allow';
	public const FORBIDDEN = 'forbidden';
	public const METHOD_NOT_ALLOWED = 'method-not-allowed';

	public static function decide(string $method, ?StorageMatch $match, ?Scope $scope, bool $publicOnly): string {
		if ($match === null) {
			return self::FORBIDDEN;
		}
		$read = in_array($method, ['GET', 'HEAD'], true);
		$write = in_array($method, ['PUT', 'DELETE'], true);
		if (!$read && !$write) {
			return self::METHOD_NOT_ALLOWED;
		}
		if ($write && $match->folder) {
			return self::METHOD_NOT_ALLOWED;
		}
		if ($publicOnly) {
			return $read && $match->public && !$match->folder && $match->module !== null
				? self::ALLOW : self::FORBIDDEN;
		}
		if ($scope === null) {
			return self::FORBIDDEN;
		}
		$allowed = $match->module === null ? $scope->allowsRoot($write) : $scope->allows($match->module, $write);
		return $allowed ? self::ALLOW : self::FORBIDDEN;
	}
}
