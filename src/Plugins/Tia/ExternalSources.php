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
     * @var list<string>
     */
    private const array CONFIGURATION_FLAGS = ['-c', '--configuration'];

    /**
     * @var list<string>
     */
    private const array CONFIGURATION_NAMES = ['phpunit.xml', 'phpunit.xml.dist'];

    /**
     * @var array<string, array<int, string>>
     */
    private static array $cache = [];

    /**
     * @param  array<int, string>  $arguments
     * @return array<int, string> repository-relative directory prefixes with a trailing slash, and exact file paths without one.
     */
    public static function rootsFor(string $projectRoot, array $arguments = []): array
    {
        $configurations = self::configurations($projectRoot, $arguments);

        $key = $projectRoot."\x00".implode("\x00", $configurations);

        return self::$cache[$key] ??= self::resolve($projectRoot, $configurations);
    }

    /**
     * @param  array<int, string>  $files  repository-relative paths.
     * @param  array<int, string>  $arguments
     * @return array<int, string>
     */
    public static function matching(string $projectRoot, array $files, array $arguments = []): array
    {
        $roots = self::rootsFor($projectRoot, $arguments);

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
     * @param  array<int, string>  $configurations
     * @return array<int, string>
     */
    private static function resolve(string $projectRoot, array $configurations): array
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

        foreach (self::declarations($projectRoot, $configurations) as [$resolved, $isDirectory]) {
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
     * @param  array<int, string>  $arguments
     * @return array<int, string> absolute paths of the configuration files that declare what this project loads.
     */
    private static function configurations(string $projectRoot, array $arguments): array
    {
        $files = [];

        $fromArguments = self::configurationArgument($arguments);

        if ($fromArguments !== null) {
            $resolved = self::realpath($fromArguments) ?? self::absolutePath($projectRoot, $fromArguments);

            if ($resolved !== null && is_file($resolved)) {
                $files[$resolved] = true;
            }
        }

        foreach (self::CONFIGURATION_NAMES as $name) {
            $resolved = self::realpath($projectRoot.DIRECTORY_SEPARATOR.$name);

            if ($resolved !== null) {
                $files[$resolved] = true;

                break;
            }
        }

        return array_keys($files);
    }

    /**
     * @param  array<int, string>  $arguments
     */
    private static function configurationArgument(array $arguments): ?string
    {
        $count = count($arguments);

        for ($index = 0; $index < $count; $index++) {
            $argument = $arguments[$index];

            foreach (self::CONFIGURATION_FLAGS as $flag) {
                if (str_starts_with($argument, $flag.'=')) {
                    return substr($argument, strlen($flag) + 1);
                }

                if ($argument === $flag && isset($arguments[$index + 1])) {
                    return $arguments[$index + 1];
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $configurations
     * @return array<int, array{0: string, 1: bool}>
     */
    private static function declarations(string $projectRoot, array $configurations): array
    {
        $declarations = self::composerDeclarations($projectRoot);

        foreach ($configurations as $configuration) {
            $declarations = [...$declarations, ...self::phpunitDeclarations($configuration)];
        }

        return $declarations;
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

                        self::declare($declarations, $projectRoot, $path, $isDirectory ?? ! self::looksLikeFile($path));
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
                    self::declare($declarations, $projectRoot, self::beforeWildcard($url), true);
                }
            }
        }

        return $declarations;
    }

    /**
     * @return array<int, array{0: string, 1: bool}>
     */
    private static function phpunitDeclarations(string $configuration): array
    {
        $xml = self::parseXml($configuration);

        if (! $xml instanceof SimpleXMLElement) {
            return [];
        }

        $base = dirname($configuration);
        $declarations = [[str_replace(DIRECTORY_SEPARATOR, '/', $configuration), false]];

        $bootstrap = trim((string) ($xml['bootstrap'] ?? ''));

        if ($bootstrap !== '') {
            self::declare($declarations, $base, $bootstrap, false);
        }

        $sections = ['source/include', 'coverage/include', 'testsuites/testsuite'];

        foreach ($sections as $section) {
            foreach (['directory' => true, 'file' => false] as $node => $isDirectory) {
                foreach ($xml->xpath($section.'/'.$node) ?: [] as $element) {
                    $value = trim((string) $element);

                    if ($value !== '') {
                        self::declare($declarations, $base, $value, $isDirectory);
                    }
                }
            }
        }

        return $declarations;
    }

    /**
     * @param  array<int, array{0: string, 1: bool}>  $declarations
     */
    private static function declare(array &$declarations, string $base, string $path, bool $isDirectory): void
    {
        $resolved = self::absolutePath($base, $path);

        if ($resolved !== null) {
            $declarations[] = [$resolved, $isDirectory];
        }
    }

    private static function absolutePath(string $base, string $path): ?string
    {
        return self::realpath(self::isAbsolute($path) ? $path : $base.DIRECTORY_SEPARATOR.$path)
            ?? self::lexicalPath(self::realpath($base) ?? $base, $path);
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[a-z]:[\\\\\/]/i', $path) === 1;
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
        if (! is_file($path)) {
            return null;
        }

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
        $joined = self::isAbsolute($relative) ? $relative : $base.'/'.$relative;

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
