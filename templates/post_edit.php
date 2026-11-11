<?php
include __DIR__ . '/_site_nav.php';
$base = '/sites/' . $site['slug'];
$id = $post['id'] ?? 0;
$status = $post['status'] ?? 'draft';
$v = static fn (string $k): string => (string) ($values[$k] ?? '');
$err = static fn (string $k): string => isset($errors[$k]) ? '<p class="err">' . $e($t($errors[$k])) . '</p>' : '';
?>
<h2><?= $e($t($id ? 'post.edit' : 'post.new')) ?>
  <?php if ($id): ?><span class="pill <?= $e($status) ?>"><?= $e($t('status.' . $status)) ?></span><?php endif; ?></h2>
<?php if ($notice): ?><p class="ok"><?= $e($t('post.notice.' . $notice)) ?></p><?php endif; ?>
<?php if ($queryError): ?><p class="err"><?= $e($t($queryError)) ?></p><?php endif; ?>
<?php if ($status === 'published'): ?><p><small><?= $e($t('post.live_hint')) ?></small></p><?php endif; ?>

<?php if ($rs->canWrite() && ($status !== 'published' || $rs->canPublish())): ?>
<form method="post" action="<?= $e($base) ?>/posts<?= $id ? '/' . (int) $id : '' ?>" id="post-form" data-ai="<?= $e($base) ?>/ai">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <label for="title"><?= $e($t('post.title')) ?></label>
  <input id="title" name="title" required maxlength="255" value="<?= $e($v('title')) ?>">
  <?= $err('title') ?>
  <label><?= $e($t('post.body')) ?></label>
  <div class="rte">
    <div class="toolbar">
      <?php foreach (['bold' => '<b>B</b>', 'italic' => '<i>I</i>', 'h2' => 'H2', 'h3' => 'H3', 'p' => '¶', 'ul' => '• ', 'ol' => '1.', 'quote' => '“ ”', 'link' => 'Link', 'unlink' => '✂', 'clean' => 'Clear', 'undo' => '↶', 'redo' => '↷', 'source' => '&lt;/&gt;'] as $cmd => $label): ?>
        <button type="button" class="tb" data-cmd="<?= $e($cmd) ?>"><?= $label ?></button>
      <?php endforeach; ?>
    </div>
    <div id="rte" class="area" contenteditable="true" role="textbox" aria-multiline="true" aria-label="<?= $e($t('post.body')) ?>"><?= \OmniWrite\Support\Html::sanitize($v('body_html')) ?></div>
    <textarea id="rte-source" class="area" hidden rows="18"></textarea>
    <textarea name="body_html" id="body" hidden></textarea>
  </div>
  <?= $err('body_html') ?>
  <div class="card">
    <label for="ai-instr"><?= $e($t('ai.revise_label')) ?></label>
    <input id="ai-instr" maxlength="400" placeholder="<?= $e($t('ai.revise_hint')) ?>">
    <button type="button" id="ai-run"<?= empty($textReady) ? ' disabled' : '' ?>><?= $e($t('ai.revise_btn')) ?></button>
    <button type="button" id="ai-undo" hidden><?= $e($t('ai.undo_btn')) ?></button>
    <button type="button" id="ai-seo"<?= empty($textReady) ? ' disabled' : '' ?>><?= $e($t('ai.seo_btn')) ?></button>
    <small id="ai-msg"></small>
    <?php if (empty($textReady)): ?><small class="err ai-hint"><?= $e($t('err.ai_not_configured')) ?></small><?php endif; ?>
  </div>
  <details open><summary><?= $e($t('post.seo')) ?></summary>
    <label for="slug"><?= $e($t('post.slug')) ?></label>
    <input id="slug" name="slug" maxlength="80" value="<?= $e($v('slug')) ?>" placeholder="<?= $e($t('post.slug_hint')) ?>">
    <label for="excerpt"><?= $e($t('post.excerpt')) ?></label>
    <textarea id="excerpt" name="excerpt" rows="3" maxlength="600"><?= $e($v('excerpt')) ?></textarea>
    <label for="meta_description"><?= $e($t('post.meta')) ?></label>
    <textarea id="meta_description" name="meta_description" rows="2" maxlength="320"><?= $e($v('meta_description')) ?></textarea>
    <label for="keywords"><?= $e($t('post.keywords')) ?></label>
    <input id="keywords" name="keywords" maxlength="400" value="<?= $e($v('keywords')) ?>">
    <label for="category"><?= $e($t('post.category')) ?></label>
    <input id="category" name="category" maxlength="80" value="<?= $e($v('category')) ?>">
    <label for="cover_image"><?= $e($t('post.cover')) ?></label>
    <input id="cover_image" name="cover_image" maxlength="500" value="<?= $e($v('cover_image')) ?>" placeholder="https://…">
    <?= $err('cover_image') ?>
    <label for="image_prompt"><?= $e($t('ai.image_prompt')) ?></label>
    <textarea id="image_prompt" name="image_prompt" rows="2" maxlength="1000"><?= $e($v('image_prompt')) ?></textarea>
    <button type="button" id="img-gen"<?= empty($imageReady) ? ' disabled' : '' ?>><?= $e($t('ai.image_btn')) ?></button> <small id="img-msg"></small>
    <?php if (empty($imageReady)): ?><small class="err ai-hint"><?= $e($t('err.ai_image_not_configured')) ?></small><?php endif; ?>
    <label for="cover_alt"><?= $e($t('post.cover_alt')) ?></label>
    <input id="cover_alt" name="cover_alt" maxlength="255" value="<?= $e($v('cover_alt')) ?>">
  </details>
  <button><?= $e($t('btn.save')) ?></button>
</form>
<?php if ($id && $status !== 'published' && $rs->canWrite()): ?>
<form method="post" action="<?= $e($base) ?>/posts/<?= (int) $id ?>/send-review" class="card">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <p><?= $e($t('review.send_hint')) ?></p>
  <button><?= $e($t('review.send_btn')) ?></button>
</form>
<?php endif; ?>
<?php else: ?>
  <div class="card"><?= \OmniWrite\Support\Html::sanitize($v('body_html')) ?></div>
<?php endif; ?>

<?php if ($id): ?>
<div class="actions">
  <?php
  $actions = [];
  if ($rs->canWrite() && in_array($status, ['draft', 'declined'], true)) { $actions[] = 'submit'; }
  if ($rs->canPublish()) {
      if (in_array($status, ['draft', 'in_review', 'declined'], true)) { $actions[] = 'publish'; }
      if (in_array($status, ['draft', 'in_review'], true)) { $actions[] = 'decline'; }
      if ($status === 'published') { $actions[] = 'unpublish'; }
      if ($status !== 'published') { $actions[] = 'delete'; }
  }
  foreach ($actions as $a): ?>
    <form method="post" action="<?= $e($base) ?>/posts/<?= (int) $id ?>/<?= $e($a) ?>" style="display:inline">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
      <button <?= in_array($a, ['delete', 'decline', 'unpublish'], true) ? 'data-confirm="' . $e($t('confirm.' . $a)) . '"' : '' ?>><?= $e($t('action.' . $a)) ?></button>
    </form>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<script src="/assets/editor.js" defer></script>
