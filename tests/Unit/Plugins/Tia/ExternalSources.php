<?php

declare(strict_types=1);

use Pest\Plugins\Tia\ExternalSources;
use Symfony\Component\Process\Process;
use Tests\Fixtures\Tia\GitRepo;

/**
 * @return array<int, string>
 */
function tiaExternalRoots(?string $created = null): array
{
    static $roots = [];

    if ($created !== null) {
        $roots[] = $created;

        return $roots;
    }

    $all = $roots;
    $roots = [];

    return $all;
}

/**
 * @param  array<string, mixed>  $manifest
 * @return array{root: string, project: string}
 */
function tiaExternalRepository(array $manifest = [], ?string $phpunit = null): array
{
    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pest-tia-external-'.bin2hex(random_bytes(8));

    mkdir($root.'/backend/app', 0755, true);
    mkdir($root.'/packages/shared/src', 0755, true);

    file_put_contents($root.'/backend/app/Service.php', "<?php\n\$service = 1;\n");
    file_put_contents($root.'/packages/shared/src/Shared.php', "<?php\n\$shared = 1;\n");
    file_put_contents($root.'/packages/shared/bootstrap.php', "<?php\n\$booted = 1;\n");
    file_put_contents($root.'/backend/composer.json', (string) json_encode($manifest, JSON_PRETTY_PRINT));

    if ($phpunit !== null) {
        file_put_contents($root.'/backend/phpunit.xml', $phpunit);
    }

    tiaExternalRoots($root);

    new GitRepo($root)->init('master');

    return ['root' => $root, 'project' => $root.'/backend'];
}

beforeEach(function (): void {
    ExternalSources::flush();
});

afterEach(function (): void {
    foreach (tiaExternalRoots() as $root) {
        new Process(['rm', '-rf', $root])->run();
    }

    ExternalSources::flush();
});

it('finds a psr-4 root that escapes the project', function (): void {
    $repository = tiaExternalRepository([
        'autoload' => ['psr-4' => ['App\\' => 'app/', 'Shared\\' => '../packages/shared/src']],
    ]);

    expect(ExternalSources::rootsFor($repository['project']))->toBe(['packages/shared/src/']);
})->skipOnWindows();

it('finds a path repository', function (): void {
    $repository = tiaExternalRepository([
        'repositories' => [['type' => 'path', 'url' => '../packages/shared']],
    ]);

    expect(ExternalSources::rootsFor($repository['project']))->toBe(['packages/shared/']);
})->skipOnWindows();

it('finds a phpunit source directory outside the project', function (): void {
    $repository = tiaExternalRepository([], <<<'XML_WRAP'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit>
  <source>
    <include>
      <directory suffix=".php">app</directory>
      <directory suffix=".php">../packages/shared/src</directory>
    </include>
  </source>
</phpunit>
XML_WRAP);

    expect(ExternalSources::rootsFor($repository['project']))->toBe(['packages/shared/src/']);
})->skipOnWindows();

it('finds nothing when the project only loads its own code', function (): void {
    $repository = tiaExternalRepository([
        'autoload' => ['psr-4' => ['App\\' => 'app/'], 'files' => ['app/Service.php']],
    ]);

    expect(ExternalSources::rootsFor($repository['project']))->toBeEmpty();
})->skipOnWindows();

it('finds nothing for a project at the repository root', function (): void {
    $repository = tiaExternalRepository([
        'autoload' => ['psr-4' => ['Shared\\' => '../packages/shared/src']],
    ]);

    expect(ExternalSources::rootsFor($repository['root']))->toBeEmpty();
})->skipOnWindows();

it('ignores a root that sits outside the repository altogether', function (): void {
    $repository = tiaExternalRepository([
        'autoload' => ['psr-4' => ['Tmp\\' => '../../']],
    ]);

    expect(ExternalSources::rootsFor($repository['project']))->toBeEmpty();
})->skipOnWindows();

it('matches only the changes that fall under an external root', function (): void {
    $repository = tiaExternalRepository([
        'autoload' => ['psr-4' => ['Shared\\' => '../packages/shared/src']],
    ]);

    $matched = ExternalSources::matching($repository['project'], [
        'packages/shared/src/Shared.php',
        'packages/other/src/Other.php',
        'frontend/widget.php',
    ]);

    expect($matched)->toBe(['packages/shared/src/Shared.php']);
})->skipOnWindows();

