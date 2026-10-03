<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\WellKnown;

use OCP\AppFramework\Http\Response;
use OCP\Http\WellKnown\IResponse;
use OCP\Http\WellKnown\JrdResponse;

/** WebFinger must be readable cross-origin (RFC 7033 §5); JrdResponse sets no CORS. */
class CorsJrdResponse implements IResponse {
	public function __construct(
		public readonly JrdResponse $jrd,
	) {
	}

	public function toHttpResponse(): Response {
		$response = $this->jrd->toHttpResponse();
		$response->addHeader('Access-Control-Allow-Origin', '*');
		return $response;
	}
}
