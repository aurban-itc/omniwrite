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
    <span class="pill <?= $e($p['status']) ?>"><?= $e($t('status.' . $p['status'])) ?></span>
    <?php if ($p['status'] === 'published'): ?><a href="/b/<?= $e(rawurlencode((string) $site['slug'])) ?>/<?= $e(rawurlencode((string) $p['slug'])) ?>" target="_blank" rel="noopener"><?= $e($t('post.view_live')) ?></a><?php endif; ?><br>
    <small><?= $e($p['slug']) ?> · <?= (int) $p['read_minutes'] ?> min<?= $p['category'] !== '' ? ' · ' . $e($p['category']) : '' ?>
    <?= !empty($p['published_at']) ? ' · ' . $e(substr((string) $p['published_at'], 0, 10)) : '' ?></small>
  </div>
<?php endforeach; ?>
<?php
$q = static fn (int $page): string => '?' . http_build_query(array_filter(['status' => $filter, 'page' => $page > 1 ? $page : null]));
?>
<?php if ($paging['page'] > 1): ?><a href="<?= $e($base) ?>/posts<?= $e($q($paging['page'] - 1)) ?>">← <?= $e($t('page.newer')) ?></a><?php endif; ?>
<?php if ($paging['has_more']): ?> <a href="<?= $e($base) ?>/posts<?= $e($q($paging['page'] + 1)) ?>"><?= $e($t('page.older')) ?> →</a><?php endif; ?>
