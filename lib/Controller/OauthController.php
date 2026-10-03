<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Controller;

use OCA\RemoteStorage\AppInfo\Application;
use OCA\RemoteStorage\Service\OAuthError;
use OCA\RemoteStorage\Service\OAuthRequest;
use OCA\RemoteStorage\Service\TokenService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * The OAuth dialog (implicit grant). Not a public page: Nextcloud's own
 * login, including two-factor and SSO, runs first.
 * Named "Oauth", not "OAuth": routes resolve "oauth#…" to OauthController.
 */
class OauthController extends Controller {
	public function __construct(
		IRequest $request,
		private TokenService $tokens,
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
		$response = new TemplateResponse(Application::APP_ID, 'authorize', [
			'origin' => $oauth->origin(),
			'action' => $this->urlGenerator->linkToRoute('remotestorage.oauth.approve'),
			'scopes' => $oauth->scope->levels(),
			'user' => $this->userSession->getUser()?->getDisplayName() ?? '',
			'params' => [
				'client_id' => $oauth->clientId,
				'redirect_uri' => $oauth->redirectUri,
				'scope' => (string)$oauth->scope,
				'response_type' => 'token',
				'state' => $oauth->state ?? '',
			],
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
}
