<h1><?= $e($t('dashboard.title')) ?></h1>
<p><?= $e($account['name']) ?></p>
<?php if ($sites === []): ?><p><?= $e($t('dashboard.no_sites')) ?></p><?php endif; ?>
<?php foreach ($sites as $site): ?>
  <div class="card"><strong><?= $e($site['name']) ?></strong> <small>/b/<?= $e($site['slug']) ?>/ · <?= $e($site['locale']) ?> · <?= $e($site['timezone']) ?></small><br>
    <a href="/sites/<?= $e($site['slug']) ?>/posts"><?= $e($t('site.posts')) ?></a> ·
    <a href="/sites/<?= $e($site['slug']) ?>/topics"><?= $e($t('site.topics')) ?></a> ·
    <a href="/sites/<?= $e($site['slug']) ?>/settings"><?= $e($t('site.settings')) ?></a></div>
<?php endforeach; ?>
<?php if ($canManage): ?>
<h2><?= $e($t('dashboard.new_site')) ?></h2>
<form method="post" action="/sites">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <label for="name"><?= $e($t('field.site_name')) ?></label>
  <input id="name" name="name" required value="<?= $e($old['name'] ?? '') ?>">
  <?php if (!empty($errors['name'])): ?><p class="err"><?= $e($t($errors['name'])) ?></p><?php endif; ?>
  <label for="slug"><?= $e($t('field.slug')) ?></label>
  <input id="slug" name="slug" required pattern="[a-z0-9-]{3,40}" value="<?= $e($old['slug'] ?? '') ?>">
  <?php if (!empty($errors['slug'])): ?><p class="err"><?= $e($t($errors['slug'])) ?></p><?php endif; ?>
  <label for="locale"><?= $e($t('field.locale')) ?></label>
  <select id="locale" name="locale"><option value="de">Deutsch</option><option value="en">English</option></select>
  <label for="timezone"><?= $e($t('field.timezone')) ?></label>
  <?php $tzSelected = $old['timezone'] ?? ''; include __DIR__ . '/_timezone_select.php'; ?>
  <?php if (!empty($errors['timezone'])): ?><p class="err"><?= $e($t($errors['timezone'])) ?></p><?php endif; ?>
  <button><?= $e($t('btn.create_site')) ?></button>
</form>
<?php endif; ?>
