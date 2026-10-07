<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Controller;

use InvalidArgumentException;
use OCA\RemoteStorage\AppInfo\Application;
use OCA\RemoteStorage\Service\AuthCodeService;
use OCA\RemoteStorage\Service\Cors;
use OCA\RemoteStorage\Service\OAuthError;
use OCA\RemoteStorage\Service\OAuthRequest;
use OCA\RemoteStorage\Service\Scope;
use OCA\RemoteStorage\Service\TokenService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * The OAuth endpoints: the implicit grant's token step and the PKCE code
 * grant's consent step (authorize/approve), plus the PKCE token endpoint.
 * Authorize/approve are not public pages: Nextcloud's own login, including
 * two-factor and SSO, runs first. The token endpoint is public — it is called
 * by the app's browser fetch and authenticates by code + verifier.
 * Named "Oauth", not "OAuth": routes resolve "oauth#…" to OauthController.
 */
class OauthController extends Controller {
	public function __construct(
		IRequest $request,
		private TokenService $tokens,
		private AuthCodeService $codes,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function authorize(): Response {
		try {
			$oauth = OAuthRequest::fromParams($this->request->getParams());
		} catch (OAuthError $e) {
			return $this->fail($e);
		}
		$params = [
			'client_id' => $oauth->clientId,
			'redirect_uri' => $oauth->redirectUri,
			'scope' => (string)$oauth->scope,
			'response_type' => $oauth->responseType,
			'state' => $oauth->state ?? '',
		];
		if ($oauth->isCodeFlow()) {
			$params['code_challenge'] = (string)$oauth->codeChallenge;
			$params['code_challenge_method'] = (string)$oauth->codeChallengeMethod;
		}
		$response = new TemplateResponse(Application::APP_ID, 'authorize', [
			'origin' => $oauth->origin(),
			'action' => $this->urlGenerator->linkToRoute('remotestorage.oauth.approve'),
			'scopes' => $oauth->scope->levels(),
			'user' => $this->userSession->getUser()?->getDisplayName() ?? '',
			'params' => $params,
		], TemplateResponse::RENDER_AS_GUEST);
		// Chrome applies form-action to the redirect that follows the form post.
		$csp = new ContentSecurityPolicy();
		$csp->addAllowedFormActionDomain($oauth->origin());
		$response->setContentSecurityPolicy($csp);
		return $response;
	}

	#[NoAdminRequired]
	public function approve(string $decision = 'deny'): Response {
		try {
			$oauth = OAuthRequest::fromParams($this->request->getParams());
		} catch (OAuthError $e) {
			return $this->fail($e);
		}
		$user = $this->userSession->getUser();
		if ($decision !== 'allow' || $user === null) {
			return new RedirectResponse($oauth->errorRedirect('access_denied'));
		}
		if ($oauth->isCodeFlow()) {
			$code = $this->codes->issue(
				$user->getUID(),
				$oauth->clientId,
				$oauth->redirectUri,
				$oauth->scope,
				(string)$oauth->codeChallenge
			);
			return new RedirectResponse($oauth->codeRedirect($code));
		}
		$token = $this->tokens->issue($user->getUID(), $oauth->clientId, $oauth->scope);
		return new RedirectResponse($oauth->successRedirect($token));
	}

	private function fail(OAuthError $e): Response {
		if ($e->redirect !== null) {
			return new RedirectResponse($e->redirect);
		}
		$response = new TemplateResponse(Application::APP_ID, 'error', ['code' => $e->getMessage()],
			TemplateResponse::RENDER_AS_GUEST);
		$response->setStatus(Http::STATUS_BAD_REQUEST);
		return $response;
	}

	/**
	 * The PKCE token endpoint (RFC 6749 §3.2, spec §10.1): exchanges a one-time
	 * code plus the verifier for the app's bearer token. Public, because the app
	 * calls it with a cross-origin browser fetch and no session; the code and
	 * PKCE are what authenticate it.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function token(): JSONResponse {
		$origin = (string)$this->request->getHeader('Origin');
		// The parameters must come from a form body, never the query string
		// (which would leak the code and verifier into logs).
		$contentType = strtolower((string)$this->request->getHeader('Content-Type'));
		if (!str_starts_with($contentType, 'application/x-www-form-urlencoded')) {
			return self::cors(self::oauthError('invalid_request'), $origin);
		}
		$grantType = (string)$this->request->getParam('grant_type', '');
		if ($grantType === '') {
			return self::cors(self::oauthError('invalid_request'), $origin);
		}
		if ($grantType !== 'authorization_code') {
			return self::cors(self::oauthError('unsupported_grant_type'), $origin);
		}

		$code = (string)$this->request->getParam('code', '');
		$clientId = (string)$this->request->getParam('client_id', '');
		$redirectUri = (string)$this->request->getParam('redirect_uri', '');
		$verifier = (string)$this->request->getParam('code_verifier', '');
		if ($code === '' || $clientId === '' || $redirectUri === '' || $verifier === '') {
			return self::cors(self::oauthError('invalid_request'), $origin);
		}

		$authCode = $this->codes->redeem($code, $clientId, $redirectUri, $verifier);
		if ($authCode === null) {
			return self::cors(self::oauthError('invalid_grant'), $origin);
		}
		try {
			$scope = Scope::parse($authCode->getScope());
		} catch (InvalidArgumentException) {
			return self::cors(self::oauthError('invalid_grant'), $origin);
		}
		$response = new JSONResponse([
			'access_token' => $this->tokens->issue($authCode->getUserId(), $clientId, $scope),
			'token_type' => 'bearer',
			'scope' => (string)$scope,
		]);
		// RFC 6749 §5.1: token responses must not be cached.
		$response->addHeader('Cache-Control', 'no-store');
		$response->addHeader('Pragma', 'no-cache');
		return self::cors($response, $origin);
	}

	/** CORS preflight for the token endpoint (a POST form is usually already simple). */
	#[PublicPage]
	#[NoCSRFRequired]
	public function tokenOptions(): Response {
		$response = new Response();
		$response->setStatus(Http::STATUS_NO_CONTENT);
		$response->setHeaders(Cors::preflight((string)$this->request->getHeader('Origin')));
		return $response;
	}

	/** The origin is echoed, never with credentials, so the app may read the reply. */
	private static function cors(JSONResponse $response, string $origin): JSONResponse {
		foreach (Cors::headers($origin) as $name => $value) {
			$response->addHeader($name, $value);
		}
		return $response;
	}

	private static function oauthError(string $error): JSONResponse {
		return new JSONResponse(['error' => $error], Http::STATUS_BAD_REQUEST);
	}
}
