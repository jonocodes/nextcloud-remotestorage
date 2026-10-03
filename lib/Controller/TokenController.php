<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Controller;

use OCA\RemoteStorage\AppInfo\Application;
use OCA\RemoteStorage\Service\TokenService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

class TokenController extends Controller {
	public function __construct(
		IRequest $request,
		private TokenService $tokens,
		private IUserSession $userSession,
		private IURLGenerator $urlGenerator,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoAdminRequired]
	public function revoke(int $id): RedirectResponse {
		$user = $this->userSession->getUser();
		if ($user !== null) {
			$this->tokens->revoke($user->getUID(), $id);
		}
		return new RedirectResponse(
			$this->urlGenerator->linkToRoute('settings.PersonalSettings.index', ['section' => 'security']) . '#remotestorage'
		);
	}
}
