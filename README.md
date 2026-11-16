# Advanced SEO for Nolviko

Official [Nolviko](https://github.com/Nolviko/nolviko) extension in the spirit of the WordPress SEO plugins, on top of the search-engine
features Nolviko already has (canonical addresses, sitemaps, Open Graph, structured data, IndexNow).

## Features

- **Addresses:** choose the address pattern of threads, forums, tag pages and the gallery, e.g.
  `/konu/{slug}-{thread}`, `/t/{thread}` or `/forum/{forum}`. Every older address keeps answering with a 301,
  so links and search results never break.
- **Titles and descriptions:** templates per page type with `{title}`, `{site}` and `{page}`
- **Per-page settings:** paste a page address and set its title, description, keywords, sharing image,
  canonical address or noindex
- **Sitemap:** include or leave out forums, tag pages, single forums or threads; add your own addresses
- **robots.txt:** keep the safe default, add lines or replace it
- **Redirects:** 301, 302 or 410 for addresses no page answers any more, with a usage counter

Administrators only; saving needs a recent password confirmation and is audited. Private forums never appear in
sitemaps or page data, whatever the settings.

## Installation

1. Download the latest `nolviko-ext-nolviko-seo-<version>.zip` from this repository's **Releases** page, and copy
   the SHA-256 checksum shown next to it.
2. In your forum: **Admin → Extensions → Upload**, choose the ZIP, paste the SHA-256 and confirm that you trust
   the package.
3. Install and enable it. Updates work the same way: upload the newer ZIP; the forum turns the extension off,
   replaces its files and turns it on again. Your settings and data are kept.

Requires [Nolviko](https://github.com/Nolviko/nolviko) 0.1.0-beta.2 or newer. Disabling the extension keeps its data.

## Development

The package contains `extension.json`, `LICENSE`, `src/`, `translations/`, `migrations/`; `tests/` and this README
stay in the repository. The extension uses only Nolviko core classes and PHP's standard library — no Composer
packages of its own.

Tests run against a Nolviko core checkout with its development dependencies:

```bash
php <nolviko-dev-vendor>/bin/phpunit --bootstrap <nolviko-dev-vendor>/autoload.php tests
```

Code style follows the core project (PSR-12, 120 columns). All text goes through `translations/en.json` and
`translations/tr.json`.

## License

[Apache License 2.0](LICENSE), like Nolviko itself.
