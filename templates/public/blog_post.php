<article class="post">
  <p class="meta"><a href="<?= $e($b->link('/')) ?>">← <?= $e($b->name()) ?></a></p>
  <h1><?= $e($post['title']) ?></h1>
  <p class="meta"><?= $e($date($post['published_at'] ?? $post['created_at'] ?? null)) ?><?= (string) $post['category'] !== '' ? ' · ' . $e($post['category']) : '' ?> · <?= $e($t('blog.read_minutes', ['n' => (int) $post['read_minutes']])) ?></p>
  <?php if ((string) $post['cover_image'] !== ''): ?>
    <img src="<?= $e($post['cover_image']) ?>" alt="<?= $e($post['cover_alt']) ?>">
  <?php endif; ?>
  <?= $post['body_html'] /* beim Speichern per Allowlist gesäubert (Html::sanitize) */ ?>
</article>
