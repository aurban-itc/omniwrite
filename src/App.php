<?php
declare(strict_types=1);

namespace OmniWrite;

use OmniWrite\Ai\ClaudeClient;
use OmniWrite\Ai\ContentAi;
use OmniWrite\Ai\CurlHttpClient;
use OmniWrite\Ai\HttpClient;
use OmniWrite\Ai\OpenAiImageClient;
use OmniWrite\Auth\AuditLogger;
use OmniWrite\Auth\AuthService;
use OmniWrite\Auth\PasswordHasher;
use OmniWrite\Auth\RateLimiter;
use OmniWrite\Auth\SecretBox;
use OmniWrite\Auth\TwoFactorService;
use OmniWrite\Core\Config;
use OmniWrite\Http\Controller\AdminController;
use OmniWrite\Http\Controller\AiController;
use OmniWrite\Http\Controller\BlogSettingsController;
use OmniWrite\Http\Controller\MediaController;
use OmniWrite\Http\Controller\PublicApiController;
use OmniWrite\Http\Controller\PublicBlogController;
use OmniWrite\Http\Controller\AuthController;
use OmniWrite\Http\Controller\DashboardController;
use OmniWrite\Http\Controller\ImpersonationController;
use OmniWrite\Http\Controller\PostController;
use OmniWrite\Http\Controller\SiteProfileController;
use OmniWrite\Http\Controller\SiteSettingsController;
use OmniWrite\Http\Controller\TopicController;
use OmniWrite\Http\Controller\TwoFactorController;
use OmniWrite\Http\Csrf;
use OmniWrite\Http\Kernel;
use OmniWrite\Http\Response;
use OmniWrite\Http\Router;
use OmniWrite\Http\View;
use OmniWrite\Repository\AccountRepository;
use OmniWrite\Repository\SiteRepository;
use OmniWrite\Repository\TotpRepository;
use OmniWrite\Repository\UserRepository;
use OmniWrite\Http\Controller\RecipientController;
use OmniWrite\Http\Controller\ReviewController;
use OmniWrite\Http\Controller\ReviewPageController;
use OmniWrite\Http\Controller\ScheduleController;
use OmniWrite\Mail\Mailer;
use OmniWrite\Mail\ResendMailer;
use OmniWrite\Net\DnsResolver;
use OmniWrite\Net\PageFetcher;
use OmniWrite\Net\SafePageFetcher;
use OmniWrite\Net\SystemDnsResolver;
use OmniWrite\Service\AiService;
use OmniWrite\Service\BlogSettingsService;
use OmniWrite\Service\DomainService;
use OmniWrite\Service\PublicBlogService;
use OmniWrite\Service\RecipientService;
use OmniWrite\Service\ReviewService;
use OmniWrite\Service\ScheduleService;
use OmniWrite\Service\WeeklyRunner;
use OmniWrite\Service\PostService;
use OmniWrite\Import\CoastalImporter;
use OmniWrite\Service\AdminService;
use OmniWrite\Service\SiteAccess;
use OmniWrite\Service\SiteProfileService;
use OmniWrite\Service\SiteService;
use OmniWrite\Service\SiteSettingsService;
use OmniWrite\Service\TopicService;
use OmniWrite\Service\UsageService;
use OmniWrite\Support\ImageStore;
use OmniWrite\Support\Translator;
use PDO;

/** Composition Root: verdrahtet Repositories, Services, Controller und Routen. */
final class App
{
    public static function buildAuth(PDO $pdo, Config $config, ?AuditLogger $audit = null): AuthService
    {
        return new AuthService(
            $pdo,
            new UserRepository($pdo),
            new AccountRepository($pdo),
            new PasswordHasher(),
            new RateLimiter($pdo),
            $audit ?? new AuditLogger($pdo),
            (string) $config->get('auth.registration', 'closed'),
        );
    }

