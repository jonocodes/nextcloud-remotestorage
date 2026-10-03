<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Dav;

use OCA\RemoteStorage\Service\Scope;

/** What TokenAuth decided about the current request, for RsPlugin. One per request. */
final class RequestState {
	public ?string $uid = null;
	public ?Scope $scope = null;
	/** Anonymous read of a public document, logged in as its owner for this request only. */
	public bool $publicOnly = false;

	public function isRemoteStorageLogin(): bool {
		return $this->uid !== null;
	}
}
