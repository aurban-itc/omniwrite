<?php $err = static fn (string $f, string $k): string => $form === $f ? ($errors[$k] ?? '') : ''; ?>
<p><a href="/admin">&larr; <?= $e($t('admin.title')) ?></a></p>
<h1><?= $e($account['name']) ?></h1>
<?php if ($created): ?><p class="ok"><?= $e($t('admin.created_hint')) ?></p><?php elseif ($saved): ?><p class="ok"><?= $e($t('admin.saved')) ?></p><?php endif; ?>

<form method="post" action="/admin/accounts/<?= (int) $account['id'] ?>">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <label for="account_name"><?= $e($t('field.account_name')) ?></label>
  <input id="account_name" name="account_name" required value="<?= $e($form === 'rename' ? ($old['account_name'] ?? '') : $account['name']) ?>">
  <?php if ($err('rename', 'account_name') !== ''): ?><p class="err"><?= $e($t($err('rename', 'account_name'))) ?></p><?php endif; ?>
  <button><?= $e($t('btn.save')) ?></button>
</form>

<h2><?= $e($t('admin.members')) ?></h2>
<?php if ($err('role', 'role') !== ''): ?><p class="err"><?= $e($t($err('role', 'role'))) ?></p><?php endif; ?>
<?php if ($form === 'reset' && !empty($errors['password'])): ?><p class="err"><?= $e($t($errors['password'])) ?></p><?php endif; ?>
<?php foreach ($members as $m): ?>
  <div class="card">
    <strong><?= $e($m['name']) ?></strong> &lt;<?= $e($m['email']) ?>&gt;
    <span class="pill"><?= $e($t('role.' . $m['role'])) ?></span>
    <span class="pill<?= $m['status'] === 'active' ? '' : ' declined' ?>"><?= $e($t('user.status.' . $m['status'])) ?></span>
    <form method="post" action="/admin/accounts/<?= (int) $account['id'] ?>/members/<?= (int) $m['user_id'] ?>/role" style="display:inline">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <select name="role" style="width:auto"><option value="owner"<?= $m['role'] === 'owner' ? ' selected' : '' ?>><?= $e($t('role.owner')) ?></option><option value="editor"<?= $m['role'] === 'editor' ? ' selected' : '' ?>><?= $e($t('role.editor')) ?></option></select>
      <button><?= $e($t('btn.save')) ?></button>
    </form>
    <form method="post" action="/admin/users/<?= (int) $m['user_id'] ?>/<?= $m['status'] === 'active' ? 'disable' : 'enable' ?>" style="display:inline">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
      <button><?= $e($t($m['status'] === 'active' ? 'btn.disable' : 'btn.enable')) ?></button>
    </form>
    <form method="post" action="/admin/accounts/<?= (int) $account['id'] ?>/members/<?= (int) $m['user_id'] ?>/remove" style="display:inline">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button><?= $e($t('btn.remove')) ?></button>
    </form>
    <form method="post" action="/admin/users/<?= (int) $m['user_id'] ?>/password" style="display:inline">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="account_id" value="<?= (int) $account['id'] ?>">
      <input name="password" type="text" autocomplete="off" minlength="10" placeholder="<?= $e($t('admin.new_password')) ?>" style="width:12rem">
      <button><?= $e($t('admin.reset_password')) ?></button>
    </form>
  </div>
<?php endforeach; ?>

<h3><?= $e($t('admin.add_member')) ?></h3>
<p><small><?= $e($t('admin.add_member_hint')) ?></small></p>
<form method="post" action="/admin/accounts/<?= (int) $account['id'] ?>/members">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <?php $errors_m = $form === 'member' ? $errors : []; $old_m = $form === 'member' ? $old : []; ?>
  <?php (function () use ($e, $t, $errors_m, $old_m): void { $errors = $errors_m; $old = $old_m; include __DIR__ . '/_admin_user_fields.php'; })(); ?>
  <label for="role"><?= $e($t('field.role')) ?></label>
  <select id="role" name="role"><option value="editor"><?= $e($t('role.editor')) ?></option><option value="owner"<?= $form === 'member' && ($old['role'] ?? '') === 'owner' ? ' selected' : '' ?>><?= $e($t('role.owner')) ?></option></select>
  <?php if ($err('member', 'role') !== ''): ?><p class="err"><?= $e($t($err('member', 'role'))) ?></p><?php endif; ?>
  <button><?= $e($t('btn.add')) ?></button>
</form>

<h2><?= $e($t('admin.sites')) ?></h2>
<?php if ($sites === []): ?><p><?= $e($t('dashboard.no_sites')) ?></p><?php endif; ?>
<?php foreach ($sites as $site): ?>
  <div class="card"><strong><?= $e($site['name']) ?></strong> <small>/b/<?= $e($site['slug']) ?>/ · <?= $e($site['locale']) ?> · <?= $e($site['timezone']) ?></small><br>
    <a href="/sites/<?= $e($site['slug']) ?>/posts"><?= $e($t('site.posts')) ?></a> ·
    <a href="/sites/<?= $e($site['slug']) ?>/topics"><?= $e($t('site.topics')) ?></a> ·
    <a href="/sites/<?= $e($site['slug']) ?>/settings"><?= $e($t('site.settings')) ?></a></div>
<?php endforeach; ?>
<h3><?= $e($t('dashboard.new_site')) ?></h3>
<form method="post" action="/admin/accounts/<?= (int) $account['id'] ?>/sites">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <label for="site_name"><?= $e($t('field.site_name')) ?></label>
  <input id="site_name" name="name" required value="<?= $e($form === 'site' ? ($old['name'] ?? '') : '') ?>">
  <?php if ($err('site', 'name') !== ''): ?><p class="err"><?= $e($t($err('site', 'name'))) ?></p><?php endif; ?>
  <label for="slug"><?= $e($t('field.slug')) ?></label>
  <input id="slug" name="slug" required pattern="[a-z0-9-]{3,40}" value="<?= $e($form === 'site' ? ($old['slug'] ?? '') : '') ?>">
  <?php if ($err('site', 'slug') !== ''): ?><p class="err"><?= $e($t($err('site', 'slug'))) ?></p><?php endif; ?>
  <label for="site_locale"><?= $e($t('field.locale')) ?></label>
  <select id="site_locale" name="locale"><option value="de">Deutsch</option><option value="en">English</option></select>
  <label for="timezone"><?= $e($t('field.timezone')) ?></label>
  <?php $tzSelected = $form === 'site' ? ($old['timezone'] ?? '') : ''; include __DIR__ . '/_timezone_select.php'; ?>
  <?php if ($err('site', 'timezone') !== ''): ?><p class="err"><?= $e($t($err('site', 'timezone'))) ?></p><?php endif; ?>
  <button><?= $e($t('btn.create_site')) ?></button>
</form>
