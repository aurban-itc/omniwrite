<?php include __DIR__ . '/_site_nav.php'; $base = '/sites/' . $site['slug'];
$rows = ['style_guide' => 8, 'company_facts' => 6, 'image_style' => 3]; ?>
<h2><?= $e($t('analyze.review_title')) ?></h2>
<p><?= $e($t('analyze.review_intro', ['url' => $result['url'], 'n' => $result['pages']])) ?></p>
<p><strong><?= $e($t('analyze.nothing_saved')) ?></strong></p>
<form method="post" action="<?= $e($base) ?>/settings/analyze/apply">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <?php if ($rs->canPublish()): ?>
    <h3><?= $e($t('site.settings')) ?></h3>
    <?php foreach ($rows as $key => $n): $proposal = $result['settings'][$key]; ?>
      <div class="card">
        <label><input type="checkbox" name="use_<?= $e($key) ?>" value="1" <?= $proposal !== '' ? 'checked' : '' ?>> <?= $e($t('settings.' . $key)) ?></label>
        <textarea name="<?= $e($key) ?>" rows="<?= $n ?>"><?= $e($proposal) ?></textarea>
        <?php if (trim($result['current'][$key]) !== ''): ?>
          <details><summary><?= $e($t('analyze.current')) ?></summary><pre style="white-space:pre-wrap"><?= $e($result['current'][$key]) ?></pre></details>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php else: ?>
    <p><small><?= $e($t('analyze.owner_only')) ?></small></p>
  <?php endif; ?>

  <h3><?= $e($t('site.topics')) ?></h3>
  <?php if ($result['topics'] === []): ?><p><?= $e($t('topic.none')) ?></p><?php endif; ?>
  <?php foreach ($result['topics'] as $i => $tp): ?>
    <div class="card">
      <label><input type="checkbox" name="topic_use[<?= $i ?>]" value="1" checked> <?= $e($t('analyze.take_topic')) ?></label>
      <input name="topic_title[<?= $i ?>]" maxlength="255" value="<?= $e($tp['title']) ?>" aria-label="<?= $e($t('topic.title')) ?>">
      <input name="topic_keywords[<?= $i ?>]" maxlength="400" value="<?= $e($tp['keywords']) ?>" aria-label="<?= $e($t('post.keywords')) ?>">
      <input name="topic_category[<?= $i ?>]" maxlength="80" value="<?= $e($tp['category']) ?>" aria-label="<?= $e($t('post.category')) ?>">
      <textarea name="topic_notes[<?= $i ?>]" rows="2" aria-label="<?= $e($t('topic.notes')) ?>"><?= $e($tp['notes']) ?></textarea>
    </div>
  <?php endforeach; ?>
  <button><?= $e($t('analyze.apply')) ?></button>
  <a href="<?= $e($base) ?>/settings/analyze"><?= $e($t('analyze.discard')) ?></a>
</form>