    /** @param ?HttpClient $http Transport für KI-Anbieter und Mail (Tests liefern ein Fake); $mailer überschreibt den Mailversand */
    public static function kernel(PDO $pdo, Config $config, ?string $root = null, ?HttpClient $http = null, ?Mailer $mailer = null, ?DnsResolver $dns = null, ?PageFetcher $pages = null): Kernel
    {
        $root ??= dirname(__DIR__);
        $users = new UserRepository($pdo);
        $accounts = new AccountRepository($pdo);
        $audit = new AuditLogger($pdo);
        $csrf = new Csrf();
        $translator = new Translator($root . '/lang');
        $view = new View($root . '/templates', $translator, $csrf);
        $auth = self::buildAuth($pdo, $config, $audit);
        $sites = new SiteService(new SiteRepository($pdo), $accounts, $audit);

        $twoFactor = new TwoFactorService(
            $pdo,
            new TotpRepository($pdo),
            new SecretBox((string) $config->get('security.master_key', '')),
            new RateLimiter($pdo),
            $audit,
        );

        $authC = new AuthController($auth, $view, $twoFactor, $audit);
        $tfaC = new TwoFactorController($view, $twoFactor, $users);
        $impC = new ImpersonationController($users, $audit);
        $dash = new DashboardController($view, $accounts, $sites);
        $adminService = new AdminService($pdo, $users, $accounts, new PasswordHasher(), $audit);
        $admin = new AdminController(
            $view, $users, $accounts, new SiteRepository($pdo), $adminService, $sites,
            new CoastalImporter($pdo, new ImageStore((string) $config->get('storage.path', $root . '/storage'))),
        );

        $siteAccess = new SiteAccess(new SiteRepository($pdo), $accounts);
        $postService = new PostService($pdo, $audit);
        $topicService = new TopicService($pdo, $audit);
        $settingsService = new SiteSettingsService($pdo, $audit);
        $topicC = new TopicController($siteAccess, $view, $topicService);
        $settingsC = new SiteSettingsController($siteAccess, $view, $settingsService);

        $http ??= new CurlHttpClient();
        $store = new ImageStore((string) $config->get('storage.path', $root . '/storage'));
        $usage = new UsageService($pdo, $accounts);
        $ai = self::buildAi($pdo, $config, $audit, $http, $store, $postService, $topicService, $settingsService, $usage);
        $profileC = new SiteProfileController($siteAccess, $view, $translator, new SiteProfileService(
            $pdo, $audit, self::buildContentAi($config, $http), $usage, $settingsService, $topicService, $pages ?? new SafePageFetcher(),
        ));
        $postC = new PostController($siteAccess, $view, $postService, $ai);
        $aiC = new AiController($siteAccess, $view, $translator, $ai, $topicService, $usage);
        $mediaC = new MediaController($store);
        $mailer ??= self::buildMailer($config, $http);
        $review = self::buildReview($pdo, $config, $audit, $mailer, $translator);
        $recipientService = new RecipientService($pdo, $audit);
        $schedule = new ScheduleService($pdo, $audit);
        $runner = new WeeklyRunner($pdo, $schedule, $ai, $topicService, $review, $mailer, $translator);
        $recipientC = new RecipientController($siteAccess, $view, $recipientService);
        $scheduleC = new ScheduleController($siteAccess, $view, $schedule, $runner, $pdo);
        $reviewC = new ReviewController($siteAccess, $review);
        $reviewPageC = new ReviewPageController($view, $review);

        $baseUrl = (string) $config->get('app.base_url', '');
        $portalHost = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
        $blogSettings = new BlogSettingsService($pdo, $audit);
        $domains = new DomainService($pdo, $audit, $dns ?? new SystemDnsResolver(), $portalHost, (string) $config->get('security.master_key', ''));
        $publicBlog = new PublicBlogService($pdo, $blogSettings, $baseUrl);
        $publicC = new PublicBlogController($view, $publicBlog);
        $apiC = new PublicApiController($publicBlog);
        $blogC = new BlogSettingsController($siteAccess, $view, $blogSettings, $domains, $baseUrl, $portalHost);

        // Öffentliche Routen: am Portal unter /b/<site>, auf einer eigenen Domain an der Wurzel.
        $publicRouter = new Router();
        $publicRouter->add('GET', '/', $publicC->index(...));
        $publicRouter->add('GET', '/sitemap.xml', $publicC->sitemap(...));
        $publicRouter->add('GET', '/feed.xml', $publicC->feed(...));
        $publicRouter->add('GET', '/llms.txt', $publicC->llms(...));
        $publicRouter->add('GET', '/robots.txt', $publicC->robots(...));
        $publicRouter->add('GET', '/api/v1/posts', $apiC->posts(...));
        $publicRouter->add('GET', '/api/v1/posts/{post}', $apiC->post(...));
        $publicRouter->add('GET', '/media/{site}/{file}', $mediaC->show(...));
        $publicRouter->add('GET', '/{post}', $publicC->post(...));

        $router = new Router();
        $router->add('GET', '/robots.txt', $publicC->robots(...));
        $router->add('GET', '/api/v1/sites/{site}/posts', $apiC->posts(...));
        $router->add('GET', '/api/v1/sites/{site}/posts/{post}', $apiC->post(...));
        $router->add('GET', '/b/{site}', $publicC->index(...));
        $router->add('GET', '/b/{site}/', $publicC->index(...));
        $router->add('GET', '/b/{site}/sitemap.xml', $publicC->sitemap(...));
        $router->add('GET', '/b/{site}/feed.xml', $publicC->feed(...));
        $router->add('GET', '/b/{site}/llms.txt', $publicC->llms(...));
        $router->add('GET', '/b/{site}/{post}', $publicC->post(...));
        $router->add('GET', '/sites/{slug}/blog', $blogC->form(...), 'user');
        $router->add('POST', '/sites/{slug}/blog', $blogC->save(...), 'user');
        $router->add('POST', '/sites/{slug}/blog/domain', $blogC->setDomain(...), 'user');
        $router->add('POST', '/sites/{slug}/blog/domain/verify', $blogC->verifyDomain(...), 'user');
        $router->add('POST', '/sites/{slug}/blog/domain/remove', $blogC->removeDomain(...), 'user');
        $router->add('GET', '/health', static fn (): Response => Response::json(['status' => 'ok']));
        $router->add('GET', '/', static fn (): Response => Response::redirect('/dashboard'));
        $router->add('GET', '/login', $authC->loginForm(...));
        $router->add('POST', '/login', $authC->login(...));
        $router->add('POST', '/logout', $authC->logout(...));
        $router->add('GET', '/register', $authC->registerForm(...));
        $router->add('POST', '/register', $authC->register(...));
        $router->add('GET', '/login/2fa', $tfaC->loginForm(...));
        $router->add('POST', '/login/2fa', $tfaC->login(...));
        $router->add('GET', '/account/2fa', $tfaC->setupForm(...), 'user');
        $router->add('POST', '/account/2fa', $tfaC->setupConfirm(...), 'user');
        $router->add('POST', '/admin/impersonate/{id}', $impC->start(...), 'superadmin');
        $router->add('POST', '/impersonate/stop', $impC->stop(...), 'user');
        $router->add('GET', '/dashboard', $dash->show(...), 'user');
        $router->add('POST', '/sites', $dash->createSite(...), 'user');
        $router->add('GET', '/sites/{slug}/posts', $postC->index(...), 'user');
        $router->add('GET', '/sites/{slug}/posts/new', $postC->newForm(...), 'user');
        $router->add('POST', '/sites/{slug}/posts', $postC->save(...), 'user');
        $router->add('GET', '/sites/{slug}/posts/{id}', $postC->editForm(...), 'user');
        $router->add('POST', '/sites/{slug}/posts/{id}', $postC->save(...), 'user');
        $router->add('POST', '/sites/{slug}/posts/{id}/send-review', $reviewC->send(...), 'user');
        $router->add('POST', '/sites/{slug}/posts/{id}/{action}', $postC->action(...), 'user');
        $router->add('GET', '/sites/{slug}/recipients', $recipientC->index(...), 'user');
        $router->add('POST', '/sites/{slug}/recipients', $recipientC->add(...), 'user');
        $router->add('POST', '/sites/{slug}/recipients/{id}/{action}', $recipientC->action(...), 'user');
        $router->add('GET', '/sites/{slug}/schedule', $scheduleC->index(...), 'user');
        $router->add('POST', '/sites/{slug}/schedule', $scheduleC->save(...), 'user');
        $router->add('POST', '/sites/{slug}/schedule/run', $scheduleC->run(...), 'user');
        $router->add('GET', '/review/{token}/{do}', $reviewPageC->show(...));
        $router->add('POST', '/review/{token}/{do}', $reviewPageC->act(...));
        $router->add('GET', '/sites/{slug}/generate', $aiC->generateForm(...), 'user');
        $router->add('POST', '/sites/{slug}/generate', $aiC->generate(...), 'user');
        $router->add('POST', '/sites/{slug}/ai/revise', $aiC->revise(...), 'user');
        $router->add('POST', '/sites/{slug}/ai/seo', $aiC->seo(...), 'user');
        $router->add('POST', '/sites/{slug}/ai/image', $aiC->image(...), 'user');
        $router->add('POST', '/sites/{slug}/topics/suggest', $aiC->suggestTopics(...), 'user');
        $router->add('GET', '/media/{site}/{file}', $mediaC->show(...));
        $router->add('GET', '/sites/{slug}/topics', $topicC->index(...), 'user');
        $router->add('POST', '/sites/{slug}/topics', $topicC->add(...), 'user');
        $router->add('GET', '/sites/{slug}/topics/{id}', $topicC->editForm(...), 'user');
        $router->add('POST', '/sites/{slug}/topics/{id}', $topicC->update(...), 'user');
        $router->add('POST', '/sites/{slug}/topics/{id}/{action}', $topicC->action(...), 'user');
        $router->add('GET', '/sites/{slug}/settings/analyze', $profileC->form(...), 'user');
        $router->add('POST', '/sites/{slug}/settings/analyze', $profileC->analyze(...), 'user');
        $router->add('POST', '/sites/{slug}/settings/analyze/apply', $profileC->apply(...), 'user');
        $router->add('GET', '/sites/{slug}/settings', $settingsC->form(...), 'user');
        $router->add('POST', '/sites/{slug}/settings', $settingsC->save(...), 'user');
        $router->add('GET', '/admin', $admin->index(...), 'superadmin');
        $router->add('POST', '/admin/users/{id}/activate', $admin->activate(...), 'superadmin');
        $router->add('POST', '/admin/users/{id}/reject', $admin->reject(...), 'superadmin');
        $router->add('POST', '/admin/users/{id}/disable', $admin->disable(...), 'superadmin');
        $router->add('POST', '/admin/users/{id}/enable', $admin->enable(...), 'superadmin');
        $router->add('POST', '/admin/users/{id}/password', $admin->resetPassword(...), 'superadmin');
        $router->add('GET', '/admin/accounts/new', $admin->newAccountForm(...), 'superadmin');
        $router->add('POST', '/admin/accounts', $admin->createAccount(...), 'superadmin');
        $router->add('GET', '/admin/accounts/{id}', $admin->account(...), 'superadmin');
        $router->add('POST', '/admin/accounts/{id}', $admin->rename(...), 'superadmin');
        $router->add('POST', '/admin/accounts/{id}/members', $admin->addMember(...), 'superadmin');
        $router->add('POST', '/admin/accounts/{id}/members/{uid}/role', $admin->memberRole(...), 'superadmin');
        $router->add('POST', '/admin/accounts/{id}/members/{uid}/remove', $admin->removeMember(...), 'superadmin');
        $router->add('POST', '/admin/accounts/{id}/sites', $admin->createSite(...), 'superadmin');
        $router->add('GET', '/admin/sites', $admin->sites(...), 'superadmin');
        $router->add('GET', '/admin/import', $admin->importForm(...), 'superadmin');
        $router->add('POST', '/admin/import', $admin->import(...), 'superadmin');

        return new Kernel($router, $users, $csrf, $twoFactor, $audit, (string) $config->get('app.default_locale', 'de'), $publicBlog, $publicRouter, $portalHost);
    }

