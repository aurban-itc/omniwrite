<?php include __DIR__ . '/_site_nav.php'; $base = '/sites/' . $site['slug']; ?>
<h2><?= $e($t('site.recipients')) ?></h2>
<p><small><?= $e($t('recipients.hint')) ?></small></p>
<?php if ($recipients === []): ?><p><?= $e($t('recipients.none')) ?></p><?php endif; ?>
<?php foreach ($recipients as $r): ?>
  <div class="card">
    <strong><?= $e($r['email']) ?></strong> <?= $r['name'] !== '' ? '(' . $e($r['name']) . ')' : '' ?>
    <span class="pill <?= (int) $r['active'] === 1 ? 'published' : '' ?>"><?= $e($t((int) $r['active'] === 1 ? 'recipients.active' : 'recipients.paused')) ?></span>
    <?php if ($rs->canPublish()): ?>
      <form method="post" action="<?= $e($base) ?>/recipients/<?= (int) $r['id'] ?>/toggle" style="display:inline">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="link"><?= $e($t((int) $r['active'] === 1 ? 'recipients.pause' : 'recipients.resume')) ?></button>
      </form>
      <form method="post" action="<?= $e($base) ?>/recipients/<?= (int) $r['id'] ?>/remove" style="display:inline">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="link" data-confirm="<?= $e($t('recipients.confirm_remove')) ?>"><?= $e($t('recipients.remove')) ?></button>
      </form>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
<?php if ($rs->canPublish()): ?>
<form method="post" action="<?= $e($base) ?>/recipients" class="card">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <label for="email"><?= $e($t('recipients.email')) ?></label>
  <input id="email" name="email" type="email" required maxlength="190" value="<?= $e($old['email'] ?? '') ?>">
  <?php if (!empty($errors['email'])): ?><p class="err"><?= $e($t($errors['email'])) ?></p><?php endif; ?>
  <label for="name"><?= $e($t('recipients.name')) ?></label>
  <input id="name" name="name" maxlength="120" value="<?= $e($old['name'] ?? '') ?>">
  <button><?= $e($t('recipients.add')) ?></button>
</form>
<?php endif; ?>
