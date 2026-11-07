<?php
declare(strict_types=1);

namespace OmniWrite\Tests\Service;

use OmniWrite\Domain\AccessDeniedException;
use OmniWrite\Domain\NotFoundException;
use OmniWrite\Domain\Role;
use OmniWrite\Domain\ValidationException;
use OmniWrite\Service\SiteSettingsService;
use OmniWrite\Service\TopicService;
use OmniWrite\Tests\Support\DbTestCase;
use OmniWrite\Tests\Support\Fixtures;

final class TopicServiceTest extends DbTestCase
{
    private Fixtures $fx;
    private TopicService $topics;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fx = new Fixtures($this->pdo);
        $this->topics = new TopicService($this->pdo, $this->fx->audit);
    }

    /** @return array{0: \OmniWrite\Domain\User, 1: \OmniWrite\Service\ResolvedSite} */
    private function owner(string $slug = 'alpha', string $email = 'o@example.com'): array
    {
        [$acc, $slug] = $this->fx->accountWithSite($slug);
        $user = $this->fx->user($email);
        $this->fx->member($acc, $user, Role::Owner);
        return [$user, $this->fx->resolve($user, $slug)];
    }

    public function testRotationWalksTopDownAndWrapsAround(): void
    {
        [$u, $s] = $this->owner();
        $a = $this->topics->add($s, $u, ['title' => 'A']);
        $b = $this->topics->add($s, $u, ['title' => 'B']);
        $c = $this->topics->add($s, $u, ['title' => 'C']);
        $order = [];
        for ($i = 0; $i < 4; $i++) {
            $next = $this->topics->nextFor($s);
            $order[] = $next['title'];
            $this->topics->markUsed($s, (int) $next['id'], true);
        }
        self::assertSame(['A', 'B', 'C', 'A'], $order);
        self::assertSame(2, (int) $this->topics->get($s, $a)['times_used']);
        self::assertSame(1, (int) $this->topics->get($s, $b)['times_used']);
        self::assertNotNull($c);
    }

    public function testPausedAndSuggestedTopicsAreSkippedAndEmptyRotationIsNull(): void
    {
        [$u, $s] = $this->owner();
        self::assertNull($this->topics->nextFor($s));
        $a = $this->topics->add($s, $u, ['title' => 'A']);
        $this->topics->add($s, $u, ['title' => 'S'], 'suggested');
        $this->topics->setStatus($s, $a, 'pause');
        self::assertNull($this->topics->nextFor($s));
        $this->topics->setStatus($s, $a, 'resume');
        self::assertSame('A', $this->topics->nextFor($s)['title']);
    }

    public function testManualUseDoesNotAdvanceRotation(): void
    {
        [$u, $s] = $this->owner();
        $a = $this->topics->add($s, $u, ['title' => 'A']);
        $this->topics->add($s, $u, ['title' => 'B']);
        $this->topics->markUsed($s, $a, false);
        self::assertSame('A', $this->topics->nextFor($s)['title']);
    }

    public function testMoveSwapsNeighboursAndIgnoresEdges(): void
    {
        [$u, $s] = $this->owner();
        $a = $this->topics->add($s, $u, ['title' => 'A']);
        $b = $this->topics->add($s, $u, ['title' => 'B']);
        $this->topics->move($s, $b, -1);
        $this->topics->move($s, $b, -1); // schon oben: nichts passiert
        $titles = array_column($this->topics->overview($s)['rotation'], 'title');
        self::assertSame(['B', 'A'], $titles);
        self::assertNotNull($a);
    }

    public function testTenantIsolation(): void
    {
        [$u1, $s1] = $this->owner('alpha', 'o1@example.com');
        [$u2, $s2] = $this->owner('beta', 'o2@example.com');
        $id = $this->topics->add($s1, $u1, ['title' => 'Nur Alpha']);
        $this->topics->add($s2, $u2, ['title' => 'Nur Beta']);
        self::assertSame(['Nur Beta'], array_column($this->topics->overview($s2)['rotation'], 'title'));
        foreach ([
            fn () => $this->topics->get($s2, $id),
            fn () => $this->topics->update($s2, $u2, $id, ['title' => 'x']),
            fn () => $this->topics->delete($s2, $u2, $id),
            fn () => $this->topics->move($s2, $id, 1),
            fn () => $this->topics->setStatus($s2, $id, 'pause'),
        ] as $attempt) {
            try {
                $attempt();
                self::fail('NotFound erwartet');
            } catch (NotFoundException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testCursorIsPerSite(): void
    {
        [$u1, $s1] = $this->owner('alpha', 'o1@example.com');
        [$u2, $s2] = $this->owner('beta', 'o2@example.com');
        $a1 = $this->topics->add($s1, $u1, ['title' => 'A1']);
        $this->topics->add($s1, $u1, ['title' => 'B1']);
        $this->topics->add($s2, $u2, ['title' => 'A2']);
        $this->topics->add($s2, $u2, ['title' => 'B2']);
        $this->topics->markUsed($s1, $a1, true);
        self::assertSame('B1', $this->topics->nextFor($s1)['title']);
        self::assertSame('A2', $this->topics->nextFor($s2)['title']);
    }

    public function testTitleRequired(): void
    {
        [$u, $s] = $this->owner();
        $this->expectException(ValidationException::class);
        $this->topics->add($s, $u, ['title' => '  ']);
    }

    public function testSettingsDefaultsPerLocaleOverrideAndReset(): void
    {
        [$u, $s] = $this->owner();
        $svc = new SiteSettingsService($this->pdo, $this->fx->audit);
        self::assertStringContainsString('Ton:', $svc->get($s)['style_guide']);
        $svc->save($s, $u, ['style_guide' => 'Eigener Stil', 'company_facts' => 'Fakt', 'image_style' => '']);
        $got = $svc->get($s);
        self::assertSame('Eigener Stil', $got['style_guide']);
        self::assertSame('Fakt', $got['company_facts']);
        $svc->save($s, $u, ['style_guide' => '', 'company_facts' => 'Fakt', 'image_style' => '']);
        self::assertStringContainsString('Ton:', $svc->get($s)['style_guide']);

        [$acc, $slug] = $this->fx->accountWithSite('gamma', 'en');
        $en = $this->fx->user('en@example.com');
        $this->fx->member($acc, $en, Role::Owner);
        self::assertStringContainsString('Voice:', $svc->get($this->fx->resolve($en, $slug))['style_guide']);
    }

    public function testSettingsAreOwnerOnlyAndLengthLimited(): void
    {
        [$u, $s] = $this->owner();
        $svc = new SiteSettingsService($this->pdo, $this->fx->audit);
        try {
            $svc->save($s, $u, ['style_guide' => str_repeat('x', 8001)]);
            self::fail('Validation erwartet');
        } catch (ValidationException $e) {
            self::assertSame(['style_guide'], array_keys($e->errors));
        }
        [$acc] = $this->fx->accountWithSite('delta');
        $editor = $this->fx->user('ed@example.com');
        $this->fx->member($acc, $editor, Role::Editor);
        $this->expectException(AccessDeniedException::class);
        $svc->save($this->fx->resolve($editor, 'delta'), $editor, ['style_guide' => 'x']);
    }
}