    private static function buildAi(PDO $pdo, Config $config, AuditLogger $audit, HttpClient $http, ImageStore $store, PostService $postService, TopicService $topicService, SiteSettingsService $settingsService, UsageService $usage): AiService
    {
        return new AiService(
            $pdo, $audit,
            self::buildContentAi($config, $http),
            new OpenAiImageClient(
                $http, (string) $config->get('ai.openai_api_key', ''),
                (string) $config->get('ai.openai_image_model', 'gpt-image-1'),
                (string) $config->get('ai.openai_endpoint', OpenAiImageClient::DEFAULT_ENDPOINT),
            ),
            $store, $postService, $topicService, $settingsService, $usage,
        );
    }

    private static function buildContentAi(Config $config, HttpClient $http): ContentAi
    {
        return new ContentAi(new ClaudeClient(
            $http, (string) $config->get('ai.anthropic_api_key', ''),
            (string) $config->get('ai.anthropic_model', ClaudeClient::DEFAULT_MODEL),
            (string) $config->get('ai.anthropic_endpoint', ClaudeClient::DEFAULT_ENDPOINT),
        ));
    }

    private static function buildMailer(Config $config, HttpClient $http): Mailer
    {
        return new ResendMailer(
            $http, (string) $config->get('mail.resend_api_key', ''), (string) $config->get('mail.from', ''),
            (string) $config->get('mail.endpoint', ResendMailer::DEFAULT_ENDPOINT),
        );
    }

