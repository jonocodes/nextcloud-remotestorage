<?php

declare(strict_types=1);

namespace OCA\RemoteStorage\Listener;

use OCA\RemoteStorage\Db\AuthCodeMapper;
use OCA\RemoteStorage\Db\TokenMapper;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;

/** @template-implements IEventListener<UserDeletedEvent> */
class UserDeletedListener implements IEventListener {
	public function __construct(
		private TokenMapper $tokens,
		private AuthCodeMapper $codes,
	) {
	}

	public function handle(Event $event): void {
		if ($event instanceof UserDeletedEvent) {
			$uid = $event->getUser()->getUID();
			$this->tokens->deleteForUser($uid);
			$this->codes->deleteForUser($uid);
		}
	}
}
