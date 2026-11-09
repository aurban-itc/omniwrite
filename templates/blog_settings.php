<?php include __DIR__ . '/_site_nav.php';
$slug = $e($site['slug']);
$can = $rs->canPublish();
?>
<h2><?= $e($t('blog.title')) ?></h2>
<?php if ($notice === 'saved' || $notice === 'domain_saved'): ?><p class="ok"><?= $e($t('blog.saved')) ?></p><?php endif; ?>
<?php if ($notice === 'domain_verified'): ?><p class="ok"><?= $e($t('domain.verified_notice')) ?></p><?php endif; ?>
<?php if ($notice === 'domain_dns'): ?><p class="err"><?= $e($t('err.domain_dns')) ?></p><?php endif; ?>
<p><?= $e($t('blog.public_url')) ?>: <a href="<?= $e($publicUrl) ?>"><?= $e($publicUrl) ?></a></p>

<h3><?= $e($t('blog.theme')) ?></h3>
<form method="post" action="/sites/<?= $slug ?>/blog">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <label for="blog_tagline"><?= $e($t('blog.tagline')) ?></label>
  <input id="blog_tagline" name="blog_tagline" maxlength="160" value="<?= $e($values['blog_tagline'] ?? '') ?>" <?= $can ? '' : 'readonly' ?>>
  <?php if (!empty($errors['blog_tagline'])): ?><p class="err"><?= $e($t($errors['blog_tagline'])) ?></p><?php endif; ?>
  <label for="theme_accent"><?= $e($t('blog.accent')) ?></label>
  <input id="theme_accent" name="theme_accent" maxlength="7" value="<?= $e($values['theme_accent'] ?? '') ?>" <?= $can ? '' : 'readonly' ?>>
  <?php if (!empty($errors['theme_accent'])): ?><p class="err"><?= $e($t($errors['theme_accent'])) ?></p><?php endif; ?>
  <label for="theme_font"><?= $e($t('blog.font')) ?></label>
  <select id="theme_font" name="theme_font" <?= $can ? '' : 'disabled' ?>>
    <?php foreach (['sans', 'serif'] as $f): ?>
      <option value="<?= $f ?>" <?= ($values['theme_font'] ?? 'sans') === $f ? 'selected' : '' ?>><?= $e($t('blog.font_' . $f)) ?></option>
    <?php endforeach; ?>
  </select>
  <label for="blog_footer"><?= $e($t('blog.footer')) ?></label>
  <input id="blog_footer" name="blog_footer" maxlength="300" value="<?= $e($values['blog_footer'] ?? '') ?>" <?= $can ? '' : 'readonly' ?>>
  <?php if (!empty($errors['blog_footer'])): ?><p class="err"><?= $e($t($errors['blog_footer'])) ?></p><?php endif; ?>
  <label for="home_url"><?= $e($t('blog.home_url')) ?></label>
  <input id="home_url" name="home_url" maxlength="300" value="<?= $e($values['home_url'] ?? '') ?>" <?= $can ? '' : 'readonly' ?>>
  <?php if (!empty($errors['home_url'])): ?><p class="err"><?= $e($t($errors['home_url'])) ?></p><?php endif; ?>
  <p><small><?= $e($t('settings.reset_hint')) ?></small></p>
  <?php if ($can): ?><button><?= $e($t('btn.save')) ?></button><?php endif; ?>
</form>

<h3><?= $e($t('domain.title')) ?></h3>
<p><small><?= $e($t('domain.help', ['target' => $portalHost])) ?></small></p>
<?php if ($domain !== null): ?>
  <p class="<?= $domain['verified_at'] !== null ? 'ok' : '' ?>">
    <?= $e($t($domain['verified_at'] !== null ? 'domain.status_ok' : 'domain.status_pending', ['host' => $domain['host']])) ?>
  </p>
<?php endif; ?>
<?php if ($can): ?>
  <form method="post" action="/sites/<?= $slug ?>/blog/domain">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <label for="domain"><?= $e($t('domain.host')) ?></label>
    <input id="domain" name="domain" maxlength="190" value="<?= $e($domain['host'] ?? '') ?>">
    <?php if (!empty($errors['domain'])): ?><p class="err"><?= $e($t($errors['domain'])) ?></p><?php endif; ?>
    <button><?= $e($t('domain.set')) ?></button>
  </form>
  <?php if ($domain !== null): ?>
    <form method="post" action="/sites/<?= $slug ?>/blog/domain/verify" style="display:inline">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button><?= $e($t('domain.verify')) ?></button>
    </form>
    <form method="post" action="/sites/<?= $slug ?>/blog/domain/remove" style="display:inline">
      <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button data-confirm="<?= $e($t('domain.remove_confirm')) ?>"><?= $e($t('domain.remove')) ?></button>
    </form>
  <?php endif; ?>
<?php endif; ?>
