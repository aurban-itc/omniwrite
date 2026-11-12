<p><a href="/admin">&larr; <?= $e($t('admin.title')) ?></a></p>
<h1><?= $e($t('admin.usage_title')) ?>: <?= $e($month) ?></h1>
<p>
  <a href="/admin/usage?month=<?= $e($prev) ?>">&larr; <?= $e($t('admin.usage_prev')) ?></a>
  <?php if (!$isCurrent): ?> · <a href="/admin/usage?month=<?= $e($next) ?>"><?= $e($t('admin.usage_next')) ?> &rarr;</a><?php endif; ?>
  · <a href="/admin/usage?month=<?= $e($month) ?>&amp;format=csv"><?= $e($t('admin.usage_csv')) ?></a>
</p>
<p><small><?= $e($t('admin.usage_hint')) ?></small></p>
<?php if ($report === []): ?><p><?= $e($t('admin.usage_none')) ?></p><?php endif; ?>
<?php foreach ($report as $a): ?>
  <div class="card">
    <strong><a href="/admin/accounts/<?= (int) $a['account_id'] ?>"><?= $e($a['account_name']) ?></a></strong>
    <?php if ($a['plan'] !== ''): ?><small>· <?= $e($a['plan']) ?><?= $a['posts_limit'] !== null ? ' · ' . $e($t('admin.usage_limit')) . ' ' . (int) $a['posts_limit'] : '' ?></small><?php endif; ?>
    <table>
      <thead><tr><th><?= $e($t('admin.usage_site')) ?></th><th><?= $e($t('admin.usage_posts')) ?></th><th><?= $e($t('admin.usage_texts')) ?></th><th><?= $e($t('admin.usage_images')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($a['sites'] as $s): ?>
        <tr><td><?= $e($s['name']) ?> <small>/<?= $e($s['slug']) ?></small></td><td><?= (int) $s['ai_post'] ?></td><td><?= (int) $s['ai_text'] ?></td><td><?= (int) $s['ai_image'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
      <?php if (count($a['sites']) > 1): ?>
      <tfoot><tr><th><?= $e($t('admin.usage_total')) ?></th><th><?= (int) $a['ai_post'] ?></th><th><?= (int) $a['ai_text'] ?></th><th><?= (int) $a['ai_image'] ?></th></tr></tfoot>
      <?php endif; ?>
    </table>
  </div>
<?php endforeach; ?>
