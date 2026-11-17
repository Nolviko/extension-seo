<?php

declare(strict_types=1);

namespace NolvikoExt\Seo;

use Closure;
use InvalidArgumentException;
use Nolviko\Core\Http\ErrorResponder;
use Nolviko\Core\Http\InstallerPage;
use Nolviko\Core\Http\RouteHandler;
use Nolviko\Core\Http\SeoMetadata;
use Nolviko\Core\Locale\MessageTranslator;
use Nolviko\Core\Routing\CoreRoutes;
use Nolviko\Core\Routing\RoutePatterns;
use Nolviko\Core\Routing\Router;
use Nolviko\Core\Session\CsrfTokens;
use Nolviko\Core\Session\Session;
use Nolviko\Core\View\SafeHtml;
use Nolviko\Core\View\TemplateRenderer;
use Nolviko\Modules\Admin\Http\AdminNavigation;
use Nolviko\Modules\Admin\Http\AdminSession;
use Nolviko\Modules\Admin\Infrastructure\DbalAdminAudit;
use Nolviko\Modules\Seo\Domain\SiteUrl;
use Nolviko\Modules\Users\Http\RequestSubject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `/ext/nolviko/seo/admin` (2026-11-15): the SEO settings, for administrators only (owner decision).
 * Tabs: general (title and description templates), pages (settings for one address), addresses (patterns),
 * sitemap, robots.txt and redirects. Saving needs a password confirmation from the last 15 minutes like every
 * sensitive admin page; each save is audited. All text is translated and every value escaped here.
 */
final class AdminHandler implements RouteHandler
{
    public const ROUTE = 'nolviko.seo.admin';

    private const TABS = ['general', 'pages', 'addresses', 'sitemap', 'robots', 'redirects'];

    private const FLASH = '_nolviko_seo_notice';

    /** Sample values for checking that every moved page is reached by its own address. */
    private const SAMPLES = [
        CoreRoutes::THREAD_SHOW => ['slug' => 'ornek-konu', 'thread' => '123'],
        CoreRoutes::FORUM_SHOW => ['forum' => '12'],
        CoreRoutes::TAG_SHOW => ['tag' => 'ornek'],
        CoreRoutes::GALLERY_ALBUM => ['album' => '7'],
        CoreRoutes::GALLERY_ITEM => ['item' => '9'],
    ];

    /**
     * @param Closure(): SeoConfig $config
     * @param Closure(): SeoStore $store
     */
    public function __construct(
        private readonly MessageTranslator $translator,
        private readonly TemplateRenderer $views,
        private readonly RequestSubject $subjects,
        private readonly AdminSession $admin,
        private readonly CsrfTokens $csrf,
        private readonly Router $router,
        private readonly Session $session,
        private readonly DbalAdminAudit $audit,
        private readonly ErrorResponder $errors,
        private readonly SiteUrl $site,
        private readonly Closure $config,
        private readonly Closure $store,
    ) {
    }

    public function handle(Request $request, array $parameters): Response
    {
        $subject = $this->subjects->of($request);
        if ($subject->userId === null || !$subject->allows('admin.access')) {
            return $subject->userId === null
                ? InstallerPage::redirect($this->router->generate(CoreRoutes::LOGIN)) : $this->errors->denied();
        }

        $tab = (string) $request->query->get('tab', 'general');
        $tab = in_array($tab, self::TABS, true) ? $tab : 'general';
        $error = null;
        if ($request->isMethod('POST')) {
            if (!$this->admin->isFresh()) {
                $error = $this->text('reauth');
            } else {
                try {
                    $notice = $this->save($tab, $request->request->all(), (int) $subject->userId);
                    $this->session->set(self::FLASH, $notice);

                    return InstallerPage::redirect($this->self($tab, $tab === 'pages'
                        ? (string) ($request->request->all()['address'] ?? '') : null));
                } catch (InvalidArgumentException $invalid) {
                    $error = $this->text('error.' . $invalid->getMessage());
                } catch (Throwable) {
                    $error = $this->text('error.failed');
                }
            }
        }

        $notice = $this->session->get(self::FLASH);
        $this->session->remove(self::FLASH);
        $body = $this->tabs($tab)
            . (is_string($notice) ? '<p role="status">' . $this->e($notice) . '</p>' : '')
            . ($error === null ? '' : '<p role="alert">' . $this->e($error) . '</p>')
            . ($this->admin->isFresh() ? '' : '<p>' . $this->e($this->text('reauth_hint')) . ' <a href="'
                . $this->e($this->router->generate(CoreRoutes::ADMIN_REAUTH)) . '">'
                . $this->e($this->translator->translate('admin.reauth.title')) . '</a></p>')
            . match ($tab) {
                'pages' => $this->pagesTab((string) $request->query->get('address', '')),
                'addresses' => $this->addressesTab(),
                'sitemap' => $this->sitemapTab(),
                'robots' => $this->robotsTab(),
                'redirects' => $this->redirectsTab(),
                default => $this->generalTab(),
            };

        $html = $this->views->render('extensions/admin_page.twig', [
            'title' => $this->text('title'),
            'nav' => AdminNavigation::items($this->router, $this->translator, CoreRoutes::ADMIN_EXTENSIONS),
            'body' => new SafeHtml($body),
        ]);

        return new Response($html, $error === null ? 200 : 422, [
            'Content-Type' => 'text/html; charset=UTF-8', 'Cache-Control' => 'no-store, private',
        ]);
    }

