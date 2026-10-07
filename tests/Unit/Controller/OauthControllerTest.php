<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Tests\Unit\Controller;

use OCA\RemoteStorage\Controller\OauthController;
use OCA\RemoteStorage\Db\AuthCode;
use OCA\RemoteStorage\Service\AuthCodeService;
use OCA\RemoteStorage\Service\Scope;
use OCA\RemoteStorage\Service\TokenService;
use OCP\AppFramework\Http;
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

	private function tokenController(array $params, string $origin, AuthCodeService $codes, TokenService $tokens, string $contentType = 'application/x-www-form-urlencoded'): OauthController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $key, $default = null) => $params[$key] ?? $default
		);
		$request->method('getHeader')->willReturnCallback(
			static fn (string $name): string => match (strtolower($name)) {
				'origin' => $origin,
				'content-type' => $contentType,
				default => '',
			}
		);
		return new OauthController(
			$request,
			$tokens,
			$codes,
			$this->createMock(IUserSession::class),
			$this->createMock(IURLGenerator::class)
		);
	}

	private static function tokenParams(): array {
		return [
			'grant_type' => 'authorization_code',
			'code' => 'the-code',
			'client_id' => 'https://app.example',
			'redirect_uri' => 'https://app.example/callback',
			'code_verifier' => 'the-verifier',
		];
	}

	private function storedCode(): AuthCode {
		$code = new AuthCode();
		$code->setUserId('alice');
		$code->setScope('notes:rw');
		return $code;
	}

	public function testTokenExchangesACodeForAToken(): void {
		$codes = $this->createMock(AuthCodeService::class);
		$codes->expects($this->once())->method('redeem')
			->with('the-code', 'https://app.example', 'https://app.example/callback', 'the-verifier')
			->willReturn($this->storedCode());
		$tokens = $this->createMock(TokenService::class);
		$tokens->expects($this->once())->method('issue')
			->with('alice', 'https://app.example', $this->isInstanceOf(Scope::class))
			->willReturn('rs_abc');

		$response = $this->tokenController(self::tokenParams(), 'https://app.example', $codes, $tokens)->token();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['access_token' => 'rs_abc', 'token_type' => 'bearer', 'scope' => 'notes:rw'],
			$response->getData()
		);
	}

	public function testTokenRejectsAnUnsupportedGrantType(): void {
		$response = $this->tokenController(
			['grant_type' => 'password'],
			'https://app.example',
			$this->createMock(AuthCodeService::class),
			$this->createMock(TokenService::class)
		)->token();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'unsupported_grant_type'], $response->getData());
	}

	public function testTokenRejectsANonCodeGrantTypeWithInvalidRequest(): void {
		$params = self::tokenParams();
		$params['grant_type'] = '';
		$response = $this->tokenController($params, 'https://app.example', $this->createMock(AuthCodeService::class), $this->createMock(TokenService::class))->token();

		$this->assertSame(['error' => 'invalid_request'], $response->getData());
	}

	public function testTokenRejectsANonFormContentType(): void {
		$response = $this->tokenController(
			self::tokenParams(),
			'https://app.example',
			$this->createMock(AuthCodeService::class),
			$this->createMock(TokenService::class),
			'application/json'
		)->token();

		$this->assertSame(['error' => 'invalid_request'], $response->getData());
	}

	public function testTokenRejectsAMissingParameter(): void {
		$params = self::tokenParams();
		unset($params['code']);
		$response = $this->tokenController($params, 'https://app.example', $this->createMock(AuthCodeService::class), $this->createMock(TokenService::class))->token();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'invalid_request'], $response->getData());
	}

	public function testTokenRejectsAnInvalidGrant(): void {
		$codes = $this->createMock(AuthCodeService::class);
		$codes->method('redeem')->willReturn(null);
		$response = $this->tokenController(self::tokenParams(), 'https://app.example', $codes, $this->createMock(TokenService::class))->token();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'invalid_grant'], $response->getData());
	}

	public function testTokenOptionsAnswersThePreflight(): void {
		$response = $this->tokenController([], 'https://app.example', $this->createMock(AuthCodeService::class), $this->createMock(TokenService::class))->tokenOptions();

		$this->assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
	}
}
