<h1><?= $e($t('2fa.login_title')) ?></h1>
<p><?= $e($t('2fa.login_hint')) ?></p>
<?php if ($error): ?><p class="err"><?= $e($t($error)) ?></p><?php endif; ?>
<form method="post" action="/login/2fa">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <label for="code"><?= $e($t('2fa.code')) ?></label>
  <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus required>
  <button><?= $e($t('btn.confirm')) ?></button>
</form>