    private static function buildReview(PDO $pdo, Config $config, AuditLogger $audit, Mailer $mailer, Translator $translator): ReviewService
    {
        return new ReviewService($pdo, $audit, $mailer, $translator, (string) $config->get('app.base_url', ''), max(1, (int) $config->get('review.link_hours', 72)));
    }

    /** Wochenlauf ohne HTTP-Kernel (bin/cron.php). */
    public static function weeklyRunner(PDO $pdo, Config $config, ?string $root = null, ?HttpClient $http = null, ?Mailer $mailer = null): WeeklyRunner
    {
        $root ??= dirname(__DIR__);
        $http ??= new CurlHttpClient();
        $audit = new AuditLogger($pdo);
        $translator = new Translator($root . '/lang');
        $postService = new PostService($pdo, $audit);
        $topicService = new TopicService($pdo, $audit);
        $settingsService = new SiteSettingsService($pdo, $audit);
        $usage = new UsageService($pdo, new AccountRepository($pdo));
        $store = new ImageStore((string) $config->get('storage.path', $root . '/storage'));
        $ai = self::buildAi($pdo, $config, $audit, $http, $store, $postService, $topicService, $settingsService, $usage);
        $mailer ??= self::buildMailer($config, $http);
        return new WeeklyRunner(
            $pdo, new ScheduleService($pdo, $audit), $ai, $topicService,
            self::buildReview($pdo, $config, $audit, $mailer, $translator), $mailer, $translator,
        );
    }
}
