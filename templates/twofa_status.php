<h1><?= $e($t('2fa.status_title')) ?></h1>
<p><?= $e($t('2fa.status_on')) ?></p>
<p><a href="<?= ($user && $user->isSuperadmin) ? '/admin' : '/dashboard' ?>"><?= $e($t('2fa.continue')) ?></a></p>
