<?php

declare(strict_types=1);

namespace NolvikoExt\Seo\Tests;

use Nolviko\Core\Extensions\PageMeta;
use NolvikoExt\Seo\SeoConfig;
use NolvikoExt\Seo\SeoFilters;
use NolvikoExt\Seo\SeoStore;
use PHPUnit\Framework\TestCase;

/**
 * Run from the workspace root (not part of the ZIP):
 *   php dev/vendor/bin/phpunit --bootstrap dev/vendor/autoload.php extensions-src/nolviko/seo/tests
 * Core parts (patterns, aliases, filters in the page) are covered by app/tests/Unit/Routing/RoutePatternsTest.
 */
final class SeoTest extends TestCase
{
    private string $directory;

    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/src/SeoConfig.php';
        require_once dirname(__DIR__) . '/src/SeoStore.php';
        require_once dirname(__DIR__) . '/src/SeoFilters.php';
    }

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/nolviko-seo-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->directory . '/*') ?: []);
        rmdir($this->directory);
    }

    public function testSettingsAreCleanedAndSurviveASaveAndLoad(): void
    {
        $config = SeoConfig::load($this->directory);
        self::assertSame([], $config->routePatterns(), 'no file: core behaviour');

        $config->save([
            'titles' => ['thread' => "  {title} | {site}\x07 "],
            'image' => 'javascript:alert(1)',
            'patterns' => ['threads.show' => '/KONU/{slug}-{thread}', 'unknown.route' => '/x'],
            'aliases' => ['threads.show' => ['/t/{thread}', '', 7]],
            'sitemap' => [
                'tags' => false, 'exclude_forums' => [3, '4', -1, 3], 'extra' => ['https://a.b/x', 'ftp://c'],
            ],
            'robots' => ['mode' => 'evil', 'text' => "Disallow: /a\nDisallow: /b"],
        ]);
        $loaded = SeoConfig::load($this->directory)->data;

        self::assertSame('{title} | {site}', $loaded['titles']['thread']);
        self::assertSame('', $loaded['image']);
        $pattern = SeoConfig::load($this->directory)->routePatterns()['threads.show'];
        self::assertSame(['path' => '/konu/{slug}-{thread}', 'aliases' => ['/t/{thread}']], $pattern);
        self::assertArrayNotHasKey('unknown.route', $loaded['patterns']);
        self::assertSame([3, 4], $loaded['sitemap']['exclude_forums']);
        self::assertSame(['https://a.b/x'], $loaded['sitemap']['extra']);
        self::assertFalse(SeoConfig::load($this->directory)->sitemapRules()->tags);
        self::assertSame('core', $loaded['robots']['mode']);

        file_put_contents($this->directory . '/config.json', '{broken');
        self::assertSame([], SeoConfig::load($this->directory)->routePatterns(), 'damaged file: defaults');
    }

    public function testGoingBackToTheDefaultKeepsOlderAddressesRedirecting(): void
    {
        $config = SeoConfig::load($this->directory);
        $config->save(['patterns' => ['threads.show' => ''], 'aliases' => ['threads.show' => ['/t/{thread}']]]);
        $patterns = SeoConfig::load($this->directory)->routePatterns();

        self::assertSame(
            ['threads.show' => ['path' => '/threads/{slug}-{thread}', 'aliases' => ['/t/{thread}']]],
            $patterns,
        );
        $built = \Nolviko\Core\Routing\RoutePatterns::build(\Nolviko\Core\Routing\CoreRoutes::definitions(), $patterns);
        $compiled = \Nolviko\Core\Routing\CompiledRoutes::compile($built['definitions']);
        $router = new \Nolviko\Core\Routing\Router($compiled);
        $match = $router->match(\Symfony\Component\HttpFoundation\Request::create('/t/5'));
        self::assertSame('threads.show', $match['_alias_of'] ?? null);
    }

    public function testTemplatesAndRobots(): void
    {
        $config = SeoConfig::load($this->directory);
        $config->save([
            'titles' => ['thread' => '{title} – {site} (s. {page})'],
            'description' => '{title} hakkında {site}',
            'keywords' => 'forum',
            'robots' => ['mode' => 'append', 'text' => 'Disallow: /x'],
        ]);
        $filters = new SeoFilters(
            static fn(): SeoConfig => $config,
            fn(): SeoStore => self::fail('no per-page settings needed for an unkeyed page'),
            static fn(): string => 'Nolviko',
        );

        $meta = $filters->page(new PageMeta('thread', null, 'Merhaba', '', context: ['page' => '2']));
        self::assertSame('Merhaba – Nolviko (s. 2)', $meta->title);
        self::assertSame('Merhaba hakkında Nolviko', $meta->description);
        self::assertSame('forum', $meta->keywords);
        self::assertSame("User-agent: *\n\nDisallow: /x\n", $filters->robots("User-agent: *\n"));
        self::assertSame('/eski/sayfa', SeoFilters::normalize('/eski/sayfa/'));
        self::assertSame('/', SeoFilters::normalize('/'));
    }
}
