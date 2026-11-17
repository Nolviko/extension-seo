<?php

declare(strict_types=1);

/*
 * Nolviko Advanced SEO extension (2026-11-15, PLG-04): address patterns, site-wide title/description templates,
 * per-page settings, sitemap control, a robots.txt editor and a redirect manager. Administrators only.
 */

use Doctrine\DBAL\Connection;
use Nolviko\Core\Cache\CacheFactory;
use Nolviko\Core\Extensions\ExtensionContext;
use Nolviko\Core\Extensions\ExtensionProvider;
use Nolviko\Core\Extensions\PageMeta;
use Nolviko\Core\Extensions\SitemapRules;
use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\RouteDefinition;
use Nolviko\Core\Routing\Router;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\Session\Session;
use Nolviko\Core\Settings\SiteSettings;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Admin\Http\AdminSession;
use Nolviko\Modules\Admin\Infrastructure\DbalAdminAudit;
use Nolviko\Modules\Permissions\Domain\PermissionDefinition;
use Nolviko\Modules\Permissions\Domain\PermissionType;
use Nolviko\Modules\Seo\Domain\SiteUrl;
use Nolviko\Modules\Users\Http\RequestSubject;
use NolvikoExt\Seo\AdminHandler;
use NolvikoExt\Seo\SeoConfig;
use NolvikoExt\Seo\SeoFilters;
use NolvikoExt\Seo\SeoStore;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

require_once __DIR__ . '/SeoConfig.php';
require_once __DIR__ . '/SeoStore.php';
require_once __DIR__ . '/SeoFilters.php';
require_once __DIR__ . '/AdminHandler.php';

return new class implements ExtensionProvider {
    public function register(ExtensionContext $context): void
    {
        $directory = $context->storage();
        $config = SeoConfig::load($directory);
        // Patterns are needed by the router before any service exists.
        $context->routePatterns($config->routePatterns());

        $metaTable = $context->table('meta');
        $redirectTable = $context->table('redirects');
        $context->service(
            'nolviko.seo.store',
            static fn(Connection $db, CacheFactory $caches): SeoStore
                => new SeoStore($db, $caches->namespace('nolviko-seo'), $metaTable, $redirectTable),
            [Connection::class, CacheFactory::class],
        );
        $context->service(
            'nolviko.seo.filters',
            static fn(SeoStore $store, SiteSettings $site): SeoFilters => new SeoFilters(
                static fn(): SeoConfig => $config,
                static fn(): SeoStore => $store,
                static fn(): string => trim((string) ($site->peek('site.name') ?? '')) ?: 'Nolviko',
            ),
            ['nolviko.seo.store', SiteSettings::class],
        );

        // Hooks run on public requests; the filters (and the database) are built only when one is called.
        $filters = $context->lazy('nolviko.seo.filters');
        $context->pageMeta(static fn(PageMeta $meta): PageMeta => $filters()->page($meta));
        $context->robots(static fn(string $body): string => $filters()->robots($body));
        $context->sitemap(static fn(): SitemapRules => $config->sitemapRules());
        $context->notFound(static fn(Request $request): ?Response => $filters()->notFound($request));

        $context->permission(new PermissionDefinition('nolviko.seo.manage', PermissionType::Bool, default: true));
        $context->route(
            new RouteDefinition(
                AdminHandler::ROUTE,
                '/ext/nolviko/seo/admin',
                ['GET', 'POST'],
                requiresHttps: true,
            ),
            'nolviko.seo.manage',
            static fn(
                MessageTranslator $translator,
                TemplateRenderer $views,
                RequestSubject $subjects,
                AdminSession $admin,
                CsrfTokens $csrf,
                Router $router,
                Session $session,
                Connection $db,
                ErrorResponder $errors,
                SiteUrl $site,
                SeoStore $store,
            ): AdminHandler => new AdminHandler(
                $translator,
                $views,
                $subjects,
                $admin,
                $csrf,
                $router,
                $session,
                new DbalAdminAudit($db),
                $errors,
                $site,
                static fn(): SeoConfig => SeoConfig::load($directory),
                static fn(): SeoStore => $store,
            ),
            [
                MessageTranslator::class, TemplateRenderer::class, RequestSubject::class, AdminSession::class,
                CsrfTokens::class, Router::class, Session::class, Connection::class, ErrorResponder::class,
                SiteUrl::class, 'nolviko.seo.store',
            ],
        );
        $context->adminPage(AdminHandler::ROUTE);
    }
};
