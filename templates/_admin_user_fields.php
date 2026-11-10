<?php /** Felder für einen neuen Kunden-Nutzer. Erwartet $errors und $old. */ ?>
<label for="email"><?= $e($t('field.email')) ?></label>
<input id="email" name="email" type="email" required value="<?= $e($old['email'] ?? '') ?>">
<?php if (!empty($errors['email'])): ?><p class="err"><?= $e($t($errors['email'])) ?></p><?php endif; ?>
<label for="name"><?= $e($t('field.name')) ?></label>
<input id="name" name="name" required value="<?= $e($old['name'] ?? '') ?>">
<?php if (!empty($errors['name'])): ?><p class="err"><?= $e($t($errors['name'])) ?></p><?php endif; ?>
<label for="password"><?= $e($t('admin.start_password')) ?></label>
<input id="password" name="password" type="text" autocomplete="off" minlength="10" value="">
<small><?= $e($t('admin.start_password_hint')) ?></small>
<?php if (!empty($errors['password'])): ?><p class="err"><?= $e($t($errors['password'])) ?></p><?php endif; ?>
<label for="locale"><?= $e($t('field.locale')) ?></label>
<select id="locale" name="locale"><option value="de"<?= ($old['locale'] ?? '') === 'en' ? '' : ' selected' ?>>Deutsch</option><option value="en"<?= ($old['locale'] ?? '') === 'en' ? ' selected' : '' ?>>English</option></select>
