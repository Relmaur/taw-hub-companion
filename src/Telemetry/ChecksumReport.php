<?php

declare(strict_types=1);

namespace TAW\HubCompanion\Telemetry;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The `GET /inventory/checksums` payload — a per-component SHA-256 file
 * manifest, so the Hub can
 *
 *   - detect file-integrity drift (a webshell dropped into a plugin folder
 *     post-compromise) by comparing against the official release hashes, and
 *   - diff one version of a component against the next on update — the
 *     "quiet backdoor on update" signal (new phone-home, new application
 *     passwords, a new drop-in) — and
 *   - dedupe fleet-wide analysis by `(slug, version, tree_hash)`.
 *
 * The companion only produces ground truth (which files exist, and their
 * hashes). All comparison and analysis is the Hub's.
 *
 * Reads the filesystem but never a subprocess. Two modes:
 *   - summary (no `slug`): `tree_hash` + `file_count` per active component —
 *     a cheap "did anything change" signal for the whole fleet.
 *   - detail (`slug` given): adds the full `files` map for the matched
 *     component(s).
 *
 * Only executable / script file types are hashed (`.php`, `.js`, …, plus
 * extension-less files); images, fonts, CSS, language files and the
 * `node_modules` / `.git` trees are skipped. A component over {@see MAX_FILES}
 * hashed files is reported `truncated`.
 */
final class ChecksumReport
{
    private const SCHEMA_VERSION = 1;
    private const MAX_FILES      = 6000;
    private const MAX_FILE_BYTES = 3_000_000;

    /** @var list<string> */
    private const HASH_EXTENSIONS = [
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phps', 'phar', 'inc', 'pht',
        'js', 'mjs', 'cjs', 'html', 'htm', 'sh', 'pl', 'py', 'twig',
    ];

    /** @var list<string> directory names skipped wholesale */
    private const SKIP_DIRS = ['node_modules', '.git', '.svn', '.hg'];

    /**
     * @return array<string, mixed>
     */
    public function collect(?string $slug = null, ?string $type = null): array
    {
        $detail = $slug !== null;

        $components = [];
        foreach ($this->targets($slug, $type) as $target) {
            $components[] = $this->hashComponent($target, $detail);
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at'   => gmdate('c'),
            'mode'           => $detail ? 'detail' : 'summary',
            'components'     => $components,
        ];
    }

    /**
     * @return list<array{type: string, slug: string, file: ?string, version: ?string, path: string}>
     */
    private function targets(?string $slug, ?string $type): array
    {
        $wanted = $type !== null && in_array($type, ['plugin', 'mu_plugin', 'theme'], true) ? $type : null;

        $targets = [];
        if ($wanted === null || $wanted === 'plugin') {
            array_push($targets, ...$this->pluginTargets());
        }
        if ($wanted === null || $wanted === 'mu_plugin') {
            array_push($targets, ...$this->muPluginTargets());
        }
        if ($wanted === null || $wanted === 'theme') {
            array_push($targets, ...$this->themeTargets());
        }

        if ($slug !== null) {
            $targets = array_values(array_filter($targets, static fn (array $t): bool => $t['slug'] === $slug));
        }

        return $targets;
    }

