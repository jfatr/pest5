<?php

declare(strict_types=1);

use Tests\Fixtures\Tia\Project;

afterEach(function (): void {
    Project::destroyAll();
});

/**
 * @return array{0: Project, 1: string}
 */
function tiaMonorepo(): array
{
    $project = Project::make('master');
    $nested = $project->nested();

    $project->write('nested/resources/views/greeting.blade.php', "<p>Hello</p>\n");
    $project->write('frontend/widget.php', "<?php\n\n\$widget = 1;\n");
    $project->git()->commit('add the nested project and a sibling');

    $project->seedFor($nested, 'master');

    $project->mutateGraph(function (array $graph): array {
        $id = count($graph['files']);
        $graph['files'][$id] = 'resources/views/greeting.blade.php';
        $graph['edges']['tests/Unit/GreeterTest.php'][] = $id;

        return $graph;
    });

    return [$project, $nested];
}

test('a project in a subdirectory of the repository replays its baseline', function (array $arguments): void {
    [$project, $nested] = tiaMonorepo();

    $result = $project->pestIn($nested, '--tia', ...$arguments);

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->replayed())->toBe(Project::TOTAL_TESTS, $result->describe())
        ->and($result->affected())->toBe(0, $result->describe());
})->with(Project::SEQUENTIAL_AND_PARALLEL)->skipOnWindows();

test('a working tree change inside the subdirectory selects the tests that depend on it', function (array $arguments): void {
    [$project, $nested] = tiaMonorepo();

    $project->write('nested/resources/views/greeting.blade.php', "<p>Hi</p>\n");
    $project->snapshot();

    $result = $project->pestIn($nested, '--tia', ...$arguments);

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->affected())->toBe(2, $result->describe())
        ->and($result->replayed())->toBe(4, $result->describe());
})->with(Project::SEQUENTIAL_AND_PARALLEL)->skipOnWindows();

test('a committed change inside the subdirectory selects the tests that depend on it', function (): void {
    [$project, $nested] = tiaMonorepo();

    $project->write('nested/resources/views/greeting.blade.php', "<p>Hi</p>\n");
    $project->git()->commit('reword the greeting');
    $project->snapshot();

    $result = $project->pestIn($nested, '--tia');

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->affected())->toBe(2, $result->describe())
        ->and($result->replayed())->toBe(4, $result->describe());
})->skipOnWindows();

test('a change in a sibling package leaves the subdirectory project untouched', function (): void {
    [$project, $nested] = tiaMonorepo();

    $project->write('frontend/widget.php', "<?php\n\n\$widget = 2;\n");
    $project->write('frontend/views/greeting.blade.php', "<p>Hi</p>\n");
    $project->git()->commit('rework the sibling');
    $project->snapshot();

    $result = $project->pestIn($nested, '--tia');

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->replayed())->toBe(Project::TOTAL_TESTS, $result->describe())
        ->and($result->affected())->toBe(0, $result->describe());
})->skipOnWindows();

test('a committed change inside the subdirectory that is undone again selects nothing', function (): void {
    [$project, $nested] = tiaMonorepo();

    $project->write('nested/resources/views/greeting.blade.php', "<p>Hi</p>\n");
    $project->git()->commit('reword the greeting');

    $project->write('nested/resources/views/greeting.blade.php', "<p>Hello</p>\n");
    $project->git()->commit('put the greeting back');
    $project->snapshot();

    $result = $project->pestIn($nested, '--tia');

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->replayed())->toBe(Project::TOTAL_TESTS, $result->describe())
        ->and($result->affected())->toBe(0, $result->describe());
})->skipOnWindows();

test('two projects in the same repository keep their state apart', function (): void {
    $project = Project::make('master');

    $api = $project->nested('services/api');
    $admin = $project->nested('services/admin');

    $project->git()->commit('add both projects');

    expect($project->stateDirFor($api))->not->toBe($project->stateDirFor($admin));
})->skipOnWindows();

