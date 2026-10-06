<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'oauth#authorize', 'url' => '/oauth', 'verb' => 'GET'],
		['name' => 'oauth#approve', 'url' => '/oauth', 'verb' => 'POST'],
		['name' => 'token#revoke', 'url' => '/tokens/revoke', 'verb' => 'POST'],
		['name' => 'debug#config', 'url' => '/debug/config', 'verb' => 'GET'],
		['name' => 'debug#explain', 'url' => '/debug/explain', 'verb' => 'GET'],
		['name' => 'debug#tokensMine', 'url' => '/debug/tokens/mine', 'verb' => 'GET'],
		['name' => 'debug#tokens', 'url' => '/debug/tokens', 'verb' => 'GET'],
	],
];
