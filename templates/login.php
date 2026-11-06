<h1><?= $e($t('login.title')) ?></h1>
<?php if (!empty($errors['form'])): ?><p class="err"><?= $e($t($errors['form'])) ?></p><?php endif; ?>
<form method="post" action="/login">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <label for="email"><?= $e($t('field.email')) ?></label>
  <input id="email" name="email" type="email" autocomplete="username" required value="<?= $e($old['email'] ?? '') ?>">
  <label for="password"><?= $e($t('field.password')) ?></label>
  <input id="password" name="password" type="password" autocomplete="current-password" required>
  <button><?= $e($t('btn.login')) ?></button>
</form>