it('finds an autoload file that escapes the project and matches it exactly', function (): void {
    $repository = tiaExternalRepository([
        'autoload' => ['files' => ['../packages/shared/bootstrap.php']],
    ]);

    expect(ExternalSources::rootsFor($repository['project']))->toBe(['packages/shared/bootstrap.php'])
        ->and(ExternalSources::matching($repository['project'], ['packages/shared/bootstrap.php']))
        ->toBe(['packages/shared/bootstrap.php']);
})->skipOnWindows();

it('finds a phpunit bootstrap that escapes the project', function (): void {
    $repository = tiaExternalRepository([], <<<'XML_WRAP'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="../packages/shared/bootstrap.php">
  <source>
    <include>
      <directory suffix=".php">app</directory>
    </include>
  </source>
</phpunit>
XML_WRAP);

    expect(ExternalSources::rootsFor($repository['project']))->toBe(['packages/shared/bootstrap.php']);
})->skipOnWindows();

it('keeps a declared root that no longer exists on disk', function (): void {
    $repository = tiaExternalRepository([
        'autoload' => ['psr-4' => ['Shared\\' => '../packages/shared/src']],
    ]);

    new Process(['rm', '-rf', $repository['root'].'/packages/shared'])->mustRun();

    ExternalSources::flush();

    expect(ExternalSources::rootsFor($repository['project']))->toBe(['packages/shared/src/'])
        ->and(ExternalSources::matching($repository['project'], ['packages/shared/src/Shared.php']))
        ->toBe(['packages/shared/src/Shared.php']);
})->skipOnWindows();

it('treats a classmap entry as a file only when it names one', function (): void {
    $repository = tiaExternalRepository([
        'autoload' => ['classmap' => ['../packages/shared/src', '../packages/shared/bootstrap.php']],
    ]);

    expect(ExternalSources::rootsFor($repository['project']))
        ->toBe(['packages/shared/bootstrap.php', 'packages/shared/src/']);
})->skipOnWindows();

it('finds a testsuite directory outside the project', function (): void {
    $repository = tiaExternalRepository([], <<<'XML_WRAP'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit>
  <testsuites>
    <testsuite name="default">
      <directory suffix="Test.php">./tests</directory>
      <directory suffix="Test.php">../packages/shared/tests</directory>
      <file>../packages/shared/tests/OneTest.php</file>
    </testsuite>
  </testsuites>
</phpunit>
XML_WRAP);

    expect(ExternalSources::rootsFor($repository['project']))->toBe([
        'packages/shared/tests/',
        'packages/shared/tests/OneTest.php',
    ]);
})->skipOnWindows();

it('keeps the directory before a wildcard in a path repository', function (): void {
    $repository = tiaExternalRepository([
        'repositories' => [['type' => 'path', 'url' => '../packages/*/src']],
    ]);

    expect(ExternalSources::rootsFor($repository['project']))->toBe(['packages/'])
        ->and(ExternalSources::matching($repository['project'], ['packages/foo/src/Service.php']))
        ->toBe(['packages/foo/src/Service.php']);
})->skipOnWindows();

it('keeps the directory before a single character wildcard', function (): void {
    $repository = tiaExternalRepository([
        'repositories' => [['type' => 'path', 'url' => '../packages/lib-?/src']],
    ]);

    expect(ExternalSources::rootsFor($repository['project']))->toBe(['packages/']);
})->skipOnWindows();

it('matches a deleted package that a wildcard declared', function (): void {
    $repository = tiaExternalRepository([
        'repositories' => [['type' => 'path', 'url' => '../packages/*']],
    ]);

    new Process(['rm', '-rf', $repository['root'].'/packages/shared'])->mustRun();

    ExternalSources::flush();

    expect(ExternalSources::matching($repository['project'], ['packages/shared/src/Shared.php']))
        ->toBe(['packages/shared/src/Shared.php']);
})->skipOnWindows();

it('covers the whole repository when a declaration reaches its root', function (): void {
    $repository = tiaExternalRepository([
        'repositories' => [['type' => 'path', 'url' => '../*']],
    ]);

    expect(ExternalSources::rootsFor($repository['project']))->toBe([''])
        ->and(ExternalSources::matching($repository['project'], ['packages/shared/src/Shared.php']))
        ->toBe(['packages/shared/src/Shared.php']);
})->skipOnWindows();

