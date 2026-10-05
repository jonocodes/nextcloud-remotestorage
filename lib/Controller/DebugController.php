<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Controller;

use InvalidArgumentException;
use OCA\RemoteStorage\AppInfo\Application;
use OCA\RemoteStorage\Dav\RsPlugin;
use OCA\RemoteStorage\Service\AccessPolicy;
use OCA\RemoteStorage\Service\Scope;
use OCA\RemoteStorage\Service\StoragePaths;
use OCA\RemoteStorage\WellKnown\WebFingerHandler;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Read-only introspection for admins: the effective configuration, and a dry
 * run of the access decision for a hypothetical request. Admin-only by the
 * framework default (no NoAdminRequired). Never touches tokens or files.
 */
class DebugController extends Controller {
	public function __construct(
		IRequest $request,
		private StoragePaths $paths,
		private IAppManager $appManager,
	) {
		parent::__construct(Application::APP_ID, $request);
	}

	#[NoCSRFRequired]
	public function config(): JSONResponse {
		$nextcloud = $this->appManager->getAppInfo(Application::APP_ID)['dependencies']['nextcloud']['@attributes'] ?? [];
		return new JSONResponse([
			'app' => Application::APP_ID,
			'version' => $this->appManager->getAppVersion(Application::APP_ID),
			'storage_root' => $this->paths->root(),
			'default_storage_root' => Application::DEFAULT_ROOT,
			'spec_version' => WebFingerHandler::SPEC_VERSION,
			'webfinger_rel' => WebFingerHandler::REL,
			'cors' => [
				'allow_methods' => explode(', ', RsPlugin::METHODS),
				'allow_headers' => explode(', ', RsPlugin::ALLOW_HEADERS),
				'expose_headers' => explode(', ', RsPlugin::EXPOSE_HEADERS),
			],
			'nextcloud' => [
				'min_version' => $nextcloud['min-version'] ?? null,
				'max_version' => $nextcloud['max-version'] ?? null,
			],
		]);
	}

	/**
	 * @param string $path the request path or URL as a client would send it,
	 *                     e.g. /remote.php/dav/files/alice/remoteStorage/notes/a.txt
	 * @param string $scope the token's scope; empty means an anonymous request
	 */
	#[NoCSRFRequired]
	public function explain(string $method = 'GET', string $path = '', string $scope = ''): JSONResponse {
		if ($path === '') {
			return self::badRequest('path is required');
		}
		$method = strtoupper(trim($method));
		$parsed = null;
		if (trim($scope) !== '') {
			try {
				$parsed = Scope::parse($scope);
			} catch (InvalidArgumentException $e) {
				return self::badRequest('scope: ' . $e->getMessage());
			}
		}
		$publicOnly = $parsed === null;
		$match = $this->paths->match($path);
		return new JSONResponse([
			'input' => ['method' => $method, 'path' => $path, 'scope' => $parsed === null ? null : (string)$parsed],
			'storage_root' => $this->paths->root(),
			'match' => $match === null ? null : [
				'uid' => $match->uid,
				'rel' => $match->rel,
				'module' => $match->module,
				'folder' => $match->folder,
				'public' => $match->public,
			],
			'scope' => $parsed?->levels(),
			'public_only' => $publicOnly,
			...AccessPolicy::explain($method, $match, $parsed, $publicOnly),
		]);
	}

	private static function badRequest(string $error): JSONResponse {
		return new JSONResponse(['error' => $error], Http::STATUS_BAD_REQUEST);
	}
}
