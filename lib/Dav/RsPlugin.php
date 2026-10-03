<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Dav;

use OCA\DAV\Connector\Sabre\File;
use OCA\DAV\Connector\Sabre\Node;
use OCA\RemoteStorage\Service\AccessPolicy;
use OCA\RemoteStorage\Service\Listing;
use OCA\RemoteStorage\Service\StorageMatch;
use OCA\RemoteStorage\Service\StoragePaths;
use Sabre\DAV\Exception\Conflict;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\MethodNotAllowed;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\ICollection;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use Sabre\HTTP\Sapi;

/**
 * Fills the gaps between Nextcloud's WebDAV and the remoteStorage protocol.
 * CORS applies under the storage root; everything else only to requests
 * TokenAuth logged in. File operations, ETags and conditional requests stay
 * with Nextcloud's WebDAV.
 */
class RsPlugin extends ServerPlugin {
	private const METHODS = 'GET, HEAD, PUT, DELETE, OPTIONS';
	private const ALLOW_HEADERS = 'Authorization, Content-Type, Content-Length, If-Match, If-None-Match, Origin, Range';
	private const EXPOSE_HEADERS = 'ETag, Content-Type, Content-Length, Content-Range, Last-Modified';

	private Server $server;

	public function __construct(
		private StoragePaths $paths,
		private RequestState $state,
	) {
	}

	public function getPluginName(): string {
		return 'remotestorage';
	}

	public function initialize(Server $server): void {
		$this->server = $server;
		// Before core's AnonymousOptionsPlugin (9) and the auth plugin (10).
		$server->on('beforeMethod:*', [$this, 'cors'], 5);
		// After auth (10).
		$server->on('beforeMethod:*', [$this, 'enforce'], 20);
		// Before Sabre checks preconditions and handles the PUT.
		$server->on('beforeMethod:PUT', [$this, 'createParents'], 30);
		// Before Sabre's CorePlugin GET (100).
		$server->on('method:GET', [$this, 'folderGet'], 50);
		$server->on('afterMethod:DELETE', [$this, 'pruneParents'], 50);
	}

	public function cors(RequestInterface $request, ResponseInterface $response): ?bool {
		if (empty($request->getHeader('Origin')) || $this->paths->match($request->getUrl()) === null
			|| $response->hasHeader('Access-Control-Allow-Origin')) {
			return null;
		}
		// Preflights carry no credentials. Actual requests: only bearer-token or
		// anonymous ones (a bad token's 401 must be readable); Basic-auth
		// requests keep core's behaviour exactly.
		$auth = (string)$request->getHeader('Authorization');
		if ($auth !== '' && !str_starts_with($auth, 'Bearer ')) {
			return null;
		}
		// "*", never a reflected origin: browsers then never send cookies.
		$response->setHeader('Access-Control-Allow-Origin', '*');
		$response->setHeader('Access-Control-Expose-Headers', self::EXPOSE_HEADERS);
		if ($request->getMethod() === 'OPTIONS' && $auth === '') {
			$response->setHeader('Access-Control-Allow-Methods', self::METHODS);
			$response->setHeader('Access-Control-Allow-Headers', self::ALLOW_HEADERS);
			$response->setHeader('Access-Control-Max-Age', '600');
			$response->setStatus(204);
			Sapi::sendResponse($response);
			return false;
		}
		return null;
	}

	public function enforce(RequestInterface $request, ResponseInterface $response): void {
		if (!$this->state->isRemoteStorageLogin()) {
			return;
		}
		$match = $this->paths->match($request->getUrl());
		if ($match !== null && $match->uid !== $this->state->uid) {
			$match = null;
		}
		$decision = AccessPolicy::decide($request->getMethod(), $match, $this->state->scope, $this->state->publicOnly);
		if ($decision === AccessPolicy::METHOD_NOT_ALLOWED) {
			throw new MethodNotAllowed($request->getMethod() . ' is not allowed here by remoteStorage');
		}
		if ($decision !== AccessPolicy::ALLOW) {
			throw new Forbidden('outside the scope of this remoteStorage token');
		}
	}