    /** @return string The notice to show after the redirect. */
    private function save(string $tab, array $input, int $userId): string
    {
        $field = static fn(string $name): string => is_string($input[$name] ?? null) ? $input[$name] : '';
        $config = ($this->config)();
        $data = $config->data;

        switch ($tab) {
            case 'general':
                foreach (SeoConfig::TYPES as $type) {
                    $data['titles'][$type] = $field('title_' . $type);
                }
                $data['description'] = $field('description');
                $data['keywords'] = $field('keywords');
                $data['image'] = $field('image');
                if ($data['image'] !== '' && preg_match('~^https?://\S+$~iD', $data['image']) !== 1) {
                    throw new InvalidArgumentException('image');
                }
                $config->save($data);
                break;
            case 'pages':
                $key = $this->pageKey($field('address'));
                if ($key === null) {
                    throw new InvalidArgumentException('address');
                }
                $values = [
                    'title' => mb_substr(trim($field('title')), 0, 200),
                    'description' => mb_substr(trim($field('description')), 0, 320),
                    'keywords' => mb_substr(trim($field('keywords')), 0, 255),
                    'image' => trim($field('image')),
                    'canonical' => trim($field('canonical')),
                    'noindex' => $field('noindex') === '1',
                ];
                foreach (['image', 'canonical'] as $url) {
                    if ($values[$url] !== '' && preg_match('~^https?://\S+$~iD', $values[$url]) !== 1) {
                        throw new InvalidArgumentException($url);
                    }
                }
                ($this->store)()->saveMeta($key, $field('delete') === '1'
                    ? ['title' => '', 'description' => '', 'keywords' => '', 'image' => '', 'canonical' => '',
                        'noindex' => false] : $values);
                break;
            case 'addresses':
                $patterns = [];
                foreach (SeoConfig::ROUTES as $route) {
                    $new = strtolower(trim($field('pattern_' . str_replace('.', '_', $route))));
                    $old = $data['patterns'][$route];
                    $aliases = $data['aliases'][$route];
                    if ($old !== '' && $old !== $new) {
                        array_unshift($aliases, $old);
                    }
                    $data['patterns'][$route] = $new;
                    $kept = array_values(array_unique(array_diff($aliases, [$new])));
                    $data['aliases'][$route] = array_slice($kept, 0, 8);
                    $path = $new === '' && $data['aliases'][$route] !== [] ? SeoConfig::defaultPath($route) : $new;
                    if ($path !== '') {
                        $patterns[$route] = ['path' => $path, 'aliases' => $data['aliases'][$route]];
                    }
                }
                try {
                    $built = RoutePatterns::build(CoreRoutes::definitions(), $patterns);
                } catch (InvalidArgumentException $invalid) {
                    throw new InvalidArgumentException(explode(':', $invalid->getMessage())[0]);
                }
                $samples = array_intersect_key(self::SAMPLES, $patterns);
                if (RoutePatterns::conflicts($built['definitions'], $samples) !== []) {
                    throw new InvalidArgumentException('route_pattern_conflict');
                }
                $config->save($data);
                break;
            case 'sitemap':
                $data['sitemap'] = [
                    'forums' => $field('forums') === '1',
                    'tags' => $field('tags') === '1',
                    'exclude_forums' => self::numbers($field('exclude_forums')),
                    'exclude_threads' => self::numbers($field('exclude_threads')),
                    'extra' => self::lines($field('extra')),
                ];
                $origin = $this->site->origin();
                foreach ($data['sitemap']['extra'] as $url) {
                    if ($origin === null || !str_starts_with($url, $origin . '/')) {
                        throw new InvalidArgumentException('extra');
                    }
                }
                $config->save($data);
                break;
            case 'robots':
                $data['robots'] = ['mode' => $field('mode'), 'text' => str_replace("\r\n", "\n", $field('text'))];
                $config->save($data);
                break;
            case 'redirects':
                if ($field('delete') !== '') {
                    ($this->store)()->deleteRedirect(SeoFilters::normalize($field('delete')));
                    break;
                }
                $source = SeoFilters::normalize((string) parse_url($field('source'), PHP_URL_PATH));
                $target = trim($field('target'));
                $code = (int) $field('code');
                if (
                    $source === '/' || strlen($source) > 255 || !in_array($code, [301, 302, 410], true)
                    || ($code !== 410 && preg_match('~^(?:/[^\s]*|https?://\S+)$~iD', $target) !== 1)
                    || strlen($target) > 2000 || ($code !== 410 && SeoFilters::normalize($target) === $source)
                ) {
                    throw new InvalidArgumentException('redirect');
                }
                ($this->store)()->saveRedirect($source, $code === 410 ? '' : $target, $code);
                break;
        }

        $this->audit->record($userId, 'nolviko.seo.' . $tab . '_saved');

        return $this->text('saved');
    }

