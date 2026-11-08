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
use OmniWrite\Http\Controller\MediaController;
use OmniWrite\Http\Controller\AuthController;
use OmniWrite\Http\Controller\DashboardController;
use OmniWrite\Http\Controller\ImpersonationController;
use OmniWrite\Http\Controller\PostController;
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
use OmniWrite\Service\AiService;
use OmniWrite\Service\PostService;
use OmniWrite\Service\SiteAccess;
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

    /** @param ?HttpClient $http Transport für KI-Anbieter (Tests liefern ein Fake) */
    public static function kernel(PDO $pdo, Config $config, ?string $root = null, ?HttpClient $http = null): Kernel
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
        $admin = new AdminController($view, $users, $accounts, $audit);

        $siteAccess = new SiteAccess(new SiteRepository($pdo), $accounts);
        $postService = new PostService($pdo, $audit);
        $topicService = new TopicService($pdo, $audit);
        $settingsService = new SiteSettingsService($pdo, $audit);
        $postC = new PostController($siteAccess, $view, $postService);
        $topicC = new TopicController($siteAccess, $view, $topicService);
        $settingsC = new SiteSettingsController($siteAccess, $view, $settingsService);

        $http ??= new CurlHttpClient();
        $store = new ImageStore((string) $config->get('storage.path', $root . '/storage'));
        $usage = new UsageService($pdo, $accounts);
        $ai = new AiService(
            $pdo, $audit,
            new ContentAi(new ClaudeClient(
                $http, (string) $config->get('ai.anthropic_api_key', ''),
                (string) $config->get('ai.anthropic_model', ClaudeClient::DEFAULT_MODEL),
                (string) $config->get('ai.anthropic_endpoint', ClaudeClient::DEFAULT_ENDPOINT),
            )),
            new OpenAiImageClient(
                $http, (string) $config->get('ai.openai_api_key', ''),
                (string) $config->get('ai.openai_image_model', 'gpt-image-1'),
                (string) $config->get('ai.openai_endpoint', OpenAiImageClient::DEFAULT_ENDPOINT),
            ),
            $store, $postService, $topicService, $settingsService, $usage,
        );
        $aiC = new AiController($siteAccess, $view, $translator, $ai, $topicService, $usage);
        $mediaC = new MediaController($store);

        $router = new Router();
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
        $router->add('POST', '/sites/{slug}/posts/{id}/{action}', $postC->action(...), 'user');
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
        $router->add('GET', '/sites/{slug}/settings', $settingsC->form(...), 'user');
        $router->add('POST', '/sites/{slug}/settings', $settingsC->save(...), 'user');
        $router->add('GET', '/admin', $admin->index(...), 'superadmin');
        $router->add('POST', '/admin/users/{id}/activate', $admin->activate(...), 'superadmin');

        return new Kernel($router, $users, $csrf, $twoFactor, $audit, (string) $config->get('app.default_locale', 'de'));
    }
}
