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
     * @return array<int, string> repository-relative directory prefixes with a trailing slash, and exact file paths without one.
     */
    public static function rootsFor(string $projectRoot): array
    {
        return self::$cache[$projectRoot] ??= self::resolve($projectRoot);
    }

    /**
     * @param  array<int, string>  $files  repository-relative paths.
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
                if (self::covers($root, $file)) {
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

    private static function covers(string $root, string $file): bool
    {
        return $root === '' || str_ends_with($root, '/')
            ? str_starts_with($file, $root)
            : $file === $root;
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
        $project = self::realpath($projectRoot);

        if ($repositoryRoot === null || $project === null) {
            return [];
        }

        $roots = [];

        foreach (self::declarations($projectRoot) as [$path, $isDirectory]) {
            $resolved = self::lexicalPath($project, $path);

            if ($resolved === null) {
                continue;
            }

            if ($resolved === $project || str_starts_with($resolved, $project.'/')) {
                continue;
            }

            if ($resolved === $repositoryRoot) {
                $relative = '';
            } elseif (str_starts_with($resolved, $repositoryRoot.'/')) {
                $relative = substr($resolved, strlen($repositoryRoot) + 1);
            } else {
                continue;
            }

            $roots[$isDirectory && $relative !== '' ? $relative.'/' : $relative] = true;
        }

        $roots = array_keys($roots);
        sort($roots);

        return $roots;
    }

    /**
     * @return array<int, array{0: string, 1: bool}>
     */
    private static function declarations(string $projectRoot): array
    {
        return [
            ...self::composerDeclarations($projectRoot),
            ...self::phpunitDeclarations($projectRoot),
        ];
    }

    /**
     * @return array<int, array{0: string, 1: bool}>
     */
    private static function composerDeclarations(string $projectRoot): array
    {
        $manifest = self::decodeJson($projectRoot.DIRECTORY_SEPARATOR.'composer.json');

        if ($manifest === null) {
            return [];
        }

        $declarations = [];

        foreach (['autoload', 'autoload-dev'] as $section) {
            $autoload = $manifest[$section] ?? null;

            if (! is_array($autoload)) {
                continue;
            }

            foreach (['psr-4' => true, 'psr-0' => true, 'classmap' => null, 'files' => false] as $kind => $isDirectory) {
                $entries = $autoload[$kind] ?? null;

                if (! is_array($entries)) {
                    continue;
                }

                foreach ($entries as $entry) {
                    foreach (is_array($entry) ? $entry : [$entry] as $path) {
                        if (! is_string($path) || $path === '') {
                            continue;
                        }

                        $declarations[] = [$path, $isDirectory ?? ! self::looksLikeFile($path)];
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
                    $declarations[] = [self::beforeWildcard($url), true];
                }
            }
        }

        return $declarations;
    }

    /**
     * @return array<int, array{0: string, 1: bool}>
     */
    private static function phpunitDeclarations(string $projectRoot): array
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

            $declarations = [];

            $bootstrap = trim((string) ($xml['bootstrap'] ?? ''));

            if ($bootstrap !== '') {
                $declarations[] = [$bootstrap, false];
            }

            $sections = [
                'source/include',
                'coverage/include',
                'testsuites/testsuite',
            ];

            foreach ($sections as $section) {
                foreach (['directory' => true, 'file' => false] as $node => $isDirectory) {
                    foreach ($xml->xpath($section.'/'.$node) ?: [] as $element) {
                        $value = trim((string) $element);

                        if ($value !== '') {
                            $declarations[] = [$value, $isDirectory];
                        }
                    }
                }
            }

            return $declarations;
        }

        return [];
    }

    private static function beforeWildcard(string $url): string
    {
        $segments = [];

        foreach (explode('/', str_replace(DIRECTORY_SEPARATOR, '/', $url)) as $segment) {
            if (str_contains($segment, '*') || str_contains($segment, '?')) {
                break;
            }

            $segments[] = $segment;
        }

        return rtrim(implode('/', $segments), '/');
    }

    private static function looksLikeFile(string $path): bool
    {
        return pathinfo($path, PATHINFO_EXTENSION) !== '';
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

    private static function lexicalPath(string $base, string $relative): ?string
    {
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
        $joined = str_starts_with($relative, '/') ? $relative : $base.'/'.$relative;

        $segments = [];

        foreach (explode('/', $joined) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment !== '..') {
                $segments[] = $segment;

                continue;
            }

            if ($segments === []) {
                return null;
            }

            array_pop($segments);
        }

        return '/'.implode('/', $segments);
    }

    private static function realpath(string $path): ?string
    {
        $resolved = @realpath($path);

        return $resolved === false ? null : rtrim(str_replace(DIRECTORY_SEPARATOR, '/', $resolved), '/');
    }
}
