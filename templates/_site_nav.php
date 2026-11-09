<?php /** Untermenü einer Site. Erwartet $site, $e, $t; optional $active. */ ?>
<h1><?= $e($site['name']) ?> <small>/b/<?= $e($site['slug']) ?>/</small></h1>
<p>
  <a href="/sites/<?= $e($site['slug']) ?>/posts"><?= $e($t('site.posts')) ?></a> ·
  <a href="/sites/<?= $e($site['slug']) ?>/topics"><?= $e($t('site.topics')) ?></a> ·
  <a href="/sites/<?= $e($site['slug']) ?>/recipients"><?= $e($t('site.recipients')) ?></a> ·
  <a href="/sites/<?= $e($site['slug']) ?>/schedule"><?= $e($t('site.schedule')) ?></a> ·
  <a href="/sites/<?= $e($site['slug']) ?>/blog"><?= $e($t('blog.nav')) ?></a> ·
  <a href="/sites/<?= $e($site['slug']) ?>/settings"><?= $e($t('site.settings')) ?></a>
</p>
