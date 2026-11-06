<h1><?= $e($t('admin.title')) ?></h1>
<h2><?= $e($t('admin.pending_users')) ?></h2>
<?php if ($pending === []): ?><p><?= $e($t('admin.none')) ?></p><?php endif; ?>
<?php foreach ($pending as $p): ?>
  <div class="card">
    <?= $e($p->name) ?> &lt;<?= $e($p->email) ?>&gt;
    <form method="post" action="/admin/users/<?= (int) $p->id ?>/activate" style="display:inline">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button><?= $e($t('btn.activate')) ?></button>
    </form>
  </div>
<?php endforeach; ?>
<h2><?= $e($t('admin.accounts')) ?></h2>
<?php foreach ($accounts as $a): ?>
  <div class="card"><?= $e($a['name']) ?> · <?= $e($t('admin.sites_count', ['n' => $a['sites']])) ?></div>
<?php endforeach; ?>
