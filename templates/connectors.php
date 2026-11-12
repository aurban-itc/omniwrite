<?php include __DIR__ . '/_site_nav.php'; $base = '/sites/' . $site['slug']; $kind = $old['kind'] ?? 'webhook'; ?>
<h2><?= $e($t('site.connectors')) ?></h2>
<p><small><?= $e($t('connectors.hint')) ?></small></p>
<?php if ($result !== []): ?>
  <div class="card ok"><?php foreach ($result as $line): ?><?= $e($line) ?><br><?php endforeach; ?></div>
<?php endif; ?>
<?php if ($targets === []): ?><p><?= $e($t('connectors.none')) ?></p><?php endif; ?>
<?php foreach ($targets as $c): ?>
  <div class="card">
    <strong><?= $e($c['name']) ?></strong>
    <span class="pill"><?= $e($t('connectors.kind_' . $c['kind'])) ?></span>
    <span class="pill <?= $c['active'] ? 'published' : '' ?>"><?= $e($t($c['active'] ? 'recipients.active' : 'recipients.paused')) ?></span><br>
    <small><code><?= $e($c['summary']) ?></code></small><br>
    <small><?= $e($t('connectors.state', ['ok' => $c['counts']['ok'], 'failed' => $c['counts']['failed'], 'pending' => $c['counts']['pending']])) ?></small>
    <?php if ($c['error'] !== ''): ?><br><small class="err"><?= $e($c['error']) ?></small><?php endif; ?>
    <?php if ($rs->canPublish()): ?>
      <form method="post" action="<?= $e($base) ?>/connectors/<?= (int) $c['id'] ?>/send" style="display:inline">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="link"><?= $e($t('connectors.send')) ?></button>
      </form>
      <form method="post" action="<?= $e($base) ?>/connectors/<?= (int) $c['id'] ?>/toggle" style="display:inline">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="link"><?= $e($t($c['active'] ? 'recipients.pause' : 'recipients.resume')) ?></button>
      </form>
      <form method="post" action="<?= $e($base) ?>/connectors/<?= (int) $c['id'] ?>/remove" style="display:inline">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="link" data-confirm="<?= $e($t('connectors.confirm_remove')) ?>"><?= $e($t('recipients.remove')) ?></button>
      </form>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
<?php if ($rs->canPublish()): ?>
<?php if (!empty($errors['kind'])): ?><p class="err"><?= $e($t($errors['kind'])) ?></p><?php endif; ?>
<form method="post" action="<?= $e($base) ?>/connectors" class="card">
  <h3><?= $e($t('connectors.add_webhook')) ?></h3>
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="kind" value="webhook">
  <label for="w_name"><?= $e($t('connectors.name')) ?></label>
  <input id="w_name" name="name" maxlength="120" value="<?= $e($kind === 'webhook' ? ($old['name'] ?? '') : '') ?>">
  <label for="w_url"><?= $e($t('connectors.url')) ?></label>
  <input id="w_url" name="url" type="url" required maxlength="300" placeholder="https://" value="<?= $e($kind === 'webhook' ? ($old['url'] ?? '') : '') ?>">
  <?php if ($kind === 'webhook' && !empty($errors['url'])): ?><p class="err"><?= $e($t($errors['url'])) ?></p><?php endif; ?>
  <label for="w_secret"><?= $e($t('connectors.secret')) ?></label>
  <input id="w_secret" name="secret" type="password" required minlength="16" maxlength="200" autocomplete="new-password">
  <?php if ($kind === 'webhook' && !empty($errors['secret'])): ?><p class="err"><?= $e($t($errors['secret'])) ?></p><?php endif; ?>
  <p><small><?= $e($t('connectors.webhook_hint')) ?></small></p>
  <button><?= $e($t('recipients.add')) ?></button>
</form>
<form method="post" action="<?= $e($base) ?>/connectors" class="card">
  <h3><?= $e($t('connectors.add_github')) ?></h3>
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="kind" value="github">
  <label for="g_name"><?= $e($t('connectors.name')) ?></label>
  <input id="g_name" name="name" maxlength="120" value="<?= $e($kind === 'github' ? ($old['name'] ?? '') : '') ?>">
  <label for="g_repo"><?= $e($t('connectors.repo')) ?></label>
  <input id="g_repo" name="repo" required maxlength="201" placeholder="owner/name" value="<?= $e($kind === 'github' ? ($old['repo'] ?? '') : '') ?>">
  <?php if ($kind === 'github' && !empty($errors['repo'])): ?><p class="err"><?= $e($t($errors['repo'])) ?></p><?php endif; ?>
  <label for="g_branch"><?= $e($t('connectors.branch')) ?></label>
  <input id="g_branch" name="branch" maxlength="100" placeholder="main" value="<?= $e($kind === 'github' ? ($old['branch'] ?? '') : '') ?>">
  <?php if ($kind === 'github' && !empty($errors['branch'])): ?><p class="err"><?= $e($t($errors['branch'])) ?></p><?php endif; ?>
  <label for="g_dir"><?= $e($t('connectors.dir')) ?></label>
  <input id="g_dir" name="dir" maxlength="150" placeholder="content/posts" value="<?= $e($kind === 'github' ? ($old['dir'] ?? 'content/posts') : 'content/posts') ?>">
  <?php if ($kind === 'github' && !empty($errors['dir'])): ?><p class="err"><?= $e($t($errors['dir'])) ?></p><?php endif; ?>
  <label for="g_ext"><?= $e($t('connectors.ext')) ?></label>
  <select id="g_ext" name="ext"><option value="md">.md</option><option value="html"<?= $kind === 'github' && ($old['ext'] ?? '') === 'html' ? ' selected' : '' ?>>.html</option></select>
  <label for="g_token"><?= $e($t('connectors.token')) ?></label>
  <input id="g_token" name="token" type="password" required maxlength="255" autocomplete="new-password">
  <?php if ($kind === 'github' && !empty($errors['token'])): ?><p class="err"><?= $e($t($errors['token'])) ?></p><?php endif; ?>
  <p><small><?= $e($t('connectors.github_hint')) ?></small></p>
  <button><?= $e($t('recipients.add')) ?></button>
</form>
<?php endif; ?>