test('a nested project fetches the baseline published for it', function (): void {
    [$project, $nested] = tiaMonorepo();

    $environment = $project->gh('ok', $project->detachGraph());

    $result = $project->pestWithEnvironment($nested, $environment, '--tia', '--baselined');

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->output)->toContain('Downloading TIA baseline')
        ->and($result->replayed())->toBe(Project::TOTAL_TESTS, $result->describe());
})->skipOnWindows();

test('a nested project refuses a baseline published by a sibling project', function (): void {
    [$project, $nested] = tiaMonorepo();

    /** @var array<string, mixed> $graph */
    $graph = json_decode($project->detachGraph(), true);
    $graph['fingerprint']['structural']['project_prefix'] = 'services/admin/';

    $environment = $project->gh('ok', (string) json_encode($graph, JSON_UNESCAPED_SLASHES));

    $result = $project->pestWithEnvironment($nested, $environment, '--tia', '--baselined');

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->tally())->toContain(Project::TOTAL_TESTS.' passed')
        ->and($result->replayed())->toBe(0, $result->describe());
})->skipOnWindows();

test('it says when changes outside the project were ignored', function (): void {
    [$project, $nested] = tiaMonorepo();

    $project->write('frontend/widget.php', "<?php\n\n\$widget = 2;\n");
    $project->git()->commit('rework the sibling');
    $project->snapshot();

    $result = $project->pestIn($nested, '--tia');

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->output)->toContain('1 changed file outside this project was ignored');
})->skipOnWindows();

test('a change in a sibling package the project autoloads runs the full suite', function (array $arguments): void {
    [$project, $nested] = tiaMonorepo();

    $manifest = json_decode((string) file_get_contents($nested.'/composer.json'), true);
    $manifest['autoload']['psr-4']['Shared\\'] = '../packages/shared/src';
    $project->write('nested/composer.json', (string) json_encode($manifest, JSON_PRETTY_PRINT));

    $project->write('packages/shared/src/Shared.php', "<?php\n\n\$shared = 1;\n");
    $project->git()->commit('let the project load a sibling package');
    $project->seedFor($nested, 'master');

    $project->write('packages/shared/src/Shared.php', "<?php\n\n\$shared = 2;\n");
    $project->git()->commit('rework the sibling package');
    $project->snapshot();

    $result = $project->pestIn($nested, '--tia', ...$arguments);

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->output)->toContain('this project loads from outside its root')
        ->and($result->replayed())->toBe(0, $result->describe())
        ->and($result->tally())->toContain(Project::TOTAL_TESTS.' passed');
})->with(Project::SEQUENTIAL_AND_PARALLEL)->skipOnWindows();

test('a change in a sibling package the project does not load still replays', function (): void {
    [$project, $nested] = tiaMonorepo();

    $project->write('packages/shared/src/Shared.php', "<?php\n\n\$shared = 2;\n");
    $project->git()->commit('rework an unrelated sibling package');
    $project->snapshot();

    $result = $project->pestIn($nested, '--tia');

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->output)->not->toContain('this project loads from outside its root')
        ->and($result->replayed())->toBe(Project::TOTAL_TESTS, $result->describe());
})->skipOnWindows();

test('a change in an autoload file outside the project runs the full suite', function (): void {
    [$project, $nested] = tiaMonorepo();

    $manifest = json_decode((string) file_get_contents($nested.'/composer.json'), true);
    $manifest['autoload']['files'][] = '../packages/shared/bootstrap.php';
    $project->write('nested/composer.json', (string) json_encode($manifest, JSON_PRETTY_PRINT));

    $project->write('packages/shared/bootstrap.php', "<?php\n\n\$booted = 1;\n");
    $project->git()->commit('let the project load a sibling bootstrap file');
    $project->seedFor($nested, 'master');

    $project->write('packages/shared/bootstrap.php', "<?php\n\n\$booted = 2;\n");
    $project->git()->commit('rework the sibling bootstrap file');
    $project->snapshot();

    $result = $project->pestIn($nested, '--tia');

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->output)->toContain('this project loads from outside its root')
        ->and($result->replayed())->toBe(0, $result->describe());
})->skipOnWindows();

