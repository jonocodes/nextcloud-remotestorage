<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Listener;

use OCA\DAV\Events\SabrePluginAuthInitEvent;
use OCA\RemoteStorage\Dav\TokenAuth;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Sabre\DAV\Auth\Plugin;

/** @template-implements IEventListener<SabrePluginAuthInitEvent> */
class AuthInitListener implements IEventListener {
	public function __construct(
		private TokenAuth $tokenAuth,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof SabrePluginAuthInitEvent) {
			return;
		}
		$plugin = $event->getServer()->getPlugin('auth');
		if ($plugin instanceof Plugin) {
			$plugin->addBackend($this->tokenAuth);
		}
	}
}
