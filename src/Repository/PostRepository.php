<?php
declare(strict_types=1);

namespace OmniWrite\Repository;

/** Beispiel-Repository; wird in Phase 2 (Extraktion) um Status, Slug, Veröffentlichung erweitert. */
final class PostRepository extends SiteScopedRepository
{
    protected function table(): string
    {
        return 'posts';
    }

    protected function columns(): array
    {
        return ['title', 'slug', 'body_html', 'status'];
    }
}
