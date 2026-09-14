<?php

declare(strict_types=1);

namespace Pest\Plugins\Tia;

use Pest\Support\Git;
use SimpleXMLElement;
use Throwable;

/**
 * @internal
 */
final class ExternalSources
{
    /**
     * @var array<string, array<int, string>>
     */
    private static array $cache = [];

    /**
     * @return array<int, string>
     */
    public static function rootsFor(string $projectRoot): array
    {
        return self::$cache[$projectRoot] ??= self::resolve($projectRoot);
    }

    /**
     * @param  array<int, string>  $files  Repository-relative paths.
     * @return array<int, string>
     */
    public static function matching(string $projectRoot, array $files): array
    {
        $roots = self::rootsFor($projectRoot);

        if ($roots === []) {
            return [];
        }

        $matched = [];

        foreach ($files as $file) {
            foreach ($roots as $root) {
                if (str_starts_with($file, $root)) {
                    $matched[] = $file;

                    break;
                }
            }
        }

        return $matched;
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    /**
     * @return array<int, string>
     */
    private static function resolve(string $projectRoot): array
    {
        $git = new Git($projectRoot);

        if ($git->pathPrefix() === '') {
            return [];
        }

        $repositoryRoot = $git->repositoryRoot();

        if ($repositoryRoot === null) {
            return [];
        }

        $project = self::realpath($projectRoot);

        if ($project === null) {
            return [];
        }

        $roots = [];

        foreach ([...self::composerPaths($projectRoot), ...self::phpunitSourcePaths($projectRoot)] as $candidate) {
            $resolved = self::realpath($projectRoot.DIRECTORY_SEPARATOR.$candidate);

            if ($resolved === null) {
                continue;
            }

            if ($resolved === $project || str_starts_with($resolved, $project.'/')) {
                continue;
            }

            if (! str_starts_with($resolved, $repositoryRoot.'/')) {
                continue;
            }

            $roots[substr($resolved, strlen($repositoryRoot) + 1).'/'] = true;
        }

        $roots = array_keys($roots);
        sort($roots);

        return $roots;
    }

    /**
     * @return array<int, string>
     */
    private static function composerPaths(string $projectRoot): array
    {
        $manifest = self::decodeJson($projectRoot.DIRECTORY_SEPARATOR.'composer.json');

        if ($manifest === null) {
            return [];
        }

        $paths = [];

        foreach (['autoload', 'autoload-dev'] as $section) {
            $autoload = $manifest[$section] ?? null;

            if (! is_array($autoload)) {
                continue;
            }

            foreach (['psr-4', 'psr-0', 'classmap', 'files'] as $kind) {
                $entries = $autoload[$kind] ?? null;

                if (! is_array($entries)) {
                    continue;
                }

                foreach ($entries as $entry) {
                    foreach (is_array($entry) ? $entry : [$entry] as $path) {
                        if (is_string($path) && $path !== '') {
                            $paths[] = $path;
                        }
                    }
                }
            }
        }

        $repositories = $manifest['repositories'] ?? null;

        if (is_array($repositories)) {
            foreach ($repositories as $repository) {
                if (! is_array($repository) || ($repository['type'] ?? null) !== 'path') {
                    continue;
                }

                $url = $repository['url'] ?? null;

                if (is_string($url) && $url !== '') {
                    $paths[] = rtrim(str_replace(['*', '?'], '', $url), '/');
                }
            }
        }

        return $paths;
    }

    /**
     * @return array<int, string>
     */
    private static function phpunitSourcePaths(string $projectRoot): array
    {
        foreach (['phpunit.xml', 'phpunit.xml.dist'] as $name) {
            $path = $projectRoot.DIRECTORY_SEPARATOR.$name;

            if (! is_file($path)) {
                continue;
            }

            $xml = self::parseXml($path);

            if (! $xml instanceof SimpleXMLElement) {
                continue;
            }

            $paths = [];

            foreach (['source', 'coverage'] as $section) {
                foreach ($xml->xpath($section.'/include/directory') ?: [] as $directory) {
                    $value = trim((string) $directory);

                    if ($value !== '') {
                        $paths[] = $value;
                    }
                }
            }

            return $paths;
        }

        return [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodeJson(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return null;
        }

        $decoded = json_decode($contents, true);

        return is_array($decoded) ? $decoded : null;
    }

    private static function parseXml(string $path): ?SimpleXMLElement
    {
        try {
            $xml = @simplexml_load_file($path);
        } catch (Throwable) {
            return null;
        }

        return $xml === false ? null : $xml;
    }

    private static function realpath(string $path): ?string
    {
        $resolved = @realpath($path);

        return $resolved === false ? null : rtrim(str_replace(DIRECTORY_SEPARATOR, '/', $resolved), '/');
    }
}
