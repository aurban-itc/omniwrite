<?php include __DIR__ . '/_site_nav.php'; $base = '/sites/' . $site['slug']; ?>
<h2><?= $e($t('ai.generate_title')) ?></h2>
<?php if (!$aiReady): ?><p class="err"><?= $e($t('err.ai_not_configured')) ?></p><?php endif; ?>
<?php if ($aiError): ?><p class="err"><?= $e($t($aiError)) ?></p><?php endif; ?>
<p><small><?= $e($t('ai.usage', [
    'used' => $usage['posts_used'],
    'limit' => $usage['posts_limit'] === null ? $t('ai.unlimited') : $usage['posts_limit'],
])) ?></small></p>
<form method="post" action="<?= $e($base) ?>/generate">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <label for="topic_id"><?= $e($t('ai.topic_from_list')) ?></label>
  <select id="topic_id" name="topic_id">
    <option value="0"><?= $e($t('ai.free_subject')) ?></option>
    <?php $sel = (int) ($old['topic_id'] ?? $preselect); foreach ($topics as $tp): ?>
      <option value="<?= (int) $tp['id'] ?>"<?= $sel === (int) $tp['id'] ? ' selected' : '' ?>><?= $e($tp['title']) ?></option>
    <?php endforeach; ?>
  </select>
  <h3><?= $e($t('ai.free_subject')) ?></h3>
  <label for="title"><?= $e($t('ai.subject')) ?></label>
  <input id="title" name="title" maxlength="255" value="<?= $e($old['title'] ?? '') ?>">
  <?php if (!empty($errors['title'])): ?><p class="err"><?= $e($t($errors['title'])) ?></p><?php endif; ?>
  <label for="keywords"><?= $e($t('post.keywords')) ?></label>
  <input id="keywords" name="keywords" maxlength="400" value="<?= $e($old['keywords'] ?? '') ?>">
  <label for="notes"><?= $e($t('topic.notes')) ?></label>
  <textarea id="notes" name="notes" rows="5"><?= $e($old['notes'] ?? '') ?></textarea>
  <label for="extra"><?= $e($t('ai.extra')) ?></label>
  <textarea id="extra" name="extra" rows="2" maxlength="2000"><?= $e($old['extra'] ?? '') ?></textarea>
  <button <?= $aiReady ? '' : 'disabled' ?>><?= $e($t('ai.write_draft')) ?></button>
  <small><?= $e($t('ai.takes_a_minute')) ?></small>
</form>
