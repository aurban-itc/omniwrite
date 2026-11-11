<p><a href="/admin">&larr; <?= $e($t('admin.title')) ?></a></p>
<h1><?= $e($t('admin.import')) ?></h1>
<p><?= $e($t('admin.import_intro')) ?></p>
<?php if ($result !== null): ?>
  <div class="card ok">
    <?= $e($t('admin.import_done', [
        'posts' => $result['posts'], 'posts_skipped' => $result['posts_skipped'],
        'topics' => $result['topics'], 'topics_skipped' => $result['topics_skipped'],
        'recipients' => $result['recipients'], 'recipients_skipped' => $result['recipients_skipped'],
        'images' => $result['images'], 'images_repaired' => $result['images_repaired'],
    ])) ?>
    <?php foreach ($result['warnings'] as $w): ?><br><small><?= $e($w) ?></small><?php endforeach; ?>
  </div>
<?php endif; ?>
<?php if ($sites === []): ?>
  <p><?= $e($t('admin.import_no_sites')) ?> <a href="/admin/accounts/new"><?= $e($t('admin.new_account')) ?></a></p>
<?php else: ?>
<form method="post" action="/admin/import" enctype="multipart/form-data">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <label for="site_id"><?= $e($t('admin.import_site')) ?></label>
  <select id="site_id" name="site_id">
    <?php foreach ($sites as $s): ?>
      <option value="<?= (int) $s['id'] ?>"<?= (int) $s['id'] === $selected ? ' selected' : '' ?>><?= $e($s['account_name']) ?> / <?= $e($s['name']) ?> (<?= $e($s['slug']) ?>)</option>
    <?php endforeach; ?>
  </select>
  <?php if (!empty($errors['site_id'])): ?><p class="err"><?= $e($t($errors['site_id'])) ?></p><?php endif; ?>
  <label for="export"><?= $e($t('admin.import_file')) ?></label>
  <input id="export" name="export" type="file" accept=".json,application/json" required>
  <?php if (!empty($errors['export'])): ?><p class="err"><?= $e($t($errors['export'])) ?><?= !empty($detail) ? ' (' . $e($detail) . ')' : '' ?></p><?php endif; ?>
  <button><?= $e($t('admin.import_run')) ?></button>
</form>
<?php endif; ?>

<h2><?= $e($t('admin.storage_title')) ?></h2>
<p><small><?= $e($t('admin.storage_intro')) ?></small></p>
<?php foreach ($storage as $st): ?>
  <div class="card">
    <strong><?= $e($st['site']['slug']) ?></strong>:
    <code><?= $e($st['dir']) ?></code>
    &middot; <?= $e($t($st['base_real'] !== '' ? 'admin.storage_found' : 'admin.storage_missing')) ?>
    <?= $st['base_real'] !== '' ? '(' . $e($st['base_real']) . ')' : '' ?>
    &middot; <?= $e($t($st['writable'] ? 'admin.storage_writable' : 'admin.storage_readonly')) ?>
    &middot; <?= $e($t('admin.storage_files', ['n' => $st['count']])) ?>
    <?php if ($st['sample'] !== []): ?><br><small><code><?= $e(implode(', ', $st['sample'])) ?></code></small><?php endif; ?>
  </div>
<?php endforeach; ?>
