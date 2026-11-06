<!doctype html>
<html lang="<?= $e($locale) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>OmniWrite</title>
<style>
body{font:16px/1.5 system-ui,sans-serif;max-width:46rem;margin:0 auto;padding:0 16px 3rem;color:#1b1f24}
nav{display:flex;gap:1rem;align-items:center;padding:1rem 0;border-bottom:1px solid #d8dde3;margin-bottom:1.5rem}
nav .grow{flex:1}nav form{display:inline}
label{display:block;margin:.8rem 0 .2rem;font-weight:600}
input,select{width:100%;padding:.5rem;font:inherit;box-sizing:border-box}
button{margin-top:1rem;padding:.55rem 1.1rem;font:inherit;cursor:pointer}
.err{color:#b00020;margin:.2rem 0}.card{border:1px solid #d8dde3;border-radius:6px;padding:1rem;margin:1rem 0}
button.link{background:none;border:0;padding:0;margin:0;color:#0645ad;text-decoration:underline}
@media (prefers-color-scheme:dark){body{background:#14171a;color:#e6e8ea}nav,.card{border-color:#333}input,select{background:#1d2126;color:inherit;border:1px solid #444}button.link{color:#8ab4f8}.err{color:#ff8a80}}
</style>
</head>
<body>
<nav>
  <strong>OmniWrite</strong>
  <?php if ($user): ?>
    <a href="/dashboard"><?= $e($t('nav.dashboard')) ?></a>
    <?php if ($user->isSuperadmin): ?><a href="/admin"><?= $e($t('nav.admin')) ?></a><?php endif; ?>
    <span class="grow"></span>
    <span><?= $e($user->name) ?></span>
    <form method="post" action="/logout"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="link"><?= $e($t('nav.logout')) ?></button></form>
  <?php else: ?>
    <span class="grow"></span>
    <a href="/login"><?= $e($t('nav.login')) ?></a>
    <a href="/register"><?= $e($t('nav.register')) ?></a>
    <a href="?lang=<?= $locale === 'de' ? 'en' : 'de' ?>"><?= $locale === 'de' ? 'EN' : 'DE' ?></a>
  <?php endif; ?>
</nav>
<?php if (!empty($impersonator)): ?>
<div class="card" style="border-color:#b00020">
  <?= $e($t('imp.banner', ['name' => $user->name ?? ''])) ?>
  <form method="post" action="/impersonate/stop" style="display:inline"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button><?= $e($t('imp.stop')) ?></button></form>
</div>
<?php endif; ?>
<main><?= $content ?></main>
</body>
</html>
