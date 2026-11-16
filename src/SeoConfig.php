<?php

declare(strict_types=1);

namespace NolvikoExt\Seo;

use Nolviko\Core\Extensions\SitemapRules;
use Nolviko\Core\Routing\CoreRoutes;
use RuntimeException;
use Throwable;

/**
 * The SEO extension's site-wide settings (2026-11-15) in one JSON file in the extension's storage directory:
 * address patterns must be known before the database is connected, and everything else is small. Every value is
 * cleaned on load and on save; a damaged file falls back to the defaults (core behaviour).
 */
final class SeoConfig
{
    public const TYPES = ['home', 'forum', 'thread', 'tag', 'gallery', 'album', 'image', 'other'];

    public const ROUTES = ['threads.show', 'forums.show', 'tags.show', 'gallery.album', 'gallery.item'];

    private const FILE = '/config.json';

    /** @var array<string, mixed> */
    public array $data;

    private function __construct(private readonly string $directory, array $data)
    {
        $this->data = self::clean($data);
    }

    public static function load(string $directory): self
    {
        try {
            $raw = is_file($directory . self::FILE) ? file_get_contents($directory . self::FILE) : false;
            $data = is_string($raw) ? json_decode($raw, true, 8) : [];
        } catch (Throwable) {
            $data = [];
        }

        return new self($directory, is_array($data) ? $data : []);
    }

    /** Writes atomically (temporary file and rename), so a request never reads half a file. */
    public function save(array $data): void
    {
        $this->data = self::clean($data);
        $temporary = $this->directory . '/.config-' . bin2hex(random_bytes(6)) . '.json';
        $json = json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            | JSON_THROW_ON_ERROR);
        $written = file_put_contents($temporary, $json . "\n", LOCK_EX) !== false;
        if (!$written || !rename($temporary, $this->directory . self::FILE)) {
            @unlink($temporary);
            throw new RuntimeException('nolviko_seo_config_unwritable');
        }
    }

    /** @return array<string, array{path: string, aliases: list<string>}> For ExtensionContext::routePatterns(). */
    public function routePatterns(): array
    {
        $patterns = [];
        foreach ($this->data['patterns'] as $route => $path) {
            $aliases = $this->data['aliases'][$route] ?? [];
            // Back to the default address: older custom addresses keep redirecting to it.
            $path = $path === '' && $aliases !== [] ? self::defaultPath($route) : $path;
            if ($path !== '') {
                $patterns[$route] = ['path' => $path, 'aliases' => $aliases];
            }
        }

        return $patterns;
    }

    public static function defaultPath(string $route): string
    {
        foreach (CoreRoutes::definitions() as $definition) {
            if ($definition->name === $route) {
                return $definition->path;
            }
        }

        return '';
    }

    public function sitemapRules(): SitemapRules
    {
        $map = $this->data['sitemap'];

        return new SitemapRules(
            $map['exclude_forums'],
            $map['exclude_threads'],
            $map['tags'],
            $map['forums'],
            $map['extra'],
        );
    }

    /** @return array<string, mixed> */
    public static function clean(array $data): array
    {
        $text = static fn(mixed $value, int $max): string => is_string($value)
            ? mb_substr(trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value)), 0, $max) : '';
        $ids = static fn(mixed $value): array => array_values(array_unique(array_filter(
            array_map('intval', is_array($value) ? $value : []),
            static fn(int $id): bool => $id > 0,
        )));

        $titles = [];
        foreach (self::TYPES as $type) {
            $titles[$type] = $text($data['titles'][$type] ?? '', 160);
        }
        $patterns = [];
        $aliases = [];
        foreach (self::ROUTES as $route) {
            $patterns[$route] = strtolower($text($data['patterns'][$route] ?? '', 120));
            $aliases[$route] = array_slice(array_values(array_filter(
                array_map(
                    static fn(mixed $path): string => $text($path, 120),
                    (array) ($data['aliases'][$route] ?? []),
                ),
                static fn(string $path): bool => $path !== '',
            )), 0, 8);
        }
        $image = $text($data['image'] ?? '', 2000);
        $mode = $data['robots']['mode'] ?? 'core';

        return [
            'titles' => $titles,
            'description' => $text($data['description'] ?? '', 320),
            'keywords' => $text($data['keywords'] ?? '', 255),
            'image' => preg_match('~^https?://\S+$~iD', $image) === 1 ? $image : '',
            'patterns' => $patterns,
            'aliases' => $aliases,
            'sitemap' => [
                'forums' => ($data['sitemap']['forums'] ?? true) !== false,
                'tags' => ($data['sitemap']['tags'] ?? true) !== false,
                'exclude_forums' => array_slice($ids($data['sitemap']['exclude_forums'] ?? []), 0, 1000),
                'exclude_threads' => array_slice($ids($data['sitemap']['exclude_threads'] ?? []), 0, 5000),
                'extra' => array_slice(array_values(array_filter(
                    array_map(
                        static fn(mixed $url): string => $text($url, 2000),
                        (array) ($data['sitemap']['extra'] ?? []),
                    ),
                    static fn(string $url): bool => preg_match('~^https?://\S+$~iD', $url) === 1,
                )), 0, 1000),
            ],
            'robots' => [
                'mode' => in_array($mode, ['core', 'append', 'replace'], true) ? $mode : 'core',
                'text' => $text($data['robots']['text'] ?? '', 16000),
            ],
        ];
    }
}
