<?php

declare(strict_types=1);

namespace NolvikoExt\Seo;

use Closure;
use Nolviko\Core\Extensions\PageMeta;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What the extension does on public requests (2026-11-15): page metadata (per-page settings first, then the
 * site-wide templates), robots.txt and the redirect manager. Settings and stores are built only when a request
 * needs them.
 */
final class SeoFilters
{
    /**
     * @param Closure(): SeoConfig $config
     * @param Closure(): SeoStore $store
     * @param Closure(): string $siteName
     */
    public function __construct(
        private readonly Closure $config,
        private readonly Closure $store,
        private readonly Closure $siteName,
    ) {
    }

    public function page(PageMeta $meta): PageMeta
    {
        $config = ($this->config)()->data;
        $own = $meta->key === null ? null : (($this->store)()->meta()[$meta->key] ?? null);
        $values = ['title' => $meta->title, 'site' => ($this->siteName)(), 'page' => $meta->context['page'] ?? ''];
        $fill = static fn(string $template): string => trim(strtr($template, [
            '{title}' => $values['title'], '{site}' => $values['site'], '{page}' => (string) $values['page'],
        ]));

        $changes = [];
        $template = $config['titles'][$meta->type] ?? '';
        if ($own !== null && $own['title'] !== '') {
            $changes['title'] = $own['title'];
        } elseif ($template !== '') {
            $changes['title'] = $fill($template);
        }
        if ($own !== null && $own['description'] !== '') {
            $changes['description'] = $own['description'];
        } elseif ($meta->description === '' && $config['description'] !== '') {
            $changes['description'] = $fill($config['description']);
        }
        $keywords = $own['keywords'] ?? '';
        $changes['keywords'] = $keywords !== '' ? $keywords : $config['keywords'];
        $image = ($own['image'] ?? '') !== '' ? $own['image'] : ($meta->image ?? ($config['image'] ?: null));
        $changes['image'] = $image;
        if (($own['canonical'] ?? '') !== '') {
            $changes['canonical'] = $own['canonical'];
        }
        if (($own['noindex'] ?? false) === true) {
            $changes['noindex'] = true;
        }

        return $meta->with($changes);
    }

    public function robots(string $core): string
    {
        $robots = ($this->config)()->data['robots'];

        return match ($robots['mode']) {
            'append' => rtrim($core) . "\n\n" . $robots['text'] . "\n",
            'replace' => $robots['text'] . "\n",
            default => $core,
        };
    }

    /** The redirect manager: an exact path match (without the trailing slash) answers 301, 302 or 410. */
    public function notFound(Request $request): ?Response
    {
        $path = self::normalize($request->getPathInfo());
        $redirects = ($this->store)()->redirects();
        if (!isset($redirects[$path])) {
            return null;
        }

        $rule = $redirects[$path];
        ($this->store)()->hit($path);
        if ($rule['code'] === 410) {
            return new Response('', Response::HTTP_GONE, ['Cache-Control' => 'public, max-age=300',
                'Content-Type' => 'text/plain; charset=UTF-8', 'X-Robots-Tag' => 'noindex']);
        }

        $target = str_starts_with($rule['target'], '/') ? $request->getBaseUrl() . $rule['target'] : $rule['target'];

        return new Response('', $rule['code'] === 302 ? Response::HTTP_FOUND : Response::HTTP_MOVED_PERMANENTLY, [
            'Location' => $target, 'Cache-Control' => $rule['code'] === 302 ? 'no-store' : 'public, max-age=3600',
        ]);
    }

    public static function normalize(string $path): string
    {
        $path = '/' . ltrim(rawurldecode($path), '/');

        return strlen($path) > 1 ? rtrim($path, '/') : $path;
    }
}
