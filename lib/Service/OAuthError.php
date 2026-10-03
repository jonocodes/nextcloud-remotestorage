<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Service;

use Exception;

/**
 * An OAuth error. $redirect is set when the error may be sent back to the
 * client; null when the redirect target itself can't be trusted, in which
 * case the user sees an error page instead.
 */
final class OAuthError extends Exception {
	public function __construct(
		string $code,
		public readonly ?string $redirect = null,
	) {
		parent::__construct($code);
	}
}
