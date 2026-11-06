<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Repository;

use InvalidArgumentException;
use OmniWrite\Domain\SiteContext;
use OmniWrite\Repository\PostRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/** Mandantentrennung: Site A darf Daten von Site B weder lesen, ändern noch löschen. */
final class SiteScopedRepositoryTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('CREATE TABLE posts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, site_id INTEGER NOT NULL,
            title TEXT NOT NULL, slug TEXT NOT NULL, body_html TEXT, status TEXT NOT NULL DEFAULT "draft")');
    }

    private function repo(int $siteId): PostRepository
    {
        return new PostRepository($this->pdo, new SiteContext($siteId));
    }

    public function testInsertForcesContextSiteId(): void
    {
        $id = $this->repo(1)->insert(['title' => 'A', 'slug' => 'a', 'site_id' => 2]);
        self::assertSame(1, (int) $this->repo(1)->find($id)['site_id']);
        self::assertNull($this->repo(2)->find($id));
    }

    public function testFindAllUpdateDeleteAreScoped(): void
    {
        $idA = $this->repo(1)->insert(['title' => 'A', 'slug' => 'a']);
        $this->repo(2)->insert(['title' => 'B', 'slug' => 'b']);

        self::assertNull($this->repo(2)->find($idA));
        self::assertCount(1, $this->repo(1)->all());
        self::assertFalse($this->repo(2)->update($idA, ['title' => 'gehackt']));
        self::assertFalse($this->repo(2)->delete($idA));
        self::assertSame('A', $this->repo(1)->find($idA)['title']);
        self::assertTrue($this->repo(1)->delete($idA));
    }

    public function testWhitelistIgnoresUnknownColumns(): void
    {
        $id = $this->repo(1)->insert(['title' => 'A', 'slug' => 'a', 'evil; DROP' => 'x']);
        self::assertNotNull($this->repo(1)->find($id));
    }

    public function testInvalidSiteIdRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SiteContext(0);
    }
}
