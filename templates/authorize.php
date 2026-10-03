<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
?>
<div class="guest-box remotestorage-authorize">
	<h2><?php p($l->t('Connect %s?', [$_['origin']])); ?></h2>
	<p><?php p($l->t('%1$s wants to use your Nextcloud as remoteStorage, signed in as %2$s, with access to:', [$_['origin'], $_['user']])); ?></p>
	<ul id="remotestorage-scopes">
		<?php foreach ($_['scopes'] as $module => $level): ?>
		<li>
			<strong><?php p($module === '*' ? $l->t('all remoteStorage data') : $module); ?></strong>
			— <?php p($level === 'rw' ? $l->t('read and write') : $l->t('read only')); ?>
		</li>
		<?php endforeach; ?>
	</ul>
	<p><?php p($l->t('You can disconnect it at any time in Settings → Security.')); ?></p>
	<form method="post" action="<?php p($_['action']); ?>">
		<input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">
		<?php foreach ($_['params'] as $name => $value): ?>
		<input type="hidden" name="<?php p($name); ?>" value="<?php p($value); ?>">
		<?php endforeach; ?>
		<button type="submit" name="decision" value="allow" class="primary" id="remotestorage-allow"><?php p($l->t('Allow')); ?></button>
		<button type="submit" name="decision" value="deny" id="remotestorage-deny"><?php p($l->t('Deny')); ?></button>
	</form>
</div>