    /**
     * @return list<array{type: string, slug: string, file: ?string, version: ?string, path: string}>
     */
    private function pluginTargets(): array
    {
        if (!function_exists('get_plugins')) {
            $file = ABSPATH . 'wp-admin/includes/plugin.php';
            if (is_readable($file)) {
                require_once $file;
            }
        }
        if (!function_exists('get_plugins') || !defined('WP_PLUGIN_DIR')) {
            return [];
        }

        $base = rtrim((string) WP_PLUGIN_DIR, '/');
        $out  = [];
        foreach (get_plugins() as $file => $data) {
            $file = (string) $file;
            if (function_exists('is_plugin_active') && !is_plugin_active($file)) {
                continue;
            }
            $out[] = [
                'type'    => 'plugin',
                'slug'    => str_contains($file, '/') ? explode('/', $file, 2)[0] : basename($file, '.php'),
                'file'    => $file,
                'version' => self::str($data['Version'] ?? null),
                'path'    => str_contains($file, '/') ? $base . '/' . dirname($file) : $base . '/' . $file,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{type: string, slug: string, file: ?string, version: ?string, path: string}>
     */
    private function muPluginTargets(): array
    {
        if (!function_exists('get_mu_plugins') || !defined('WPMU_PLUGIN_DIR')) {
            return [];
        }

        $base = rtrim((string) WPMU_PLUGIN_DIR, '/');
        $out  = [];
        foreach (get_mu_plugins() as $file => $data) {
            $file = (string) $file;
            $out[] = [
                'type'    => 'mu_plugin',
                'slug'    => basename($file, '.php'),
                'file'    => $file,
                'version' => self::str($data['Version'] ?? null),
                'path'    => $base . '/' . $file,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{type: string, slug: string, file: ?string, version: ?string, path: string}>
     */
    private function themeTargets(): array
    {
        if (!function_exists('wp_get_themes') || !function_exists('get_theme_root')) {
            return [];
        }

        $active   = function_exists('get_stylesheet') ? (string) get_stylesheet() : '';
        $template = function_exists('get_template') ? (string) get_template() : '';
        $slugs    = array_values(array_unique(array_filter([$active, $template])));

        $out = [];
        foreach (wp_get_themes() as $slug => $theme) {
            $slug = (string) $slug;
            if (!in_array($slug, $slugs, true)) {
                continue;
            }
            $root    = (string) get_theme_root($slug);
            $version = $theme->get('Version');
            $out[]   = [
                'type'    => 'theme',
                'slug'    => $slug,
                'file'    => null,
                'version' => is_string($version) && $version !== '' ? $version : null,
                'path'    => rtrim($root, '/') . '/' . $slug,
            ];
        }

        return $out;
    }

    /**
     * @param array{type: string, slug: string, file: ?string, version: ?string, path: string} $target
     * @return array<string, mixed>
     */
    private function hashComponent(array $target, bool $detail): array
    {
        $files     = [];
        $truncated = false;
        $missing   = false;

        if (is_file($target['path'])) {
            $hash = @hash_file('sha256', $target['path']);
            if ($hash !== false) {
                $files[basename($target['path'])] = $hash;
            }
        } elseif (is_dir($target['path'])) {
            [$files, $truncated] = $this->walk($target['path']);
        } else {
            $missing = true;
        }

        ksort($files);

        $lines = [];
        foreach ($files as $relPath => $hash) {
            $lines[] = $relPath . ':' . $hash;
        }

        $component = [
            'type'       => $target['type'],
            'slug'       => $target['slug'],
            'file'       => $target['file'],
            'version'    => $target['version'],
            'file_count' => count($files),
            'truncated'  => $truncated,
            'missing'    => $missing,
            'tree_hash'  => hash('sha256', implode("\n", $lines)),
        ];

        if ($detail) {
            $component['files'] = $files;
        }

        return $component;
    }

    /**
     * @return array{0: array<string, string>, 1: bool} [relpath => sha256, truncated]
     */
    private function walk(string $dir): array
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                static fn (\SplFileInfo $current): bool =>
                    !$current->isDir() || !in_array($current->getFilename(), self::SKIP_DIRS, true),
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        $prefix = strlen($dir) + 1;
        $files  = [];
        $count  = 0;

        foreach ($iterator as $info) {
            if (!$info instanceof \SplFileInfo || !$info->isFile() || $info->isLink()) {
                continue;
            }

            $rel = substr($info->getPathname(), $prefix);
            if (!self::shouldHash($rel)) {
                continue;
            }

            if ($count >= self::MAX_FILES) {
                return [$files, true];
            }

            if ($info->getSize() > self::MAX_FILE_BYTES) {
                continue;
            }

            $hash = @hash_file('sha256', $info->getPathname());
            if ($hash === false) {
                continue;
            }

            $files[str_replace('\\', '/', $rel)] = $hash;
            $count++;
        }

        return [$files, false];
    }

    private static function shouldHash(string $relPath): bool
    {
        $ext = strtolower(pathinfo($relPath, PATHINFO_EXTENSION));

        // Extension-less files (`bin/tool`, dot-scripts) are hashed too — a
        // classic place to hide an `include`-d payload.
        return $ext === '' || in_array($ext, self::HASH_EXTENSIONS, true);
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