it('reads the configuration that the command line selects', function (): void {
    $repository = tiaExternalRepository();

    file_put_contents($repository['project'].'/phpunit.ci.xml', <<<'XML_WRAP'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="../packages/shared/bootstrap.php">
  <testsuites>
    <testsuite name="default">
      <directory suffix="Test.php">../packages/shared/tests</directory>
    </testsuite>
  </testsuites>
</phpunit>
XML_WRAP);

    expect(ExternalSources::rootsFor($repository['project'], ['--tia', '-c', 'phpunit.ci.xml']))->toBe([
        'packages/shared/bootstrap.php',
        'packages/shared/tests/',
    ])->and(ExternalSources::rootsFor($repository['project']))->toBeEmpty();
})->skipOnWindows();

it('accepts the configuration argument in its joined form', function (): void {
    $repository = tiaExternalRepository();

    file_put_contents($repository['project'].'/phpunit.ci.xml', <<<'XML_WRAP'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="../packages/shared/bootstrap.php"/>
XML_WRAP);

    expect(ExternalSources::rootsFor($repository['project'], ['--configuration=phpunit.ci.xml']))
        ->toBe(['packages/shared/bootstrap.php']);
})->skipOnWindows();

it('resolves a configuration path against its own directory', function (): void {
    $repository = tiaExternalRepository();

    mkdir($repository['root'].'/config', 0755, true);
    file_put_contents($repository['root'].'/config/phpunit.xml', <<<'XML_WRAP'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="../packages/shared/bootstrap.php"/>
XML_WRAP);

    expect(ExternalSources::rootsFor($repository['project'], ['-c', '../config/phpunit.xml']))->toBe([
        'config/phpunit.xml',
        'packages/shared/bootstrap.php',
    ]);
})->skipOnWindows();

it('follows a symlink that leaves the project', function (): void {
    $repository = tiaExternalRepository([
        'autoload' => ['psr-4' => ['Shared\\' => 'shared']],
    ]);

    symlink($repository['root'].'/packages/shared/src', $repository['project'].'/shared');

    ExternalSources::flush();

    expect(ExternalSources::rootsFor($repository['project']))->toBe(['packages/shared/src/'])
        ->and(ExternalSources::matching($repository['project'], ['packages/shared/src/Shared.php']))
        ->toBe(['packages/shared/src/Shared.php']);
})->skipOnWindows();

it('keeps a real directory inside the project out of the roots', function (): void {
    $repository = tiaExternalRepository([
        'autoload' => ['psr-4' => ['App\\' => 'app']],
    ]);

    expect(ExternalSources::rootsFor($repository['project']))->toBeEmpty();
})->skipOnWindows();

it('finds a bootstrap file that the command line names', function (): void {
    $repository = tiaExternalRepository();

    expect(ExternalSources::rootsFor($repository['project'], ['--bootstrap', '../packages/shared/bootstrap.php']))
        ->toBe(['packages/shared/bootstrap.php']);
})->skipOnWindows();

it('finds every directory that an include path names', function (): void {
    $repository = tiaExternalRepository();

    $list = '../packages/shared/src'.PATH_SEPARATOR.'app'.PATH_SEPARATOR.'../packages/shared/tests';

    expect(ExternalSources::rootsFor($repository['project'], ['--include-path', $list]))
        ->toBe(['packages/shared/src/', 'packages/shared/tests/']);
})->skipOnWindows();

it('resolves a command line path against the directory the command ran in', function (): void {
    $repository = tiaExternalRepository();

    file_put_contents($repository['root'].'/phpunit.ci.xml', <<<'XML_WRAP'
<?xml version="1.0" encoding="UTF-8"?>
<phpunit bootstrap="packages/shared/bootstrap.php"/>
XML_WRAP);

    $previous = getcwd();
    chdir($repository['root']);

    try {
        $roots = ExternalSources::rootsFor($repository['project'], ['-c', 'phpunit.ci.xml']);
    } finally {
        chdir((string) $previous);
    }

    expect($roots)->toBe(['packages/shared/bootstrap.php', 'phpunit.ci.xml']);
})->skipOnWindows();
