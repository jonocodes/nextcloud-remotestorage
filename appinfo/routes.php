<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'oauth#authorize', 'url' => '/oauth', 'verb' => 'GET'],
		['name' => 'oauth#approve', 'url' => '/oauth', 'verb' => 'POST'],
		['name' => 'token#revoke', 'url' => '/tokens/revoke', 'verb' => 'POST'],
	],
];
