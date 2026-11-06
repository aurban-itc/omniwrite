<?php
declare(strict_types=1);

namespace OmniWrite;

use OmniWrite\Auth\AuditLogger;
use OmniWrite\Auth\AuthService;
use OmniWrite\Auth\PasswordHasher;
use OmniWrite\Auth\RateLimiter;
use OmniWrite\Core\Config;
use OmniWrite\Http\Controller\AdminController;
use OmniWrite\Http\Controller\AuthController;
use OmniWrite\Http\Controller\DashboardController;
use OmniWrite\Http\Csrf;
use OmniWrite\Http\Kernel;
use OmniWrite\Http\Response;
use OmniWrite\Http\Router;
use OmniWrite\Http\View;
use OmniWrite\Repository\AccountRepository;
use OmniWrite\Repository\SiteRepository;
use OmniWrite\Repository\UserRepository;
use OmniWrite\Service\SiteService;
use OmniWrite\Support\Translator;
use PDO;

/** Composition Root: verdrahtet Repositories, Services, Controller und Routen. */
final class App
{
    public static function buildAuth(PDO $pdo, Config $config): AuthService
    {
        return new AuthService(
            $pdo,
            new UserRepository($pdo),
            new AccountRepository($pdo),
            new PasswordHasher(),
            new RateLimiter($pdo),
            new AuditLogger($pdo),
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
        $auth = self::buildAuth($pdo, $config);
        $sites = new SiteService(new SiteRepository($pdo), $accounts, $audit);

        $authC = new AuthController($auth, $view);
        $dash = new DashboardController($view, $accounts, $sites);
        $admin = new AdminController($view, $users, $accounts, $audit);

        $router = new Router();
        $router->add('GET', '/health', static fn (): Response => Response::json(['status' => 'ok']));
        $router->add('GET', '/', static fn (): Response => Response::redirect('/dashboard'));
        $router->add('GET', '/login', $authC->loginForm(...));
        $router->add('POST', '/login', $authC->login(...));
        $router->add('POST', '/logout', $authC->logout(...));
        $router->add('GET', '/register', $authC->registerForm(...));
        $router->add('POST', '/register', $authC->register(...));
        $router->add('GET', '/dashboard', $dash->show(...), 'user');
        $router->add('POST', '/sites', $dash->createSite(...), 'user');
        $router->add('GET', '/admin', $admin->index(...), 'superadmin');
        $router->add('POST', '/admin/users/{id}/activate', $admin->activate(...), 'superadmin');

        return new Kernel($router, $users, $csrf, (string) $config->get('app.default_locale', 'de'));
    }
}
