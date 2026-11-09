<?php
/** Öffentliche Blog-Hülle. Erwartet $b (BlogView), $title, $description, $canonical, $content, optional $jsonLd, $image. */
$accent = $b->theme['theme_accent'];
$serif = ($b->theme['theme_font'] ?? 'sans') === 'serif';
?><!doctype html>
<html lang="<?= $e($b->site['locale']) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($title) ?></title>
<?php if ($description !== ''): ?><meta name="description" content="<?= $e($description) ?>">
<?php endif; ?>
<link rel="canonical" href="<?= $e($canonical) ?>">
<link rel="alternate" type="application/rss+xml" title="<?= $e($b->name()) ?>" href="<?= $e($b->link('/feed.xml')) ?>">
<meta property="og:title" content="<?= $e($title) ?>">
<meta property="og:type" content="<?= $jsonLd !== null ? 'article' : 'website' ?>">
<meta property="og:url" content="<?= $e($canonical) ?>">
<?php if ($description !== ''): ?><meta property="og:description" content="<?= $e($description) ?>">
<?php endif; if (!empty($image)): ?><meta property="og:image" content="<?= $e($image) ?>">
<?php endif; ?>
<style>
:root{--accent:<?= $e($accent) ?>;--bg:#fff;--fg:#1b1f24;--muted:#5b6570;--line:#e3e6ea}
@media (prefers-color-scheme:dark){:root{--bg:#14171a;--fg:#e8eaed;--muted:#9aa4af;--line:#2a2f35}}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--fg);font:18px/1.65 <?= $serif ? "Georgia,'Times New Roman',serif" : "system-ui,-apple-system,'Segoe UI',Roboto,sans-serif" ?>}
a{color:var(--accent)}
.wrap{max-width:46rem;margin:0 auto;padding:0 1rem}
header.site{border-bottom:1px solid var(--line);padding:1.2rem 0}
header.site .wrap{display:flex;justify-content:space-between;align-items:baseline;gap:1rem;flex-wrap:wrap}
header.site a.brand{font-weight:700;font-size:1.25rem;text-decoration:none;color:var(--fg)}
header.site small{color:var(--muted)}
main{padding:2rem 0 3rem}
h1{line-height:1.2;font-size:2rem;margin:.2em 0 .4em}
h2,h3{line-height:1.3}
.meta{color:var(--muted);font-size:.9rem}
article.card{padding:1.2rem 0;border-bottom:1px solid var(--line)}
article.card h2{margin:.1em 0}
article.card h2 a{text-decoration:none;color:var(--fg)}
article.card img,.post img{max-width:100%;height:auto;border-radius:6px}
.post blockquote{margin:1.2rem 0;padding-left:1rem;border-left:3px solid var(--accent);color:var(--muted)}
.post table{border-collapse:collapse;width:100%}.post td,.post th{border:1px solid var(--line);padding:.4rem .6rem;text-align:left}
nav.pager{display:flex;justify-content:space-between;margin-top:2rem}
footer.site{border-top:1px solid var(--line);padding:1.5rem 0;color:var(--muted);font-size:.9rem}
</style>
<?php if ($jsonLd !== null): ?><script type="application/ld+json"><?= $jsonLd ?></script>
<?php endif; ?>
</head>
<body>
<header class="site"><div class="wrap">
  <a class="brand" href="<?= $e($b->link('/')) ?>"><?= $e($b->name()) ?></a>
  <?php if (($b->theme['blog_tagline'] ?? '') !== ''): ?><small><?= $e($b->theme['blog_tagline']) ?></small><?php endif; ?>
</div></header>
<main><div class="wrap"><?= $content ?></div></main>
<footer class="site"><div class="wrap">
  <?php if (($b->theme['blog_footer'] ?? '') !== ''): ?><p><?= $e($b->theme['blog_footer']) ?></p><?php endif; ?>
  <p>
    <?php if (($b->theme['home_url'] ?? '') !== ''): ?><a href="<?= $e($b->theme['home_url']) ?>" rel="noopener"><?= $e($t('blog.home')) ?></a> · <?php endif; ?>
    <a href="<?= $e($b->link('/feed.xml')) ?>">RSS</a>
  </p>
</div></footer>
</body>
</html>
