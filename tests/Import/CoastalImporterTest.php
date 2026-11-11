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
        if (!is_dir($this->dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
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

    public function testKeepsExistingCursorAndDoesNotSetForeignCursorId(): void
    {
        $s = new SiteSettingsRepository($this->pdo, new SiteContext($this->siteId));
        $s->set('topic_cursor', '20');
        $this->importer->import($this->siteId, $this->bundle());
        $this->assertSame('20', $s->get('topic_cursor'));
        $this->assertSame('', $s->get('topic_cursor_id'));
    }

    public function testRedirectScriptWritesRulesAndIndexWithLegacyKeys(): void
    {
        $b = $this->bundle();
        $b['posts'][0]['legacy_slug'] = 'old-key';
        $dir = sys_get_temp_dir() . '/ow-redir-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/e.json", json_encode($b));
        $out = (string) shell_exec(sprintf('php %s %s https://insights.example.com %s',
            escapeshellarg(dirname(__DIR__, 2) . '/bin/coastal-redirects.php'), escapeshellarg("$dir/e.json"), escapeshellarg("$dir/index.json")));
        $this->assertStringContainsString('^insights/hello(\.html)?/?$ https://insights.example.com/hello?', $out);
        $this->assertStringNotContainsString('waiting', $out);
        $index = json_decode((string) file_get_contents("$dir/index.json"), true);
        $this->assertCount(1, $index);
        $this->assertSame('old-key', $index[0]['legacy']);
        $this->assertSame('https://insights.example.com/hello', $index[0]['url']);
        $this->assertSame('https://www.coastalcopy.com/assets/blog/a.webp', $index[0]['image']);
        array_map('unlink', glob("$dir/*"));
        rmdir($dir);
    }

    public function testAvifIsStoredAndLaterImportRepairsFallbackReferences(): void
    {
        $avif = base64_encode((string) file_get_contents(dirname(__DIR__) . '/fixtures/sample.avif'));
        $b = $this->bundle();
        $b['images'] = []; // erster Export ohne Bilder: Verweise bleiben auf der alten Adresse
        $b['posts'][0]['cover_image'] = 'assets/slots/desk.avif';
        $b['posts'][0]['body_html'] = '<p>Hi</p><img src="/assets/slots/desk.avif" alt="x">';
        $this->importer->import($this->siteId, $b);
        $posts = new PostRepository($this->pdo, new SiteContext($this->siteId));
        $this->assertSame('https://www.coastalcopy.com/assets/slots/desk.avif', $posts->findBySlug('hello')['cover_image']);

        $b['images'] = ['assets/slots/desk.avif' => $avif];
        $bad = $b;
        $bad['images'] = ['assets/slots/desk.avif' => base64_encode('not an image')];
        $r = $this->importer->import($this->siteId, $bad);
        $this->assertSame(0, $r['images_repaired']);
        $this->assertNotEmpty(array_filter($r['warnings'], fn ($w) => str_contains($w, 'assets/slots/desk.avif')), 'fehlgeschlagener Nachtrag wird gemeldet');

        $r = $this->importer->import($this->siteId, $b);
        $this->assertSame(0, $r['posts']);
        $this->assertSame(1, $r['images_repaired']);
        $post = $posts->findBySlug('hello');
        $this->assertMatchesRegularExpression('#^/media/\d+/[a-f0-9]{16}\.(webp|avif)$#', $post['cover_image']);
        $this->assertStringContainsString('src="' . $post['cover_image'] . '"', $post['body_html']);
        $file = basename($post['cover_image']);
        $this->assertNotNull((new ImageStore($this->dir))->pathFor($this->siteId, $file));
        $r = $this->importer->import($this->siteId, $b);
        $this->assertSame(0, $r['images_repaired']);
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
