<?php include __DIR__ . '/_site_nav.php'; $base = '/sites/' . $site['slug']; ?>
<h2><?= $e($t('analyze.title')) ?></h2>
<?php if ($applied !== null): ?><p class="ok"><?= $e($t('analyze.applied', ['s' => $applied['settings'], 't' => $applied['topics']])) ?></p><?php endif; ?>
<?php if (!$aiReady): ?><p class="err"><?= $e($t('err.ai_not_configured')) ?></p><?php endif; ?>
<?php if (!empty($error)): ?><p class="err"><?= $e($error) ?></p><?php endif; ?>
<p><?= $e($t('analyze.intro')) ?></p>
<form method="post" action="<?= $e($base) ?>/settings/analyze">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <label for="url"><?= $e($t('analyze.url')) ?></label>
  <input id="url" name="url" type="text" inputmode="url" placeholder="https://www.example.com" maxlength="2000" value="<?= $e($url) ?>" required>
  <button <?= $aiReady && $rs->canWrite() ? '' : 'disabled' ?>><?= $e($t('analyze.start')) ?></button>
  <small><?= $e($t('ai.takes_a_minute')) ?></small>
</form>
<p><small><?= $e($t('analyze.privacy')) ?></small></p>
<p><a href="<?= $e($base) ?>/settings"><?= $e($t('analyze.back')) ?></a></p>
