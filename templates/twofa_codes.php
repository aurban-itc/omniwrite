<h1><?= $e($t('2fa.codes_title')) ?></h1>
<p><?= $e($t('2fa.codes_hint')) ?></p>
<div class="card"><?php foreach ($codes as $c): ?><div><code><?= $e($c) ?></code></div><?php endforeach; ?></div>
<p><a href="<?= ($user && $user->isSuperadmin) ? '/admin' : '/dashboard' ?>"><?= $e($t('2fa.continue')) ?></a></p>
