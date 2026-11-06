<h1><?= $e($t('2fa.setup_title')) ?></h1>
<p><?= $e($t('2fa.setup_intro')) ?></p>
<div class="card">
  <p><?= $e($t('2fa.secret')) ?>: <code><?= $e($secret) ?></code></p>
  <p><small><?= $e($t('2fa.uri')) ?>: <code style="word-break:break-all"><?= $e($uri) ?></code></small></p>
</div>
<?php if ($error): ?><p class="err"><?= $e($t($error)) ?></p><?php endif; ?>
<form method="post" action="/account/2fa">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <label for="code"><?= $e($t('2fa.first_code')) ?></label>
  <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" required>
  <button><?= $e($t('btn.confirm')) ?></button>
</form>
