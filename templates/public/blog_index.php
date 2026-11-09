<h1><?= $e($b->name()) ?></h1>
<?php if ($posts === []): ?>
  <p class="meta"><?= $e($t('blog.empty')) ?></p>
<?php endif; ?>
<?php foreach ($posts as $p): ?>
  <article class="card">
    <?php if ((string) $p['cover_image'] !== ''): ?>
      <a href="<?= $e($b->link('/' . rawurlencode((string) $p['slug']))) ?>"><img src="<?= $e($p['cover_image']) ?>" alt="<?= $e($p['cover_alt']) ?>" loading="lazy"></a>
    <?php endif; ?>
    <p class="meta"><?= $e($date($p['published_at'] ?? $p['created_at'] ?? null)) ?><?= (string) $p['category'] !== '' ? ' · ' . $e($p['category']) : '' ?> · <?= $e($t('blog.read_minutes', ['n' => (int) $p['read_minutes']])) ?></p>
    <h2><a href="<?= $e($b->link('/' . rawurlencode((string) $p['slug']))) ?>"><?= $e($p['title']) ?></a></h2>
    <p><?= $e($p['excerpt']) ?></p>
  </article>
<?php endforeach; ?>
<?php if ($pages > 1): ?>
  <nav class="pager">
    <span><?php if ($page > 1): ?><a href="<?= $e($b->link('/') . ($page > 2 ? '?page=' . ($page - 1) : '')) ?>">← <?= $e($t('blog.newer')) ?></a><?php endif; ?></span>
    <span><?php if ($page < $pages): ?><a href="<?= $e($b->link('/') . '?page=' . ($page + 1)) ?>"><?= $e($t('blog.older')) ?> →</a><?php endif; ?></span>
  </nav>
<?php endif; ?>
