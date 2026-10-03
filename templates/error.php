<?php
/** @var array $_ */
/** @var \OCP\IL10N $l */
?>
<div class="guest-box">
	<h2><?php p($l->t('This remoteStorage connection request is invalid')); ?></h2>
	<p><?php p($l->t('The app that sent you here made a malformed request (%s). Nothing was shared.', [$_['code']])); ?></p>
</div>
