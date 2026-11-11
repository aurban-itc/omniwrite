<!doctype html>
<html lang="<?= $e($locale) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>OmniWrite</title>
<meta name="theme-color" content="#07090f">
<link rel="stylesheet" href="/assets/app.css?v=<?= (int) @filemtime(__DIR__ . '/../public/assets/app.css') ?>">
</head>
<body>
<nav class="topbar"><div class="wrap">
  <a class="brand" href="<?= $user ? '/dashboard' : '/login' ?>"><span class="brand-mark" aria-hidden="true">✦</span>OmniWrite</a>
  <?php if ($user): ?>
    <a href="/dashboard"><?= $e($t('nav.dashboard')) ?></a>
    <?php if ($user->isSuperadmin): ?><a href="/admin"><?= $e($t('nav.admin')) ?></a><?php endif; ?>
    <span class="grow"></span>
    <a href="/account/2fa"><?= $e($t('nav.security')) ?></a>
    <span class="user"><?= $e($user->name) ?></span>
    <form method="post" action="/logout"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="link"><?= $e($t('nav.logout')) ?></button></form>
  <?php else: ?>
    <span class="grow"></span>
    <a href="/login"><?= $e($t('nav.login')) ?></a>
    <a class="cta" href="/register"><?= $e($t('nav.register')) ?></a>
    <a href="?lang=<?= $locale === 'de' ? 'en' : 'de' ?>"><?= $locale === 'de' ? 'EN' : 'DE' ?></a>
  <?php endif; ?>
</div></nav>
<?php if (!empty($impersonator)): ?>
<div class="card banner">
  <?= $e($t('imp.banner', ['name' => $user->name ?? ''])) ?>
  <form method="post" action="/impersonate/stop" style="display:inline"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button><?= $e($t('imp.stop')) ?></button></form>
</div>
<?php endif; ?>
<main><?= $content ?></main>
<script src="/assets/app.js?v=<?= (int) @filemtime(__DIR__ . '/../public/assets/app.js') ?>" defer></script>
</body>
</html>
