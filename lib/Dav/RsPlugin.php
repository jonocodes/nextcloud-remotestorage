<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Dav;

use OCA\DAV\Connector\Sabre\File;
use OCA\DAV\Connector\Sabre\Node;
use OCA\RemoteStorage\Service\AccessPolicy;
use OCA\RemoteStorage\Service\ContentTypeService;
use OCA\RemoteStorage\Service\ETagHeader;
use OCA\RemoteStorage\Service\Listing;
use OCA\RemoteStorage\Service\StorageMatch;
use OCA\RemoteStorage\Service\StoragePaths;
use Sabre\DAV\Exception\Conflict;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Exception\MethodNotAllowed;
use Sabre\DAV\Exception\NotFound;
use Sabre\DAV\ICollection;
use Sabre\DAV\INode;
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
	public const METHODS = 'GET, HEAD, PUT, DELETE, OPTIONS';
	public const ALLOW_HEADERS = 'Authorization, Content-Type, Content-Length, If-Match, If-None-Match, Origin, Range';
	public const EXPOSE_HEADERS = 'ETag, Content-Type, Content-Length, Content-Range, Last-Modified';

	private Server $server;
	/** ETag of the document before this PUT, to detect writes Nextcloud gives no new ETag. */
	private ?string $etagBeforePut = null;
	/** ETag of the document before this DELETE, returned on the response (spec >= 2). */
	private ?string $etagBeforeDelete = null;
	/** Set when folderGet answered for a folder that does not exist (see finishMissingFolder). */
	private bool $answeredMissingFolder = false;

	public function __construct(
		private StoragePaths $paths,
		private RequestState $state,
		private ContentTypeService $contentTypes,
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
		$server->on('beforeMethod:*', [$this, 'prepare'], 25);
		// Before Sabre checks preconditions and handles the PUT.
		$server->on('beforeMethod:PUT', [$this, 'createParents'], 30);
		$server->on('beforeMethod:PUT', [$this, 'rememberETag'], 31);
		$server->on('beforeMethod:DELETE', [$this, 'rememberDeleteETag'], 31);
		// Before Sabre's CorePlugin GET (100).
		$server->on('method:GET', [$this, 'folderGet'], 50);
		// Before other apps' afterMethod:GET hooks (default 100).
		$server->on('afterMethod:GET', [$this, 'finishMissingFolder'], 1);
		$server->on('afterMethod:GET', [$this, 'documentContentType'], 50);
		$server->on('afterMethod:PUT', [$this, 'afterPut'], 50);
		$server->on('afterMethod:DELETE', [$this, 'afterDelete'], 50);
	}

	public function cors(RequestInterface $request, ResponseInterface $response): ?bool {
		$origin = (string)$request->getHeader('Origin');
		if ($origin === '' || $this->paths->match($request->getUrl()) === null
			|| $response->hasHeader('Access-Control-Allow-Origin')) {
			return null;
		}
		// Only bearer-token or anonymous requests (a bad token's 401 must be
		// readable); Basic-auth requests keep core's behaviour exactly.
		$auth = (string)$request->getHeader('Authorization');
		if ($auth !== '' && !str_starts_with($auth, 'Bearer ')) {
			return null;
		}
		// The origin is echoed, never with Access-Control-Allow-Credentials,
		// so browsers never send cookies with these requests.
		$response->setHeader('Access-Control-Allow-Origin', $origin);
		$response->addHeader('Vary', 'Origin');
		$response->setHeader('Access-Control-Expose-Headers', self::EXPOSE_HEADERS);
		// A preflight is answered before any authentication, even if it
		// carries a token (browsers never send one; some clients do).
		if ($request->getMethod() === 'OPTIONS') {
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
	 * Undoes what response compression does to ETags (see ETagHeader), and on
	 * Apache with mod_php asks it not to compress remoteStorage responses at all.
	 */
	public function prepare(RequestInterface $request, ResponseInterface $response): void {
		if (!$this->state->isRemoteStorageLogin()) {
			return;
		}
		foreach (['If-Match', 'If-None-Match'] as $name) {
			$value = $request->getHeader($name);
			if ($value !== null) {
				$request->setHeader($name, ETagHeader::normalize($value));
			}
		}
		if (in_array($request->getMethod(), ['GET', 'HEAD'], true) && function_exists('apache_setenv')) {
			apache_setenv('no-gzip', '1');
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

	public function rememberETag(RequestInterface $request, ResponseInterface $response): void {
		$this->etagBeforePut = null;
		if ($this->ownMatch($request) !== null && $this->server->tree->nodeExists($request->getPath())) {
			$node = $this->server->tree->getNodeForPath($request->getPath());
			$this->etagBeforePut = $node instanceof Node ? $node->getETag() : null;
		}
	}

	public function rememberDeleteETag(RequestInterface $request, ResponseInterface $response): void {
		$this->etagBeforeDelete = null;
		if ($this->ownMatch($request) !== null && $this->server->tree->nodeExists($request->getPath())) {
			$node = $this->server->tree->getNodeForPath($request->getPath());
			$this->etagBeforeDelete = $node instanceof Node ? $node->getETag() : null;
		}
	}

	public function folderGet(RequestInterface $request, ResponseInterface $response): ?bool {
		if (!$this->state->isRemoteStorageLogin()) {
			return null;
		}
		$match = $this->ownMatch($request);
		$tree = $this->server->tree;
		$node = $tree->nodeExists($request->getPath()) ? $tree->getNodeForPath($request->getPath()) : null;
		if ($node !== null && !$node instanceof ICollection) {
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
		// Missing and empty folders both list as empty (spec: GET on an empty
		// folder SHOULD return a description with no items).
		$listing = new Listing();
		$documents = [];
		foreach ($node === null ? [] : $node->getChildren() as $child) {
			if ($child instanceof ICollection && $child instanceof Node) {
				// An empty folder MUST NOT be listed in its parent.
				if (self::containsDocument($child)) {
					$listing->addFolder($child->getName(), $child->getETag());
				}
			} elseif ($child instanceof File) {
				$documents[] = $child;
			}
		}
		$types = $this->contentTypes->forNodes(array_combine(
			array_map(static fn (File $f): int => (int)$f->getId(), $documents),
			array_map(static fn (File $f): string => $f->getETag(), $documents)
		) ?: []);
		foreach ($documents as $document) {
			$listing->addDocument(
				$document->getName(),
				$document->getETag(),
				$types[(int)$document->getId()] ?? (string)$document->getContentType(),
				(int)$document->getSize(),
				(int)$document->getLastModified()
			);
		}
		$this->answeredMissingFolder = $node === null;
		$response->setStatus(200);
		$response->setHeader('Content-Type', 'application/ld+json');
		$response->setHeader('Cache-Control', 'no-cache');
		$response->setBody($listing->toJson());
		return false;
	}

	/**
	 * A missing folder lists as empty, but other apps' afterMethod:GET hooks
	 * (Files_Trashbin's TrashbinPlugin) look the path up without handling
	 * NotFound and would turn the answer into a 404. Send it now and stop there,
	 * as for preflights.
	 */
	public function finishMissingFolder(RequestInterface $request, ResponseInterface $response): ?bool {
		// Real GETs only: HEAD runs GET as a sub-request whose response Sabre sends itself.
		if (!$this->answeredMissingFolder || $this->server->httpRequest->getMethod() !== 'GET') {
			return null;
		}
		Sapi::sendResponse($response);
		return false;
	}

	/** GET/HEAD of a document: the Content-Type it was PUT with, and no caching. */
	public function documentContentType(RequestInterface $request, ResponseInterface $response): void {
		$match = $this->ownMatch($request);
		if ($match === null || $match->folder || !in_array($response->getStatus(), [200, 206, 304], true)
			|| !$this->server->tree->nodeExists($request->getPath())) {
			return;
		}
		$node = $this->server->tree->getNodeForPath($request->getPath());
		if (!$node instanceof File) {
			return;
		}
		$type = $this->contentTypes->forNode((int)$node->getId(), $node->getETag());
		if ($type !== null && $response->getStatus() !== 304) {
			$response->setHeader('Content-Type', $type);
		}
		$response->setHeader('Cache-Control', 'no-cache');
	}

	/** Stores the PUT's Content-Type; remoteStorage answers 200/201, not 204. */
	public function afterPut(RequestInterface $request, ResponseInterface $response): void {
		if ($this->ownMatch($request) === null || $response->getStatus() >= 300) {
			return;
		}
		$node = $this->server->tree->getNodeForPath($request->getPath());
		$etag = (string)($response->getHeader('ETag') ?? ($node instanceof Node ? $node->getETag() : ''));
		// Nextcloud's local storage derives a file's ETag from mtime (whole seconds),
		// inode, device and size, so a same-size overwrite within a second keeps
		// the old ETag and If-Match can no longer tell the versions apart.
		if ($node instanceof File && $this->etagBeforePut !== null && $etag !== ''
			&& Listing::bareETag($etag) === Listing::bareETag($this->etagBeforePut)) {
			$etag = '"' . self::forceNewETag($node) . '"';
			$response->setHeader('ETag', $etag);
		}
		if ($node instanceof File && $etag !== '') {
			$this->contentTypes->remember((int)$node->getId(), $etag, (string)$request->getHeader('Content-Type'));
		}
		if ($response->getStatus() === 204) {
			$response->setStatus(200);
		}
	}

	/**
	 * remoteStorage DELETE answers 200, not 204, and returns the deleted
	 * document's ETag. Empty parent folders stay on disk: listings already
	 * omit them, and deleting them can race a concurrent PUT.
	 */
	public function afterDelete(RequestInterface $request, ResponseInterface $response): void {
		$match = $this->ownMatch($request);
		if ($match === null || $response->getStatus() >= 300) {
			return;
		}
		if ($response->getStatus() === 204) {
			$response->setStatus(200);
		}
		// remoteStorage spec >= 2: a DELETE response carries the deleted document's ETag.
		if ($this->etagBeforeDelete !== null) {
			$response->setHeader('ETag', $this->etagBeforeDelete);
		}
	}

	private function ownMatch(RequestInterface $request): ?StorageMatch {
		if (!$this->state->isRemoteStorageLogin()) {
			return null;
		}
		$match = $this->paths->match($request->getUrl());
		return $match !== null && $match->uid === $this->state->uid ? $match : null;
	}

	/** Gives a document a fresh unique ETag and propagates the change to its folders (public API only). */
	private static function forceNewETag(File $file): string {
		$node = $file->getNode();
		$storage = $node->getStorage();
		$etag = bin2hex(random_bytes(16));
		$storage->getCache()->update($node->getId(), ['etag' => $etag]);
		$storage->getPropagator()->propagateChange($node->getInternalPath(), time());
		return $etag;
	}

	/** A folder "exists" for remoteStorage if its subtree holds at least one document. */
	private static function containsDocument(INode $folder): bool {
		if ($folder instanceof Node && (int)$folder->getSize() > 0) {
			return true;
		}
		foreach ($folder instanceof ICollection ? $folder->getChildren() : [] as $child) {
			if (!$child instanceof ICollection || self::containsDocument($child)) {
				return true;
			}
		}
		return false;
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
