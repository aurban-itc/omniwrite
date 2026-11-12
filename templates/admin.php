<h1><?= $e($t('admin.title')) ?></h1>
<p>
  <a href="/admin/accounts/new"><?= $e($t('admin.new_account')) ?></a> ·
  <a href="/admin/sites"><?= $e($t('admin.all_sites')) ?></a> ·
  <a href="/admin/usage"><?= $e($t('admin.usage')) ?></a> ·
  <a href="/admin/import"><?= $e($t('admin.import')) ?></a>
</p>
<h2><?= $e($t('admin.pending_users')) ?></h2>
<?php if ($pending === []): ?><p><?= $e($t('admin.none')) ?></p><?php endif; ?>
<?php foreach ($pending as $p): ?>
  <div class="card">
    <?= $e($p->name) ?> &lt;<?= $e($p->email) ?>&gt;
    <form method="post" action="/admin/users/<?= (int) $p->id ?>/activate" style="display:inline">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button><?= $e($t('btn.activate')) ?></button>
    </form>
    <form method="post" action="/admin/users/<?= (int) $p->id ?>/reject" style="display:inline">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button><?= $e($t('btn.reject')) ?></button>
    </form>
  </div>
<?php endforeach; ?>
<h2><?= $e($t('admin.accounts')) ?></h2>
<?php if ($accounts === []): ?><p><?= $e($t('admin.no_accounts')) ?> <a href="/admin/accounts/new"><?= $e($t('admin.new_account')) ?></a></p><?php endif; ?>
<?php foreach ($accounts as $a): ?>
  <div class="card"><a href="/admin/accounts/<?= (int) $a['id'] ?>"><?= $e($a['name']) ?></a> · <?= $e($t('admin.sites_count', ['n' => $a['sites']])) ?></div>
<?php endforeach; ?>
<h2><?= $e($t('admin.users')) ?></h2>
<?php if ($impersonable === []): ?><p><?= $e($t('admin.none')) ?></p><?php endif; ?>
<?php foreach ($impersonable as $u): ?>
  <div class="card">
    <?= $e($u['name']) ?> &lt;<?= $e($u['email']) ?>&gt; · <?= $e($u['account']) ?>
    <form method="post" action="/admin/impersonate/<?= (int) $u['id'] ?>" style="display:inline">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button><?= $e($t('imp.start')) ?></button>
    </form>
  </div>
<?php endforeach; ?>