    private function generalTab(): string
    {
        $data = ($this->config)()->data;
        $html = $this->paragraph('general_help');
        foreach (SeoConfig::TYPES as $type) {
            $html .= $this->input('title_' . $type, $this->text('type.' . $type), $data['titles'][$type], 'text', 160);
        }

        return $this->form('general', $html
            . $this->input('description', $this->text('description_template'), $data['description'], 'text', 320)
            . $this->input('keywords', $this->text('keywords_default'), $data['keywords'], 'text', 255)
            . $this->input('image', $this->text('image_default'), $data['image'], 'url', 2000));
    }

    private function pagesTab(string $address): string
    {
        $html = $this->paragraph('pages_help')
            . '<form method="get" action="' . $this->e($this->router->generate(self::ROUTE)) . '" class="row">'
            . '<input type="hidden" name="tab" value="pages">'
            . '<p><label for="seo-find">' . $this->e($this->text('address')) . '</label>'
            . '<input id="seo-find" name="address" type="text" value="' . $this->e($address) . '" maxlength="2000"></p>'
            . '<p><button class="btn-s" type="submit">' . $this->e($this->text('open')) . '</button></p></form>';

        $meta = ($this->store)()->meta();
        if ($address !== '') {
            $key = $this->pageKey($address);
            if ($key === null) {
                $html .= '<p role="alert">' . $this->e($this->text('error.address')) . '</p>';
            } else {
                $row = $meta[$key] ?? ['title' => '', 'description' => '', 'keywords' => '', 'image' => '',
                    'canonical' => '', 'noindex' => false];
                $hidden = '<input type="hidden" name="address" value="' . $this->e($address) . '">';
                $html .= '<h2>' . $this->e($key) . '</h2>' . $this->form('pages', $hidden
                    . $this->input('title', $this->text('page_title'), $row['title'], 'text', 200)
                    . $this->input('description', $this->text('page_description'), $row['description'], 'textarea', 320)
                    . $this->input('keywords', $this->text('page_keywords'), $row['keywords'], 'text', 255)
                    . $this->input('image', $this->text('page_image'), $row['image'], 'url', 2000)
                    . $this->input('canonical', $this->text('page_canonical'), $row['canonical'], 'url', 2000)
                    . $this->checkbox('noindex', $this->text('page_noindex'), $row['noindex'])
                    . $this->checkbox('delete', $this->text('page_delete'), false));
            }
        }

        if ($meta !== []) {
            $html .= '<h2>' . $this->e($this->text('pages_list')) . '</h2><ul class="stack">';
            foreach (array_slice($meta, 0, 200, true) as $key => $row) {
                $html .= '<li><code>' . $this->e((string) $key) . '</code> ' . $this->e($row['title'])
                    . ($row['noindex'] ? ' <span class="chip chip-amber">noindex</span>' : '') . '</li>'; // i18n-ignore
            }
            $html .= '</ul>';
        }

        return $html;
    }

