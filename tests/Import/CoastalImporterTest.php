<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Import;

use InvalidArgumentException;
use OmniWrite\Domain\SiteContext;
use OmniWrite\Import\CoastalImporter;
use OmniWrite\Repository\PostRepository;
use OmniWrite\Repository\RecipientRepository;
use OmniWrite\Repository\SiteSettingsRepository;
use OmniWrite\Repository\TopicRepository;
use OmniWrite\Support\ImageStore;
use OmniWrite\Tests\Support\DbTestCase;
use OmniWrite\Tests\Support\Fixtures;

final class CoastalImporterTest extends DbTestCase
{
    private int $siteId;
    private string $dir;
    private CoastalImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();
        [, $slug] = (new Fixtures($this->pdo))->accountWithSite('coastal');
        $this->siteId = (int) (new Fixtures($this->pdo))->sites->findBySlug($slug)['id'];
        $this->dir = sys_get_temp_dir() . '/ow-import-' . bin2hex(random_bytes(4));
        $this->importer = new CoastalImporter($this->pdo, new ImageStore($this->dir));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*/*') ?: [] as $f) {
            @unlink($f);
        }
        foreach (glob($this->dir . '/*') ?: [] as $d) {
            @rmdir($d);
        }
        @rmdir($this->dir);
    }

    private function png(): string
    {
        $im = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($im);
        return base64_encode((string) ob_get_clean());
    }

    /** @return array<string, mixed> */
    private function bundle(): array
    {
        return [
            'format' => 'coastal-export/1',
            'origin' => 'https://www.coastalcopy.com',
            'topics' => [
                ['id' => 3, 'title' => 'Managed Print', 'keywords' => 'print', 'category' => 'Print', 'notes' => null, 'status' => 'active', 'sort_order' => 10, 'times_used' => 2, 'last_used_at' => '2026-10-01 08:00:00'],
                ['id' => 4, 'title' => 'Cloud Fax', 'keywords' => '', 'category' => '', 'notes' => 'n', 'status' => 'paused', 'sort_order' => 20, 'times_used' => 0, 'last_used_at' => null],
            ],
            'posts' => [
                ['slug' => 'hello', 'title' => 'Hello', 'excerpt' => 'e', 'body_html' => '<p>Hi</p><img src="/assets/blog/a.webp" alt="x"><a href="/Print.html">Print</a><a href="https://example.org/x">ext</a><script>alert(1)</script>',
                    'category' => 'Print', 'keywords' => 'k', 'meta_description' => 'm', 'status' => 'published', 'cover_image' => 'assets/blog/a.webp', 'cover_alt' => 'alt', 'image_prompt' => '', 'read_minutes' => 3,
                    'published_at' => '2026-09-01 10:00:00', 'created_at' => '2026-08-30 10:00:00', 'updated_at' => '2026-09-01 10:00:00', 'topic_id' => 3],
                ['slug' => 'waiting', 'title' => 'Waiting', 'body_html' => '<p>W</p>', 'status' => 'review', 'cover_image' => 'assets/slots/missing.jpg', 'published_at' => null, 'created_at' => '2026-10-02 10:00:00', 'topic_id' => 4],
            ],
            'recipients' => [['email' => 'Boss@Example.com', 'name' => 'Boss', 'active' => 1], ['email' => 'not-an-email', 'name' => '', 'active' => 1]],
            'settings' => ['style_guide' => 'Be brief', 'topic_cursor' => '10'],
            'images' => ['assets/blog/a.webp' => $this->png()],
        ];
    }

    public function testImportsEverythingAndMapsReferences(): void
    {
        $r = $this->importer->import($this->siteId, $this->bundle());
        $this->assertSame(2, $r['posts']);
        $this->assertSame(2, $r['topics']);
        $this->assertSame(1, $r['recipients']);
        $this->assertSame(1, $r['images']);
        $ctx = new SiteContext($this->siteId);
        $posts = new PostRepository($this->pdo, $ctx);
        $hello = $posts->findPublishedBySlug('hello');
        $this->assertNotNull($hello);
        $this->assertMatchesRegularExpression('#^/media/\d+/[a-f0-9]{16}\.(webp|png)$#', $hello['cover_image']);
        $this->assertStringContainsString('src="' . $hello['cover_image'] . '"', $hello['body_html']);
        $this->assertStringContainsString('href="https://www.coastalcopy.com/Print.html"', $hello['body_html']);
        $this->assertStringContainsString('href="https://example.org/x"', $hello['body_html']);
        $this->assertStringNotContainsString('<script', $hello['body_html']);
        $this->assertStringStartsWith('2026-09-01 10:00:00', (string) $hello['published_at']);
        $topic = (new TopicRepository($this->pdo, $ctx))->all(10);
        $ids = array_column($topic, 'id', 'title');
        $this->assertSame((int) $ids['Managed Print'], (int) $hello['topic_id']);

        $waiting = $posts->all(10);
        $waiting = array_values(array_filter($waiting, fn ($p) => $p['slug'] === 'waiting'))[0];
        $this->assertSame('in_review', $waiting['status']);
        $this->assertNull($waiting['published_at']);
        $this->assertSame('https://www.coastalcopy.com/assets/slots/missing.jpg', $waiting['cover_image']);
        $this->assertNotEmpty($r['warnings']);

        $this->assertTrue((new RecipientRepository($this->pdo, $ctx))->emailTaken('boss@example.com'));
        $s = new SiteSettingsRepository($this->pdo, $ctx);
        $this->assertSame('Be brief', $s->get('style_guide'));
        $this->assertSame('10', $s->get('topic_cursor'));
        $this->assertSame((string) $ids['Managed Print'], $s->get('topic_cursor_id'));
    }

    public function testIsRepeatableAndKeepsExistingSettings(): void
    {
        $this->importer->import($this->siteId, $this->bundle());
        (new SiteSettingsRepository($this->pdo, new SiteContext($this->siteId)))->set('style_guide', 'Changed here');
        $r = $this->importer->import($this->siteId, $this->bundle());
        $this->assertSame(0, $r['posts']);
        $this->assertSame(2, $r['posts_skipped']);
        $this->assertSame(0, $r['topics']);
        $this->assertSame(0, $r['recipients']);
        $this->assertSame(1, $r['recipients_skipped']);
        $this->assertSame('Changed here', (new SiteSettingsRepository($this->pdo, new SiteContext($this->siteId)))->get('style_guide'));
        $this->assertCount(2, (new PostRepository($this->pdo, new SiteContext($this->siteId)))->all(10));
    }

    public function testDoesNotTouchOtherSites(): void
    {
        [, $other] = (new Fixtures($this->pdo))->accountWithSite('other');
        $otherId = (int) (new Fixtures($this->pdo))->sites->findBySlug($other)['id'];
        $this->importer->import($this->siteId, $this->bundle());
        $this->assertCount(0, (new PostRepository($this->pdo, new SiteContext($otherId)))->all(10));
    }

    public function testRejectsForeignFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->importer->import($this->siteId, ['format' => 'x']);
    }

    public function testRejectsNonHttpsOrigin(): void
    {
        $b = $this->bundle();
        $b['origin'] = 'http://www.coastalcopy.com';
        $this->expectException(InvalidArgumentException::class);
        $this->importer->import($this->siteId, $b);
    }
}
