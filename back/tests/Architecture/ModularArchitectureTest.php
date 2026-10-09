<?php

namespace Tests\Architecture;

use App\Architecture\Modules\ModuleDependencyMap;
use App\Architecture\Modules\ModuleName;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Finder\SplFileInfo;
use Tests\TestCase;

class ModularArchitectureTest extends TestCase
{
    public function test_all_declared_modules_have_a_physical_boundary(): void
    {
        $declaredModules = array_map(
            static fn (ModuleName $module): string => $module->value,
            ModuleName::cases(),
        );
        $moduleDirectories = array_map(
            static fn (string $directory): string => basename($directory),
            File::directories(app_path('Modules')),
        );

        sort($declaredModules);
        sort($moduleDirectories);

        $this->assertSame($declaredModules, $moduleDirectories);

        foreach (ModuleName::cases() as $module) {
            $boundaryClass = "App\\Modules\\{$module->value}\\Module";

            $this->assertTrue(class_exists($boundaryClass), "Missing boundary for {$module->value}.");
            $this->assertSame($module, $boundaryClass::NAME);
        }
    }

    public function test_module_dependency_graph_is_acyclic(): void
    {
        $visited = [];

        foreach (ModuleName::cases() as $module) {
            $this->visit($module, [], $visited);
        }

        $this->assertCount(count(ModuleName::cases()), $visited);
    }

    public function test_modules_only_import_declared_dependencies_through_public_contracts(): void
    {
        $violations = [];

        foreach ($this->moduleFiles() as $file) {
            $source = $this->sourceModule($file);

            foreach ($this->moduleImports($file) as $import) {
                $target = ModuleName::tryFrom($import['module']);

                if ($target === null) {
                    $violations[] = "{$file->getRelativePathname()} imports unknown module {$import['module']}";

                    continue;
                }

                if ($source === $target) {
                    continue;
                }

                if (! ModuleDependencyMap::allows($source, $target)) {
                    $violations[] = "{$source->value} may not depend on {$target->value} in {$file->getRelativePathname()}";

                    continue;
                }

                if (! Str::startsWith($import['path'], ['Application\\Contracts\\', 'Domain\\Events\\'])) {
                    $violations[] = "{$file->getRelativePathname()} bypasses the public API of {$target->value}";
                }
            }
        }

        $this->assertSame([], $violations, implode(PHP_EOL, $violations));
    }

    public function test_internal_layers_only_depend_inward(): void
    {
        $allowedLayers = [
            'Domain' => ['Domain'],
            'Application' => ['Application', 'Domain'],
            'Infrastructure' => ['Infrastructure', 'Application', 'Domain'],
            'Presentation' => ['Presentation', 'Application', 'Domain'],
        ];
        $violations = [];

        foreach ($this->moduleFiles() as $file) {
            $relativePath = str_replace('\\', '/', $file->getRelativePathname());
            $sourceLayer = explode('/', $relativePath)[1] ?? null;

            if ($sourceLayer === null || ! isset($allowedLayers[$sourceLayer])) {
                continue;
            }

            $sourceModule = $this->sourceModule($file);

            foreach ($this->moduleImports($file) as $import) {
                if ($import['module'] !== $sourceModule->value) {
                    continue;
                }

                $targetLayer = Str::before($import['path'], '\\');

                if (! in_array($targetLayer, $allowedLayers[$sourceLayer], true)) {
                    $violations[] = "{$file->getRelativePathname()}: {$sourceLayer} may not depend on {$targetLayer}";
                }
            }
        }

        $this->assertSame([], $violations, implode(PHP_EOL, $violations));
    }

    /**
     * @param  array<string, true>  $visiting
     * @param  array<string, true>  $visited
     */
    private function visit(ModuleName $module, array $visiting, array &$visited): void
    {
        if (isset($visited[$module->value])) {
            return;
        }

        $this->assertArrayNotHasKey(
            $module->value,
            $visiting,
            'Circular module dependency detected: '.implode(' -> ', [...array_keys($visiting), $module->value]),
        );

        $visiting[$module->value] = true;

        foreach (ModuleDependencyMap::dependenciesOf($module) as $dependency) {
            $this->visit($dependency, $visiting, $visited);
        }

        $visited[$module->value] = true;
    }

    /** @return list<SplFileInfo> */
    private function moduleFiles(): array
    {
        return array_values(File::allFiles(app_path('Modules')));
    }

    private function sourceModule(SplFileInfo $file): ModuleName
    {
        $relativePath = str_replace('\\', '/', $file->getRelativePathname());
        $moduleName = Str::before($relativePath, '/');

        return ModuleName::from($moduleName);
    }

    /** @return list<array{module: string, path: string}> */
    private function moduleImports(SplFileInfo $file): array
    {
        $contents = $file->getContents();
        preg_match_all(
            '/^use\s+App\\\\Modules\\\\(?<module>[A-Za-z]+)\\\\(?<path>[^;]+);/m',
            $contents,
            $matches,
            PREG_SET_ORDER,
        );

        return array_map(
            static fn (array $match): array => [
                'module' => $match['module'],
                'path' => $match['path'],
            ],
            $matches,
        );
    }
}
