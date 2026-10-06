<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Dav;

use InvalidArgumentException;
use OCA\RemoteStorage\Service\Scope;
use OCA\RemoteStorage\Service\StoragePaths;
use OCA\RemoteStorage\Service\TokenService;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
use OCP\Security\Bruteforce\MaxDelayReached;
use Sabre\DAV\Auth\Backend\BackendInterface;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * DAV auth backend, registered ahead of core's. Handles only "rs_" bearer
 * tokens and anonymous reads of public documents; everything else falls
 * through to core untouched. Logins are for this request only: nothing is
 * written to the session.
 */
class TokenAuth implements BackendInterface {
	public const THROTTLE_ACTION = 'remotestorage_token';
	private const MAX_DELAY_MS = 25000;

	public function __construct(
		private TokenService $tokens,
		private StoragePaths $paths,
		private RequestState $state,
		private IUserManager $userManager,
		private IUserSession $userSession,
		private IThrottler $throttler,
		private IRequest $request,
	) {
	}

	public function check(RequestInterface $request, ResponseInterface $response): array {
		$auth = (string)$request->getHeader('Authorization');
		$bearer = Authorization::bearer($auth);
		if ($bearer !== null) {
			return $this->checkToken($bearer);
		}
		if ($auth === '' && in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
			$match = $this->paths->match($request->getUrl());
			if ($match !== null && $match->public && !$match->folder && $match->module !== null) {
				return $this->login($match->uid, null, true) ?? [false, 'unknown user'];
			}
		}
		return [false, 'no remoteStorage credentials'];
	}

	/**
	 * Adds a Bearer challenge for a rejected remoteStorage token, next to
	 * whatever core adds, so the client learns this endpoint speaks Bearer
	 * (RFC 6750 §3). Basic-auth and anonymous requests are left to core.
	 */
	public function challenge(RequestInterface $request, ResponseInterface $response): void {
		$bearer = Authorization::bearer((string)$request->getHeader('Authorization'));
		if ($bearer !== null && str_starts_with($bearer, TokenService::PREFIX)) {
			$response->addHeader('WWW-Authenticate', 'Bearer realm="remoteStorage", error="invalid_token"');
		}
	}

	private function checkToken(string $bearer): array {
		if (!TokenService::isAppToken($bearer)) {
			return [false, 'not a remoteStorage token'];
		}
		$ip = $this->request->getRemoteAddress();
		try {
			// Blocks the IP after too many failures; otherwise only returns the delay.
			$delay = $this->throttler->sleepDelayOrThrowOnMax($ip, self::THROTTLE_ACTION);
		} catch (MaxDelayReached) {
			return [false, 'too many failed attempts'];
		}
		$token = $this->tokens->verify($bearer);
		if ($token === null) {
			$this->throttler->registerAttempt(self::THROTTLE_ACTION, $ip);
			// Sleep on failure only, like core's BruteForceMiddleware: valid tokens
			// from a shared IP are never slowed down.
			usleep(min($delay, self::MAX_DELAY_MS) * 1000);
			return [false, 'unknown remoteStorage token'];
		}
		try {
			$scope = Scope::parse($token->getScope());
		} catch (InvalidArgumentException) {
			return [false, 'stored scope is invalid'];
		}
		$result = $this->login($token->getUserId(), $scope, false);
		if ($result === null) {
			return [false, 'token owner is missing or disabled'];
		}
		$this->tokens->touch($token);
		return $result;
	}

	private function login(string $uid, ?Scope $scope, bool $publicOnly): ?array {
		$user = $this->userManager->get($uid);
		if ($user === null || !$user->isEnabled()) {
			return null;
		}
		$this->userSession->setVolatileActiveUser($user);
		\OC_Util::setupFS($user->getUID());
		$this->state->uid = $user->getUID();
		$this->state->scope = $scope;
		$this->state->publicOnly = $publicOnly;
		return [true, 'principals/users/' . $user->getUID()];
	}
}
