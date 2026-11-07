<?php
declare(strict_types=1);

namespace OmniWrite;

use OmniWrite\Auth\AuditLogger;
use OmniWrite\Auth\AuthService;
use OmniWrite\Auth\PasswordHasher;
use OmniWrite\Auth\RateLimiter;
use OmniWrite\Auth\SecretBox;
use OmniWrite\Auth\TwoFactorService;
use OmniWrite\Core\Config;
use OmniWrite\Http\Controller\AdminController;
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
use OmniWrite\Service\PostService;
use OmniWrite\Service\SiteAccess;
use OmniWrite\Service\SiteService;
use OmniWrite\Service\SiteSettingsService;
use OmniWrite\Service\TopicService;
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

    public static function kernel(PDO $pdo, Config $config, ?string $root = null): Kernel
    {
        $root ??= dirname(__DIR__);
        $users = new UserRepository($pdo);
        $accounts = new AccountRepository($pdo);
        $audit = new AuditLogger($pdo);
        $csrf = new Csrf();
        $view = new View($root . '/templates', new Translator($root . '/lang'), $csrf);
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
        $postC = new PostController($siteAccess, $view, new PostService($pdo, $audit));
        $topicC = new TopicController($siteAccess, $view, new TopicService($pdo, $audit));
        $settingsC = new SiteSettingsController($siteAccess, $view, new SiteSettingsService($pdo, $audit));

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
