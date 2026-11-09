<?php include __DIR__ . '/_site_nav.php'; $base = '/sites/' . $site['slug']; ?>
<h2><?= $e($t('site.schedule')) ?></h2>
<p><small><?= $e($t('schedule.hint', ['tz' => $site['timezone']])) ?></small></p>
<?php if ($saved): ?><p class="ok"><?= $e($t('settings.saved')) ?></p><?php endif; ?>
<?php if ($result): ?><p class="<?= $result['status'] === 'ok' ? 'ok' : 'err' ?>"><?= $e($result['message']) ?></p><?php endif; ?>
<form method="post" action="<?= $e($base) ?>/schedule">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <label><input type="checkbox" name="enabled" value="1" <?= $cfg['enabled'] ? 'checked' : '' ?> <?= $rs->canPublish() ? '' : 'disabled' ?> style="width:auto"> <?= $e($t('schedule.enabled')) ?></label>
  <label for="weekday"><?= $e($t('schedule.weekday')) ?></label>
  <select id="weekday" name="weekday" <?= $rs->canPublish() ? '' : 'disabled' ?>>
    <?php for ($d = 1; $d <= 7; $d++): ?><option value="<?= $d ?>" <?= $cfg['weekday'] === $d ? 'selected' : '' ?>><?= $e($t('weekday.' . $d)) ?></option><?php endfor; ?>
  </select>
  <label for="hour"><?= $e($t('schedule.hour')) ?></label>
  <select id="hour" name="hour" <?= $rs->canPublish() ? '' : 'disabled' ?>>
    <?php for ($h = 0; $h < 24; $h++): ?><option value="<?= $h ?>" <?= $cfg['hour'] === $h ? 'selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option><?php endfor; ?>
  </select>
  <?php if ($rs->canPublish()): ?><button><?= $e($t('btn.save')) ?></button><?php endif; ?>
</form>
<?php if ($rs->canPublish()): ?>
<form method="post" action="<?= $e($base) ?>/schedule/run" class="card">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <p><?= $e($t('schedule.run_hint')) ?></p>
  <button data-confirm="<?= $e($t('schedule.run_confirm')) ?>"><?= $e($t('schedule.run_now')) ?></button>
</form>
<?php endif; ?>
<h3><?= $e($t('schedule.runs')) ?></h3>
<?php if ($runs === []): ?><p><?= $e($t('schedule.no_runs')) ?></p><?php endif; ?>
<?php foreach ($runs as $run): ?>
  <p><span class="pill <?= $run['status'] === 'ok' ? 'published' : 'declined' ?>"><?= $e($run['status']) ?></span>
    <small><?= $e($run['created_at']) ?> UTC</small><br><?= $e($run['message']) ?>
    <?php if (!empty($run['post_id'])): ?> <a href="<?= $e($base) ?>/posts/<?= (int) $run['post_id'] ?>"><?= $e($t('schedule.open_post')) ?></a><?php endif; ?></p>
<?php endforeach; ?>
