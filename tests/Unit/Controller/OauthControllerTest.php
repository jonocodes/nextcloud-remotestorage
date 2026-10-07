<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Tests\Unit\Controller;

use OCA\RemoteStorage\Controller\OauthController;
use OCA\RemoteStorage\Service\AuthCodeService;
use OCA\RemoteStorage\Service\Scope;
use OCA\RemoteStorage\Service\TokenService;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class OauthControllerTest extends TestCase {
	private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

	private static function params(): array {
		return [
			'client_id' => 'https://app.example',
			'redirect_uri' => 'https://app.example/callback',
			'scope' => 'notes:rw',
			'response_type' => 'code',
			'code_challenge' => self::CHALLENGE,
			'code_challenge_method' => 'S256',
			'state' => 'xyz',
		];
	}

	private function controller(array $params, IUser $user, TokenService $tokens, AuthCodeService $codes): OauthController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		return new OauthController($request, $tokens, $codes, $session, $this->createMock(IURLGenerator::class));
	}

	private function user(): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		return $user;
	}

	public function testApproveIssuesACodeForTheCodeFlow(): void {
		$tokens = $this->createMock(TokenService::class);
		$tokens->expects($this->never())->method('issue');
		$codes = $this->createMock(AuthCodeService::class);
		$codes->expects($this->once())->method('issue')
			->with('alice', 'https://app.example', 'https://app.example/callback', $this->isInstanceOf(Scope::class), self::CHALLENGE)
			->willReturn('code123');

		$response = $this->controller(self::params(), $this->user(), $tokens, $codes)->approve('allow');

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame('https://app.example/callback?code=code123&state=xyz', $response->getRedirectURL());
	}

	public function testApproveIssuesATokenForTheImplicitFlow(): void {
		$params = self::params();
		$params['response_type'] = 'token';
		unset($params['code_challenge'], $params['code_challenge_method']);

		$tokens = $this->createMock(TokenService::class);
		$tokens->expects($this->once())->method('issue')->willReturn('rs_abc');
		$codes = $this->createMock(AuthCodeService::class);
		$codes->expects($this->never())->method('issue');

		$response = $this->controller($params, $this->user(), $tokens, $codes)->approve('allow');

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame(
			'https://app.example/callback#access_token=rs_abc&token_type=bearer&scope=notes%3Arw&state=xyz',
			$response->getRedirectURL()
		);
	}

	public function testDenyRedirectsWithAccessDenied(): void {
		$response = $this->controller(
			self::params(),
			$this->user(),
			$this->createMock(TokenService::class),
			$this->createMock(AuthCodeService::class)
		)->approve('deny');

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame('https://app.example/callback?error=access_denied&state=xyz', $response->getRedirectURL());
	}
}
