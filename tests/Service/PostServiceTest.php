<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Service;

use OmniWrite\Domain\AccessDeniedException;
use OmniWrite\Domain\NotFoundException;
use OmniWrite\Domain\Role;
use OmniWrite\Domain\ValidationException;
use OmniWrite\Service\PostService;
use OmniWrite\Tests\Support\DbTestCase;
use OmniWrite\Tests\Support\Fixtures;

final class PostServiceTest extends DbTestCase
{
    private Fixtures $fx;
    private PostService $posts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fx = new Fixtures($this->pdo);
        $this->posts = new PostService($this->pdo, $this->fx->audit);
    }

    /** @return array{0: \OmniWrite\Domain\User, 1: \OmniWrite\Service\ResolvedSite} */
    private function owner(string $email = 'o@example.com', string $slug = 'alpha'): array
    {
        [$acc, $slug] = $this->fx->accountWithSite($slug);
        $user = $this->fx->user($email);
        $this->fx->member($acc, $user, Role::Owner);
        return [$user, $this->fx->resolve($user, $slug)];
    }

    public function testCreateSanitizesFillsDefaultsAndStartsAsDraft(): void
    {
        [$u, $s] = $this->owner();
        $id = $this->posts->save($s, $u, 0, ['title' => 'Grüße & mehr', 'body_html' => '<p>Hallo <script>x</script>Welt</p>']);
        $post = $this->posts->get($s, $id);
        self::assertSame('draft', $post['status']);
        self::assertSame('gruesse-mehr', $post['slug']);
        self::assertStringNotContainsString('script', (string) $post['body_html']);
        self::assertSame('Hallo Welt', $post['excerpt']);
        self::assertSame('Hallo Welt', $post['meta_description']);
        self::assertSame(1, (int) $post['read_minutes']);
    }

    public function testValidationErrors(): void
    {
        [$u, $s] = $this->owner();
        try {
            $this->posts->save($s, $u, 0, ['title' => '', 'body_html' => '<p> </p>', 'cover_image' => 'javascript:alert(1)']);
            self::fail('Validation erwartet');
        } catch (ValidationException $e) {
            self::assertSame(['title', 'body_html', 'cover_image'], array_keys($e->errors));
        }
    }

    public function testSlugCollisionGetsSuffixAndEditKeepsOwnSlug(): void
    {
        [$u, $s] = $this->owner();
        $a = $this->posts->save($s, $u, 0, ['title' => 'Gleich', 'body_html' => '<p>a</p>']);
        $b = $this->posts->save($s, $u, 0, ['title' => 'Gleich', 'body_html' => '<p>b</p>']);
        self::assertSame('gleich', $this->posts->get($s, $a)['slug']);
        self::assertSame('gleich-2', $this->posts->get($s, $b)['slug']);
        $this->posts->save($s, $u, $a, ['title' => 'Gleich', 'body_html' => '<p>a2</p>']);
        self::assertSame('gleich', $this->posts->get($s, $a)['slug']);
    }

    public function testSameSlugIsAllowedInDifferentSites(): void
    {
        [$u1, $s1] = $this->owner('o1@example.com', 'alpha');
        [$u2, $s2] = $this->owner('o2@example.com', 'beta');
        $this->posts->save($s1, $u1, 0, ['title' => 'Gleich', 'body_html' => '<p>a</p>']);
        $id = $this->posts->save($s2, $u2, 0, ['title' => 'Gleich', 'body_html' => '<p>b</p>']);
        self::assertSame('gleich', $this->posts->get($s2, $id)['slug']);
    }

    public function testPostsOfOtherSitesAreInvisibleAndUntouchable(): void
    {
        [$u1, $s1] = $this->owner('o1@example.com', 'alpha');
        [$u2, $s2] = $this->owner('o2@example.com', 'beta');
        $id = $this->posts->save($s1, $u1, 0, ['title' => 'Geheim', 'body_html' => '<p>a</p>']);
        self::assertSame([], $this->posts->list($s2)['items']);
        foreach ([
            fn () => $this->posts->get($s2, $id),
            fn () => $this->posts->save($s2, $u2, $id, ['title' => 'Hijack', 'body_html' => '<p>x</p>']),
            fn () => $this->posts->transition($s2, $u2, $id, 'publish'),
            fn () => $this->posts->delete($s2, $u2, $id),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('NotFound erwartet');
            } catch (NotFoundException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame('Geheim', $this->posts->get($s1, $id)['title']);
    }

    public function testStatusWorkflowAndPublishedAtStaysOnRepublish(): void
    {
        [$u, $s] = $this->owner();
        $id = $this->posts->save($s, $u, 0, ['title' => 'T', 'body_html' => '<p>a</p>']);
        self::assertSame('in_review', $this->posts->transition($s, $u, $id, 'submit'));
        self::assertSame('published', $this->posts->transition($s, $u, $id, 'publish'));
        $first = $this->posts->get($s, $id)['published_at'];
        self::assertNotEmpty($first);
        $this->posts->transition($s, $u, $id, 'unpublish');
        $this->posts->transition($s, $u, $id, 'publish');
        self::assertSame($first, $this->posts->get($s, $id)['published_at']);
    }

    public function testInvalidTransitionsAndPublishedCannotBeDeleted(): void
    {
        [$u, $s] = $this->owner();
        $id = $this->posts->save($s, $u, 0, ['title' => 'T', 'body_html' => '<p>a</p>']);
        $this->posts->transition($s, $u, $id, 'publish');
        foreach (['publish', 'submit', 'decline', 'bogus'] as $action) {
            try {
                $this->posts->transition($s, $u, $id, $action);
                self::fail("$action hätte scheitern müssen");
            } catch (ValidationException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(ValidationException::class);
        $this->posts->delete($s, $u, $id);
    }

    public function testEditorMayWriteAndSubmitButNotPublishDeleteOrDecline(): void
    {
        [$acc] = $this->fx->accountWithSite('alpha');
        $editor = $this->fx->user('e@example.com');
        $this->fx->member($acc, $editor, Role::Editor);
        $s = $this->fx->resolve($editor, 'alpha');
        $id = $this->posts->save($s, $editor, 0, ['title' => 'T', 'body_html' => '<p>a</p>']);
        self::assertSame('in_review', $this->posts->transition($s, $editor, $id, 'submit'));
        foreach (['publish', 'decline'] as $action) {
            try {
                $this->posts->transition($s, $editor, $id, $action);
                self::fail('Verbot erwartet');
            } catch (AccessDeniedException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(AccessDeniedException::class);
        $this->posts->delete($s, $editor, $id);
    }

    public function testNonMemberCannotResolveSiteButSuperadminCan(): void
    {
        $this->fx->accountWithSite('alpha');
        $stranger = $this->fx->user('x@example.com');
        try {
            $this->fx->resolve($stranger, 'alpha');
            self::fail('NotFound erwartet');
        } catch (NotFoundException) {
        }
        $root = $this->fx->user('root@example.com', true);
        self::assertTrue($this->fx->resolve($root, 'alpha')->canPublish());
        $this->expectException(NotFoundException::class);
        $this->fx->resolve($root, 'does-not-exist');
    }

    public function testChangesAreAudited(): void
    {
        [$u, $s] = $this->owner();
        $id = $this->posts->save($s, $u, 0, ['title' => 'T', 'body_html' => '<p>a</p>']);
        $this->posts->transition($s, $u, $id, 'publish');
        $actions = $this->pdo->query("SELECT action FROM audit_log WHERE action LIKE 'post.%' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame(['post.create', 'post.publish'], $actions);
    }

    public function testListIsPaginatedNewestFirst(): void
    {
        [$u, $s] = $this->owner();
        for ($i = 1; $i <= PostService::PAGE_SIZE + 3; $i++) {
            $this->posts->save($s, $u, 0, ['title' => "Post $i", 'body_html' => '<p>x</p>']);
        }
        $first = $this->posts->list($s);
        self::assertCount(PostService::PAGE_SIZE, $first['items']);
        self::assertTrue($first['has_more']);
        self::assertSame('Post 28', $first['items'][0]['title']);
        $second = $this->posts->list($s, null, 2);
        self::assertSame(['Post 3', 'Post 2', 'Post 1'], array_column($second['items'], 'title'));
        self::assertFalse($second['has_more']);
        self::assertSame(1, $this->posts->list($s, null, -5)['page']);
    }

    public function testEditorCannotChangePublishedPost(): void
    {
        [$u, $s] = $this->owner();
        $id = $this->posts->save($s, $u, 0, ['title' => 'Live', 'body_html' => '<p>freigegeben</p>']);
        $this->posts->transition($s, $u, $id, 'publish');
        $editor = $this->fx->user('e@example.com');
        $this->fx->member($s->accountId(), $editor, Role::Editor);
        $es = $this->fx->resolve($editor, 'alpha');
        try {
            $this->posts->save($es, $editor, $id, ['title' => 'Live', 'body_html' => '<p>geändert</p>']);
            self::fail('Verbot erwartet');
        } catch (AccessDeniedException) {
        }
        self::assertStringContainsString('freigegeben', (string) $this->posts->get($s, $id)['body_html']);
        // Der Owner darf weiter ändern.
        $this->posts->save($s, $u, $id, ['title' => 'Live', 'body_html' => '<p>neu</p>']);
        self::assertStringContainsString('neu', (string) $this->posts->get($s, $id)['body_html']);
    }
}
