<?php include __DIR__ . '/_site_nav.php'; $base = '/sites/' . $site['slug']; $next = $overview['next_id'];
$btn = static function (string $base, string $csrf, callable $e, callable $t, int $id, string $do, string $label, string $confirm = ''): string {
    return '<form method="post" action="' . $e($base) . '/topics/' . $id . '/' . $e($do) . '" style="display:inline"><input type="hidden" name="_csrf" value="' . $e($csrf) . '"><button'
        . ($confirm !== '' ? ' data-confirm="' . $e($confirm) . '"' : '') . '>' . $e($label) . '</button></form> ';
};
?>
<h2><?= $e($t('site.topics')) ?></h2>
<?php if (!empty($aiError)): ?><p class="err"><?= $e($t($aiError)) ?></p><?php endif; ?>
<?php if (!empty($suggested)): ?><p class="ok"><?= $e($t('topic.suggested_added', ['n' => (int) $suggested])) ?></p><?php endif; ?>
<?php if ($rs->canWrite()): ?>
  <p><a href="<?= $e($base) ?>/generate"><?= $e($t('ai.write_now')) ?></a>
  <form method="post" action="<?= $e($base) ?>/topics/suggest" style="display:inline"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button><?= $e($t('ai.suggest_topics')) ?></button></form></p>
<?php endif; ?>
<p><small><?= $e($t('topic.rotation_hint')) ?></small></p>
<?php if ($overview['rotation'] === []): ?><p><?= $e($t('topic.none')) ?></p><?php endif; ?>
<?php foreach ($overview['rotation'] as $i => $row): ?>
  <div class="card">
    <?= $i + 1 ?>. <a href="<?= $e($base) ?>/topics/<?= (int) $row['id'] ?>"><strong><?= $e($row['title']) ?></strong></a>
    <?php if ($next === (int) $row['id']): ?><span class="pill published"><?= $e($t('topic.next')) ?></span><?php endif; ?>
    <span class="pill"><?= $e($t('topic.status.' . $row['status'])) ?></span><br>
    <small><?= $e($row['keywords']) ?> · <?= $e($t('topic.used', ['n' => (int) $row['times_used']])) ?></small>
    <?php if ($rs->canWrite()): ?><div>
      <?= $btn($base, $csrf, $e, $t, (int) $row['id'], 'up', '↑') ?><?= $btn($base, $csrf, $e, $t, (int) $row['id'], 'down', '↓') ?>
      <?= $row['status'] === 'active' ? $btn($base, $csrf, $e, $t, (int) $row['id'], 'pause', $t('action.pause')) : $btn($base, $csrf, $e, $t, (int) $row['id'], 'resume', $t('action.resume')) ?>
      <?= $btn($base, $csrf, $e, $t, (int) $row['id'], 'delete', $t('action.delete'), $t('confirm.delete_topic')) ?>
    </div><?php endif; ?>
  </div>
<?php endforeach; ?>
<?php if ($overview['suggestions'] !== []): ?>
  <h3><?= $e($t('topic.suggestions')) ?></h3>
  <?php foreach ($overview['suggestions'] as $row): ?>
    <div class="card"><?= $e($row['title']) ?>
      <?php if ($rs->canWrite()): ?><?= $btn($base, $csrf, $e, $t, (int) $row['id'], 'accept', $t('action.accept')) ?><?= $btn($base, $csrf, $e, $t, (int) $row['id'], 'dismiss', $t('action.dismiss')) ?><?php endif; ?></div>
  <?php endforeach; ?>
<?php endif; ?>
<?php if ($rs->canWrite()): ?>
<h3><?= $e($t('topic.add')) ?></h3>
<form method="post" action="<?= $e($base) ?>/topics">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <?php include __DIR__ . '/_topic_fields.php'; ?>
  <button><?= $e($t('btn.add')) ?></button>
</form>
<?php endif; ?>