    private function addressesTab(): string
    {
        $data = ($this->config)()->data;
        $defaults = [];
        foreach (CoreRoutes::definitions() as $definition) {
            $defaults[$definition->name] = $definition->path;
        }
        $html = $this->paragraph('addresses_help') . $this->paragraph('addresses_rules');
        foreach (SeoConfig::ROUTES as $route) {
            $name = 'pattern_' . str_replace('.', '_', $route);
            $label = $this->text('route.' . str_replace('.', '_', $route)) . ' — ' . $this->text('default') . ' '
                . $defaults[$route];
            $html .= $this->input($name, $label, $data['patterns'][$route], 'text', 120, $defaults[$route]);
            if ($data['aliases'][$route] !== []) {
                $html .= '<p class="muted">' . $this->e($this->text('aliases')) . ' '
                    . $this->e(implode(', ', $data['aliases'][$route])) . '</p>';
            }
        }

        return $this->form('addresses', $html);
    }

    private function sitemapTab(): string
    {
        $map = ($this->config)()->data['sitemap'];
        $index = $this->site->absolute($this->router->generate(CoreRoutes::SITEMAP_INDEX));

        $link = $index === null ? '' : '<p>' . $this->e($this->text('sitemap_address')) . ' <a href="'
            . $this->e($index) . '">' . $this->e($index) . '</a></p>';

        return $link
            . $this->paragraph('sitemap_help')
            . $this->form('sitemap', $this->checkbox('forums', $this->text('sitemap_forums'), $map['forums'])
                . $this->checkbox('tags', $this->text('sitemap_tags'), $map['tags'])
                . $this->input(
                    'exclude_forums',
                    $this->text('sitemap_exclude_forums'),
                    implode(', ', $map['exclude_forums']),
                    'text',
                    8000,
                )
                . $this->input(
                    'exclude_threads',
                    $this->text('sitemap_exclude_threads'),
                    implode(', ', $map['exclude_threads']),
                    'textarea',
                    40000,
                )
                . $this->input('extra', $this->text('sitemap_extra'), implode("\n", $map['extra']), 'textarea', 99999));
    }

    private function robotsTab(): string
    {
        $robots = ($this->config)()->data['robots'];
        $options = '';
        foreach (['core', 'append', 'replace'] as $mode) {
            $options .= '<p><input id="seo-mode-' . $mode . '" name="mode" type="radio" value="' . $mode . '"'
                . ($robots['mode'] === $mode ? ' checked' : '') . '> <label for="seo-mode-' . $mode . '">'
                . $this->e($this->text('robots_' . $mode)) . '</label></p>';
        }
        $current = $this->site->absolute($this->router->generate(CoreRoutes::ROBOTS));

        return $this->paragraph('robots_help')
            . ($current === null ? '' : '<p><a href="' . $this->e($current) . '">' . $this->e($current) . '</a></p>')
            . $this->form('robots', '<fieldset class="stack"><legend>' . $this->e($this->text('robots_mode'))
                . '</legend>' . $options . '</fieldset>'
                . $this->input('text', $this->text('robots_text'), $robots['text'], 'textarea', 16000));
    }

    private function redirectsTab(): string
    {
        $codes = '';
        foreach ([301, 302, 410] as $code) {
            $codes .= '<option value="' . $code . '">' . $this->e($this->text('code_' . $code)) . '</option>';
        }
        $html = $this->paragraph('redirects_help')
            . $this->form('redirects', $this->input('source', $this->text('redirect_source'), '', 'text', 255, '/old')
                . $this->input('target', $this->text('redirect_target'), '', 'text', 2000, '/new')
                . '<p><label for="seo-code">' . $this->e($this->text('redirect_code')) . '</label>'
                . '<select id="seo-code" name="code">' . $codes . '</select></p>');

        $rows = ($this->store)()->redirects();
        if ($rows !== []) {
            $html .= '<table class="table"><thead><tr><th>' . $this->e($this->text('redirect_source')) . '</th><th>'
                . $this->e($this->text('redirect_target')) . '</th><th>' . $this->e($this->text('redirect_code'))
                . '</th><th>' . $this->e($this->text('redirect_hits')) . '</th><th></th></tr></thead><tbody>';
            foreach (array_slice($rows, 0, 500, true) as $source => $rule) {
                $html .= '<tr><td><code>' . $this->e((string) $source) . '</code></td><td>' . $this->e($rule['target'])
                    . '</td><td>' . $rule['code'] . '</td><td>' . $rule['hits'] . '</td><td>'
                    . '<form method="post" action="' . $this->e($this->self('redirects')) . '">' . $this->token()
                    . '<button class="btn-s btn-sm" type="submit" name="delete" value="'
                    . $this->e((string) $source) . '">'
                    . $this->e($this->text('delete')) . '</button></form></td></tr>';
            }
            $html .= '</tbody></table>';
        }

        return $html;
    }

