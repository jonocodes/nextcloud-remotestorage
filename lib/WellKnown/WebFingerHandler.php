<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\WellKnown;

use OCA\RemoteStorage\Service\StoragePaths;
use OCP\Http\WellKnown\IHandler;
use OCP\Http\WellKnown\IRequestContext;
use OCP\Http\WellKnown\IResponse;
use OCP\Http\WellKnown\JrdResponse;
use OCP\IURLGenerator;
use OCP\IUserManager;

/** Answers acct:<user>@<this host> with the user's remoteStorage link. */
class WebFingerHandler implements IHandler {
	public const REL = 'http://tools.ietf.org/id/draft-dejong-remotestorage';
	public const SPEC_VERSION = 'draft-dejong-remotestorage-22';

	public function __construct(
		private IUserManager $userManager,
		private IURLGenerator $urlGenerator,
		private StoragePaths $paths,
	) {
	}

	public function handle(string $service, IRequestContext $context, ?IResponse $previousResponse): ?IResponse {
		if ($service !== 'webfinger') {
			return $previousResponse;
		}
		$request = $context->getHttpRequest();
		$resource = (string)$request->getParam('resource', '');
		// The host follows the last "@": user ids may themselves contain one.
		if (!preg_match('/^acct:(.+)@([^@]+)$/', $resource, $m)
			|| strcasecmp($m[2], $request->getServerHost()) !== 0) {
			return $previousResponse;
		}
		$user = $this->userManager->get(rawurldecode($m[1]));
		if ($user === null || !$user->isEnabled()) {
			return $previousResponse;
		}

		$jrd = match (true) {
			$previousResponse instanceof CorsJrdResponse => $previousResponse->jrd,
			$previousResponse instanceof JrdResponse => $previousResponse,
			default => new JrdResponse($resource),
		};
		$authorizeUrl = $this->urlGenerator->linkToRouteAbsolute('remotestorage.oauth.authorize');
		$jrd->addLink(self::REL, null, $this->urlGenerator->getAbsoluteURL($this->paths->storagePath($user->getUID())), [], [
			'http://remotestorage.io/spec/version' => self::SPEC_VERSION,
			'http://tools.ietf.org/html/rfc6749#section-4.2' => $authorizeUrl,
			// Spec §10.1: advertising these lets a client use the code + PKCE flow.
			'http://tools.ietf.org/html/rfc6749#section-3.1' => $authorizeUrl,
			'http://tools.ietf.org/html/rfc6749#section-3.2' =>
				$this->urlGenerator->linkToRouteAbsolute('remotestorage.oauth.token'),
			'http://tools.ietf.org/html/rfc7636' => 'S256',
			'http://tools.ietf.org/html/rfc7233' => 'GET',
		]);
		return new CorsJrdResponse($jrd);
	}
}
