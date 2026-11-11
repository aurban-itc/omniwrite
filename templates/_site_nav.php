<?php /** Untermenü einer Site. Erwartet $site, $e, $t; optional $active. */ ?>
<h1><?= $e($site['name']) ?> <small>/b/<?= $e($site['slug']) ?>/</small></h1>
<nav class="subnav">
<?php foreach (['posts' => 'site.posts', 'topics' => 'site.topics', 'recipients' => 'site.recipients', 'schedule' => 'site.schedule', 'blog' => 'blog.nav', 'connectors' => 'site.connectors', 'settings' => 'site.settings'] as $__k => $__l):
  $__href = '/sites/' . $site['slug'] . '/' . $__k;
  $__cur = str_starts_with(strtok($_SERVER['REQUEST_URI'] ?? '', '?') ?: '', $__href); ?>
  <a href="<?= $e($__href) ?>"<?= $__cur ? ' class="active" aria-current="page"' : '' ?>><?= $e($t($__l)) ?></a>
<?php endforeach; ?>
</nav>
