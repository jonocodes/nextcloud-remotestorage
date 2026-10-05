<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
?>
<div id="remotestorage" class="section">
	<h2><?php p($l->t('remoteStorage')); ?></h2>
	<p><?php p($l->t('Your remoteStorage address:')); ?> <code><?php p($_['address']); ?></code></p>
	<?php if (empty($_['apps'])): ?>
	<p><?php p($l->t('No remoteStorage apps are connected.')); ?></p>
	<?php else: ?>
	<table class="grid" id="remotestorage-tokens">
		<thead><tr>
			<th><?php p($l->t('App')); ?></th><th><?php p($l->t('Access')); ?></th>
			<th><?php p($l->t('Connected')); ?></th><th><?php p($l->t('Last used')); ?></th><th></th>
		</tr></thead>
		<tbody>
		<?php foreach ($_['apps'] as $app): ?>
		<tr data-client="<?php p($app['clientId']); ?>">
			<td>
				<?php p($app['clientId']); ?>
				<?php if ($app['count'] > 1): ?>
				<br><span class="remotestorage-token-count"><?php p($l->t('%s connections', [$app['count']])); ?></span>
				<?php endif; ?>
			</td>
			<td><?php foreach ($app['scopes'] as $scope): ?><code><?php p($scope); ?></code> <?php endforeach; ?></td>
			<td><?php p(gmdate('Y-m-d H:i', $app['createdAt'])); ?> UTC</td>
			<td><?php p(gmdate('Y-m-d H:i', $app['lastUsedAt'])); ?> UTC</td>
			<td>
				<form method="post" action="<?php p($_['revokeAction']); ?>">
					<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">
					<input type="hidden" name="clientId" value="<?php p($app['clientId']); ?>">
					<button type="submit" class="remotestorage-revoke"><?php p($l->t('Disconnect')); ?></button>
				</form>
			</td>
		</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>
</div>