test('a change in the phpunit bootstrap outside the project runs the full suite', function (): void {
    [$project, $nested] = tiaMonorepo();

    $project->write('packages/shared/bootstrap.php', "<?php\n\nrequire __DIR__.'/../../nested/vendor/autoload.php';\n");
    $project->write('nested/phpunit.xml', str_replace(
        'bootstrap="vendor/autoload.php"',
        'bootstrap="../packages/shared/bootstrap.php"',
        (string) file_get_contents($nested.'/phpunit.xml'),
    ));
    $project->git()->commit('point the project at a sibling bootstrap file');
    $project->seedFor($nested, 'master');

    $project->write('packages/shared/bootstrap.php', "<?php\n\nrequire __DIR__.'/../../nested/vendor/autoload.php';\n\n\$booted = 2;\n");
    $project->git()->commit('rework the sibling bootstrap file');
    $project->snapshot();

    $result = $project->pestIn($nested, '--tia');

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->output)->toContain('this project loads from outside its root')
        ->and($result->replayed())->toBe(0, $result->describe());
})->skipOnWindows();

test('deleting a declared external package runs the full suite', function (): void {
    [$project, $nested] = tiaMonorepo();

    $manifest = json_decode((string) file_get_contents($nested.'/composer.json'), true);
    $manifest['autoload']['psr-4']['Shared\\'] = '../packages/shared/src';
    $project->write('nested/composer.json', (string) json_encode($manifest, JSON_PRETTY_PRINT));

    $project->write('packages/shared/src/Shared.php', "<?php\n\n\$shared = 1;\n");
    $project->git()->commit('let the project load a sibling package');
    $project->seedFor($nested, 'master');

    $project->git()->run(['rm', '-r', '--quiet', 'packages/shared']);
    $project->git()->commit('delete the sibling package');
    $project->snapshot();

    $result = $project->pestIn($nested, '--tia');

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->output)->toContain('this project loads from outside its root')
        ->and($result->replayed())->toBe(0, $result->describe());
})->skipOnWindows();

test('a change in an external test suite runs the full suite', function (): void {
    [$project, $nested] = tiaMonorepo();

    $project->write('shared-tests/support.php', "<?php\n\n\$support = 1;\n");

    $project->write('nested/phpunit.xml', str_replace(
        '<directory suffix="Test.php">./tests</directory>',
        '<directory suffix="Test.php">./tests</directory>'."\n".'      <directory suffix="Test.php">../shared-tests</directory>',
        (string) file_get_contents($nested.'/phpunit.xml'),
    ));
    $project->git()->commit('add an external test suite');
    $project->seedFor($nested, 'master');

    $project->write('shared-tests/support.php', "<?php\n\n\$support = 2;\n");
    $project->git()->commit('rework the external test suite');
    $project->snapshot();

    $result = $project->pestIn($nested, '--tia');

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->output)->toContain('this project loads from outside its root')
        ->and($result->replayed())->toBe(0, $result->describe());
})->skipOnWindows();

test('a change under a wildcard path repository runs the full suite', function (): void {
    [$project, $nested] = tiaMonorepo();

    $manifest = json_decode((string) file_get_contents($nested.'/composer.json'), true);
    $manifest['repositories'][] = ['type' => 'path', 'url' => '../packages/*/src'];
    $project->write('nested/composer.json', (string) json_encode($manifest, JSON_PRETTY_PRINT));

    $project->write('packages/shared/src/Shared.php', "<?php\n\n\$shared = 1;\n");
    $project->git()->commit('declare a wildcard path repository');
    $project->seedFor($nested, 'master');

    $project->write('packages/shared/src/Shared.php', "<?php\n\n\$shared = 2;\n");
    $project->git()->commit('rework the wildcard package');
    $project->snapshot();

    $result = $project->pestIn($nested, '--tia');

    expect($result->exitCode)->toBe(0, $result->describe())
        ->and($result->output)->toContain('this project loads from outside its root')
        ->and($result->replayed())->toBe(0, $result->describe());
})->skipOnWindows();
