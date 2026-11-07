<?php include __DIR__ . '/_site_nav.php'; $base = '/sites/' . $site['slug']; ?>
<?php if ($notice === 'deleted'): ?><p class="ok"><?= $e($t('post.deleted')) ?></p><?php endif; ?>
<p>
  <?php if ($rs->canWrite()): ?><a href="<?= $e($base) ?>/posts/new"><strong><?= $e($t('post.new')) ?></strong></a> · <?php endif; ?>
  <a href="<?= $e($base) ?>/posts"><?= $e($t('post.filter_all')) ?></a>
  <?php foreach (['draft', 'in_review', 'published', 'declined'] as $st): ?>
    · <a href="<?= $e($base) ?>/posts?status=<?= $e($st) ?>"><?= $e($t('status.' . $st)) ?></a>
  <?php endforeach; ?>
</p>
<?php if ($posts === []): ?><p><?= $e($t('post.none')) ?></p><?php endif; ?>
<?php foreach ($posts as $p): ?>
  <div class="card">
    <a href="<?= $e($base) ?>/posts/<?= (int) $p['id'] ?>"><strong><?= $e($p['title']) ?></strong></a>
    <span class="pill <?= $e($p['status']) ?>"><?= $e($t('status.' . $p['status'])) ?></span><br>
    <small><?= $e($p['slug']) ?> · <?= (int) $p['read_minutes'] ?> min<?= $p['category'] !== '' ? ' · ' . $e($p['category']) : '' ?>
    <?= !empty($p['published_at']) ? ' · ' . $e(substr((string) $p['published_at'], 0, 10)) : '' ?></small>
  </div>
<?php endforeach; ?>
