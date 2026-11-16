<?php

declare(strict_types=1);

namespace NolvikoExt\Seo;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Nolviko\Core\Cache\Cache;
use RuntimeException;
use Throwable;

/**
 * Per-page settings and redirects (2026-11-15). Pages read them on every view, so both lists are kept whole in
 * the core cache and dropped on every change; the database is read only after a change or when the cache is
 * cold. Both lists are bounded (MAX_ROWS) to keep that cheap.
 */
final class SeoStore
{
    public const MAX_ROWS = 5000;

    private const META = 'meta-v1';

    private const REDIRECTS = 'redirects-v1';

    public function __construct(
        private readonly Connection $db,
        private readonly Cache $cache,
        private readonly string $metaTable,
        private readonly string $redirectTable,
    ) {
    }

    /**
     * @return array<string, array{title: string, description: string, keywords: string, image: string,
     *     canonical: string, noindex: bool}> Page key => settings.
     */
    public function meta(): array
    {
        return $this->cached(self::META, function (): array {
            $rows = [];
            $sql = 'SELECT * FROM ' . $this->metaTable . ' ORDER BY page_key LIMIT ' . self::MAX_ROWS;
            foreach ($this->db->fetchAllAssociative($sql) as $row) {
                $rows[(string) $row['page_key']] = [
                    'title' => (string) $row['title'], 'description' => (string) $row['description'],
                    'keywords' => (string) $row['keywords'], 'image' => (string) $row['image'],
                    'canonical' => (string) $row['canonical'], 'noindex' => (bool) $row['noindex'],
                ];
            }

            return $rows;
        });
    }

    /**
     * @param array{title: string, description: string, keywords: string, image: string, canonical: string,
     *     noindex: bool} $values All empty and noindex off: the page's settings are removed.
     */
    public function saveMeta(string $key, array $values): void
    {
        $this->db->transactional(function () use ($key, $values): void {
            $this->db->delete($this->metaTable, ['page_key' => $key]);
            $text = implode('', array_map('strval', array_diff_key($values, ['noindex' => true])));
            if ($text !== '' || $values['noindex']) {
                if ((int) $this->db->fetchOne('SELECT COUNT(*) FROM ' . $this->metaTable) >= self::MAX_ROWS) {
                    throw new RuntimeException('nolviko_seo_limit');
                }
                $this->db->insert($this->metaTable, ['page_key' => $key] + $values + ['updated_at' => time()], [
                    'noindex' => ParameterType::BOOLEAN,
                ]);
            }
        });
        $this->cache->delete(self::META);
    }

    /** @return array<string, array{target: string, code: int, hits: int}> Source path => redirect. */
    public function redirects(): array
    {
        return $this->cached(self::REDIRECTS, function (): array {
            $rows = [];
            $sql = 'SELECT source, target, code, hits FROM ' . $this->redirectTable . ' ORDER BY source LIMIT '
                . self::MAX_ROWS;
            foreach ($this->db->fetchAllAssociative($sql) as $row) {
                $rows[(string) $row['source']] = ['target' => (string) $row['target'], 'code' => (int) $row['code'],
                    'hits' => (int) $row['hits']];
            }

            return $rows;
        });
    }

    public function saveRedirect(string $source, string $target, int $code): void
    {
        $this->db->transactional(function () use ($source, $target, $code): void {
            $this->db->delete($this->redirectTable, ['source' => $source]);
            if ((int) $this->db->fetchOne('SELECT COUNT(*) FROM ' . $this->redirectTable) >= self::MAX_ROWS) {
                throw new RuntimeException('nolviko_seo_limit');
            }
            $this->db->insert($this->redirectTable, ['source' => $source, 'target' => $target, 'code' => $code,
                'hits' => 0, 'created_at' => time()]);
        });
        $this->cache->delete(self::REDIRECTS);
    }

    public function deleteRedirect(string $source): void
    {
        $this->db->delete($this->redirectTable, ['source' => $source]);
        $this->cache->delete(self::REDIRECTS);
    }

    /** Counts a use; the cached list shows the count after the next change, which is enough for a statistic. */
    public function hit(string $source): void
    {
        try {
            $this->db->executeStatement(
                'UPDATE ' . $this->redirectTable . ' SET hits = hits + 1 WHERE source = ?',
                [$source],
            );
        } catch (Throwable) {
            // A statistic never breaks a redirect.
        }
    }

    /** @param Closure(): array $load */
    private function cached(string $key, Closure $load): array
    {
        try {
            $value = $this->cache->remember($key, 3600, $load);
        } catch (Throwable) {
            $value = $load();
        }

        return is_array($value) ? $value : [];
    }
}
