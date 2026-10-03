<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Listener;

use OCA\DAV\Events\SabrePluginAddEvent;
use OCA\RemoteStorage\Dav\RsPlugin;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/** @template-implements IEventListener<SabrePluginAddEvent> */
class PluginAddListener implements IEventListener {
	public function __construct(
		private RsPlugin $plugin,
	) {
	}

	public function handle(Event $event): void {
		if ($event instanceof SabrePluginAddEvent) {
			$event->getServer()->addPlugin($this->plugin);
		}
	}
}
