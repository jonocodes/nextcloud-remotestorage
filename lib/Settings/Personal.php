<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Settings;

use OCA\RemoteStorage\AppInfo\Application;
use OCA\RemoteStorage\Service\TokenService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use OCP\Settings\ISettings;

/** Personal settings → Security: the user's remoteStorage address and connected apps. */
class Personal implements ISettings {
	public function __construct(
		private TokenService $tokens,
		private IUserSession $userSession,
		private IRequest $request,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getForm(): TemplateResponse {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		return new TemplateResponse(Application::APP_ID, 'settings-personal', [
			'address' => $uid . '@' . $this->request->getServerHost(),
			'tokens' => array_map(fn ($token): array => [
				'token' => $token,
				'revokeUrl' => $this->urlGenerator->linkToRoute('remotestorage.token.revoke', ['id' => $token->getId()]),
			], $this->tokens->listFor($uid)),
		], '');
	}

	public function getSection(): string {
		return 'security';
	}

	public function getPriority(): int {
		return 80;
	}
}