	/**
	 * remoteStorage PUT creates missing parent folders. Skipped for If-Match
	 * requests: those name an existing document, and a failed precondition
	 * must not leave empty folders behind.
	 */
	public function createParents(RequestInterface $request, ResponseInterface $response): void {
		$match = $this->ownMatch($request);
		if ($match === null || $request->getHeader('If-Match') !== null) {
			return;
		}
		$tree = $this->server->tree;
		foreach ($this->paths->parentDavPaths($match) as $path) {
			if ($tree->nodeExists($path)) {
				if (!$tree->getNodeForPath($path) instanceof ICollection) {
					throw new Conflict('a parent of this document is a document');
				}
				continue;
			}
			$parent = $tree->getNodeForPath(dirname($path));
			if (!$parent instanceof ICollection) {
				throw new Conflict('a parent of this document is a document');
			}
			$parent->createDirectory(basename($path));
		}
	}

	public function folderGet(RequestInterface $request, ResponseInterface $response): ?bool {
		if (!$this->state->isRemoteStorageLogin()) {
			return null;
		}
		$match = $this->ownMatch($request);
		$node = $this->server->tree->getNodeForPath($request->getPath());
		if (!$node instanceof ICollection) {
			if ($match !== null && $match->folder) {
				throw new NotFound('not a folder');
			}
			return null;
		}
		// A path without a trailing slash names a document, never a folder.
		if ($match === null || !$match->folder) {
			throw new NotFound('no document at this path');
		}
		// Public-only logins never see listings (TokenAuth should not have let one through).
		if ($this->state->publicOnly) {
			throw new Forbidden('public folder listings need a token');
		}

		$etag = $node instanceof Node ? $node->getETag() : null;
		if ($etag !== null) {
			$response->setHeader('ETag', $etag);
			if (self::matchesETag((string)$request->getHeader('If-None-Match'), $etag)) {
				$response->setStatus(304);
				return false;
			}
		}
		$listing = new Listing();
		foreach ($node->getChildren() as $child) {
			if ($child instanceof ICollection && $child instanceof Node) {
				$listing->addFolder($child->getName(), $child->getETag());
			} elseif ($child instanceof File) {
				$listing->addDocument(
					$child->getName(),
					$child->getETag(),
					(string)$child->getContentType(),
					(int)$child->getSize(),
					(int)$child->getLastModified()
				);
			}
		}
		$response->setStatus(200);
		$response->setHeader('Content-Type', 'application/ld+json');
		$response->setHeader('Cache-Control', 'no-cache');
		$response->setBody($listing->toJson());
		return false;
	}

	/** remoteStorage DELETE removes parent folders left empty, up to (not including) the root. */
	public function pruneParents(RequestInterface $request, ResponseInterface $response): void {
		$match = $this->ownMatch($request);
		if ($match === null || $response->getStatus() >= 300) {
			return;
		}
		$tree = $this->server->tree;
		$parents = array_slice($this->paths->parentDavPaths($match), 1);
		foreach (array_reverse($parents) as $path) {
			if (!$tree->nodeExists($path)) {
				continue;
			}
			$node = $tree->getNodeForPath($path);
			if (!$node instanceof ICollection || $node->getChildren() !== []) {
				return;
			}
			$tree->delete($path);
		}
	}

	private function ownMatch(RequestInterface $request): ?StorageMatch {
		if (!$this->state->isRemoteStorageLogin()) {
			return null;
		}
		$match = $this->paths->match($request->getUrl());
		return $match !== null && $match->uid === $this->state->uid ? $match : null;
	}

	private static function matchesETag(string $ifNoneMatch, string $etag): bool {
		if ($ifNoneMatch === '') {
			return false;
		}
		$bare = Listing::bareETag($etag);
		foreach (explode(',', $ifNoneMatch) as $candidate) {
			$candidate = trim($candidate);
			if ($candidate === '*' || Listing::bareETag($candidate) === $bare) {
				return true;
			}
		}
		return false;
	}
}
