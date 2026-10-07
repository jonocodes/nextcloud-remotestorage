<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\AppInfo;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\DAV\Events\SabrePluginAuthInitEvent;
use OCA\RemoteStorage\Dav\RequestState;
use OCA\RemoteStorage\Listener\AuthInitListener;
use OCA\RemoteStorage\Listener\PluginAddListener;
use OCA\RemoteStorage\Listener\UserDeletedListener;
use OCA\RemoteStorage\Service\StoragePaths;
use OCA\RemoteStorage\WellKnown\WebFingerHandler;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use OCP\User\Events\UserDeletedEvent;
use Psr\Container\ContainerInterface;

class Application extends App implements IBootstrap {
	public const APP_ID = 'remotestorage';
	/** Folder in each user's files that holds their remoteStorage data. */
	public const DEFAULT_ROOT = 'remoteStorage';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerService(StoragePaths::class, static fn (ContainerInterface $c): StoragePaths => new StoragePaths(
			$c->get(IAppConfig::class)->getValueString(self::APP_ID, 'storage_root', self::DEFAULT_ROOT),
			$c->get(IURLGenerator::class)->getWebroot(),
		));
		$context->registerService(RequestState::class, static fn (): RequestState => new RequestState());
		$context->registerEventListener(SabrePluginAuthInitEvent::class, AuthInitListener::class);
		$context->registerEventListener(SabrePluginAddEvent::class, PluginAddListener::class);
		$context->registerEventListener(UserDeletedEvent::class, UserDeletedListener::class);
		$context->registerWellKnownHandler(WebFingerHandler::class);
	}

	public function boot(IBootContext $context): void {
	}
}
