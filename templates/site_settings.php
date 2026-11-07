<?php include __DIR__ . '/_site_nav.php'; ?>
<h2><?= $e($t('site.settings')) ?></h2>
<?php if ($saved): ?><p class="ok"><?= $e($t('settings.saved')) ?></p><?php endif; ?>
<form method="post" action="/sites/<?= $e($site['slug']) ?>/settings">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <?php foreach (['style_guide' => 8, 'company_facts' => 6, 'image_style' => 3] as $key => $rows): ?>
    <label for="<?= $e($key) ?>"><?= $e($t('settings.' . $key)) ?></label>
    <textarea id="<?= $e($key) ?>" name="<?= $e($key) ?>" rows="<?= $rows ?>" <?= $rs->canPublish() ? '' : 'readonly' ?>><?= $e($values[$key] ?? '') ?></textarea>
    <?php if (!empty($errors[$key])): ?><p class="err"><?= $e($t($errors[$key])) ?></p><?php endif; ?>
  <?php endforeach; ?>
  <p><small><?= $e($t('settings.reset_hint')) ?></small></p>
  <?php if ($rs->canPublish()): ?><button><?= $e($t('btn.save')) ?></button><?php endif; ?>
</form>
