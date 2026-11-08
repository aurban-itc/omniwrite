<?php include __DIR__ . '/_site_nav.php'; $old = $topic; ?>
<h2><?= $e($t('topic.edit')) ?></h2>
<?php if ($rs->canWrite()): ?>
<form method="post" action="/sites/<?= $e($site['slug']) ?>/topics/<?= (int) $topic['id'] ?>">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <?php include __DIR__ . '/_topic_fields.php'; ?>
  <button><?= $e($t('btn.save')) ?></button>
</form>
<?php endif; ?>
<p><a href="/sites/<?= $e($site['slug']) ?>/topics"><?= $e($t('btn.back')) ?></a></p>