    private function tabs(string $current): string
    {
        $links = '';
        foreach (self::TABS as $tab) {
            $active = $tab === $current;
            $links .= '<a class="chip' . ($active ? ' chip-new' : '') . '" href="' . $this->e($this->self($tab)) . '"'
                . ($active ? ' aria-current="page"' : '') . '>' . $this->e($this->text('tab.' . $tab)) . '</a> ';
        }

        return '<nav class="row" aria-label="' . $this->e($this->text('title')) . '">' . $links . '</nav>';
    }

    private function pageKey(string $address): ?string
    {
        $path = (string) (parse_url(trim($address), PHP_URL_PATH) ?? '');
        $base = rtrim($this->site->basePath(), '/');
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base)) ?: '/';
        }
        try {
            $parameters = $this->router->match(Request::create($path === '' ? '/' : $path));
        } catch (Throwable) {
            return null;
        }
        if (isset($parameters['_alias_of'])) {
            $parameters['_route'] = $parameters['_alias_of'];
        }

        return SeoMetadata::page($parameters)[1];
    }

    private function form(string $tab, string $fields): string
    {
        return '<form method="post" action="' . $this->e($this->self($tab)) . '" class="panel panel-pad stack">'
            . $this->token() . $fields . '<p><button type="submit"' . ($this->admin->isFresh() ? '' : ' disabled') . '>'
            . $this->e($this->text('save')) . '</button></p></form>';
    }

    private function input(
        string $name,
        string $label,
        string $value,
        string $type,
        int $max,
        string $placeholder = '',
    ): string {
        $id = 'seo-' . $name;
        $attributes = ' id="' . $id . '" name="' . $name . '" maxlength="' . $max . '"'
            . ($placeholder === '' ? '' : ' placeholder="' . $this->e($placeholder) . '"');

        return '<p><label for="' . $id . '">' . $this->e($label) . '</label>'
            . ($type === 'textarea'
                ? '<textarea' . $attributes . ' rows="5">' . $this->e($value) . '</textarea>'
                : '<input' . $attributes . ' type="' . ($type === 'url' ? 'url' : 'text') . '" value="'
                    . $this->e($value) . '">')
            . '</p>';
    }

    private function checkbox(string $name, string $label, bool $checked): string
    {
        return '<p><input id="seo-' . $name . '" name="' . $name . '" type="checkbox" value="1"'
            . ($checked ? ' checked' : '')
            . '> <label for="seo-' . $name . '">' . $this->e($label) . '</label></p>';
    }

    private function token(): string
    {
        return '<input type="hidden" name="' . $this->e(CsrfTokens::FIELD) . '" value="'
            . $this->e($this->csrf->token()) . '">';
    }

    private function self(string $tab, ?string $address = null): string
    {
        return $this->router->generate(self::ROUTE) . '?tab=' . rawurlencode($tab)
            . ($address === null || $address === '' ? '' : '&address=' . rawurlencode($address));
    }

    private function paragraph(string $key): string
    {
        return '<p>' . $this->e($this->text($key)) . '</p>';
    }

    private function text(string $key): string
    {
        return $this->translator->translate('nolviko.seo.' . $key);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /** @return list<int> */
    private static function numbers(string $text): array
    {
        preg_match_all('/\d{1,18}/', $text, $matches);

        return array_values(array_unique(array_map('intval', $matches[0])));
    }

    /** @return list<string> */
    private static function lines(string $text): array
    {
        $lines = array_map('trim', preg_split('/\R/', $text) ?: []);

        return array_values(array_filter($lines, static fn(string $line): bool => $line !== ''));
    }
}
