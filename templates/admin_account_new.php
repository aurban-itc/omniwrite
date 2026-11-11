<p><a href="/admin">&larr; <?= $e($t('admin.title')) ?></a></p>
<h1><?= $e($t('admin.new_account')) ?></h1>
<form method="post" action="/admin/accounts">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <label for="account_name"><?= $e($t('field.account_name')) ?></label>
  <input id="account_name" name="account_name" required value="<?= $e($old['account_name'] ?? '') ?>">
  <?php if (!empty($errors['account_name'])): ?><p class="err"><?= $e($t($errors['account_name'])) ?></p><?php endif; ?>
  <h2><?= $e($t('admin.owner')) ?></h2>
  <p><small><?= $e($t('admin.owner_optional_hint')) ?></small></p>
  <?php $ownerOptional = true; include __DIR__ . "/_admin_user_fields.php"; ?>
  <button><?= $e($t('admin.create_account')) ?></button>
</form>
