<?php
$mode = $mode ?? 'expired';
$v = static fn (string $k): string => (string) ($values[$k] ?? '');
$err = static fn (string $k): string => isset($errors[$k]) ? '<p class="err">' . htmlspecialchars($t($errors[$k]), ENT_QUOTES, 'UTF-8') . '</p>' : '';
$token = (string) ($token ?? '');
?>
<?php if ($mode === 'expired'): ?>
  <h2><?= $e($t('review.expired_title')) ?></h2><p><?= $e($t('review.expired')) ?></p>
<?php elseif ($mode === 'decided'): ?>
  <h2><?= $e($t('review.decided_title')) ?></h2><p><?= $e($t('review.decided', ['title' => $post['title'], 'status' => $t('status.' . $post['status']), 'by' => (string) ($post['reviewed_by'] ?? '')])) ?></p>
<?php elseif ($mode === 'done_publish'): ?>
  <h2><?= $e($t('review.done_title')) ?></h2><p><?= $e($t('review.done_publish', ['title' => $post['title']])) ?></p>
<?php elseif ($mode === 'done_decline'): ?>
  <h2><?= $e($t('review.done_title')) ?></h2><p><?= $e($t('review.done_decline', ['title' => $post['title']])) ?></p>
<?php elseif ($mode === 'publish' || $mode === 'decline'): ?>
  <h2><?= $e($t('review.' . $mode . '_title')) ?></h2>
  <p><strong><?= $e($post['title']) ?></strong></p>
  <?php if ($mode === 'publish'): ?><p><?= $e($t('review.publish_hint')) ?></p><?php endif; ?>
  <div class="card"><?= \OmniWrite\Support\Html::sanitize((string) $post['body_html']) ?></div>
  <form method="post" action="/review/<?= $e($token) ?>/<?= $e($mode) ?>">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <?php if ($mode === 'decline'): ?>
      <label for="note"><?= $e($t('review.note')) ?></label>
      <textarea id="note" name="note" rows="3" maxlength="600"></textarea>
    <?php endif; ?>
    <button><?= $e($t('review.' . $mode . '_btn')) ?></button>
    <a href="/review/<?= $e($token) ?>/edit"><?= $e($t('review.edit_first')) ?></a>
  </form>
<?php elseif ($mode === 'edit'): ?>
  <h2><?= $e($t('review.edit_title')) ?></h2>
  <?php if ($saved): ?><p class="ok"><?= $e($t('post.notice.saved')) ?></p><?php endif; ?>
  <form method="post" action="/review/<?= $e($token) ?>/edit" id="post-form">
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
    <label for="excerpt"><?= $e($t('post.excerpt')) ?></label>
    <textarea id="excerpt" name="excerpt" rows="3" maxlength="600"><?= $e($v('excerpt')) ?></textarea>
    <label for="meta_description"><?= $e($t('post.meta')) ?></label>
    <textarea id="meta_description" name="meta_description" rows="2" maxlength="320"><?= $e($v('meta_description')) ?></textarea>
    <button><?= $e($t('btn.save')) ?></button>
  </form>
  <p>
    <a href="/review/<?= $e($token) ?>/publish"><?= $e($t('review.publish_btn')) ?></a> ·
    <a href="/review/<?= $e($token) ?>/decline"><?= $e($t('review.decline_btn')) ?></a>
  </p>
  <script src="/assets/editor.js" defer></script>
<?php endif; ?>
