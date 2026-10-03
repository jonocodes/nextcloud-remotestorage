<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
?>
<div id="remotestorage" class="section">
	<h2><?php p($l->t('remoteStorage')); ?></h2>
	<p><?php p($l->t('Your remoteStorage address:')); ?> <code><?php p($_['address']); ?></code></p>
	<?php if (empty($_['tokens'])): ?>
	<p><?php p($l->t('No remoteStorage apps are connected.')); ?></p>
	<?php else: ?>
	<table class="grid" id="remotestorage-tokens">
		<thead><tr>
			<th><?php p($l->t('App')); ?></th><th><?php p($l->t('Access')); ?></th>
			<th><?php p($l->t('Connected')); ?></th><th><?php p($l->t('Last used')); ?></th><th></th>
		</tr></thead>
		<tbody>
		<?php foreach ($_['tokens'] as $row): $token = $row['token']; ?>
		<tr data-client="<?php p($token->getClientId()); ?>">
			<td><?php p($token->getClientId()); ?></td>
			<td><code><?php p($token->getScope()); ?></code></td>
			<td><?php p(gmdate('Y-m-d H:i', $token->getCreatedAt())); ?> UTC</td>
			<td><?php p(gmdate('Y-m-d H:i', $token->getLastUsedAt())); ?> UTC</td>
			<td>
				<form method="post" action="<?php p($row['revokeUrl']); ?>">
					<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">
					<button type="submit" class="remotestorage-revoke"><?php p($l->t('Disconnect')); ?></button>
				</form>
			</td>
		</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php endif; ?>
</div>
