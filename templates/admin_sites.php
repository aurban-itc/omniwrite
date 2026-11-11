<p><a href="/admin">&larr; <?= $e($t('admin.title')) ?></a></p>
<h1><?= $e($t('admin.all_sites')) ?></h1>
<?php if ($sites === []): ?><p><?= $e($t('dashboard.no_sites')) ?> <a href="/admin/accounts/new"><?= $e($t('admin.new_account')) ?></a></p><?php endif; ?>
<?php foreach ($sites as $s): ?>
  <div class="card"><strong><?= $e($s['name']) ?></strong> <small>/b/<?= $e($s['slug']) ?>/ · <?= $e($s['locale']) ?></small><br>
    <a href="/admin/accounts/<?= (int) $s['account_id'] ?>"><?= $e($s['account_name']) ?></a> ·
    <?= $e($t('admin.posts_count', ['n' => (int) $s['post_count'], 'p' => (int) $s['published_count']])) ?><br>
    <a href="/sites/<?= $e($s['slug']) ?>/posts"><?= $e($t('site.posts')) ?></a> ·
    <a href="/sites/<?= $e($s['slug']) ?>/topics"><?= $e($t('site.topics')) ?></a> ·
    <a href="/sites/<?= $e($s['slug']) ?>/settings"><?= $e($t('site.settings')) ?></a> ·
    <a href="/b/<?= $e($s['slug']) ?>/"><?= $e($t('admin.public_view')) ?></a></div>
<?php endforeach; ?>
