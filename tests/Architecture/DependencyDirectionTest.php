<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class DependencyDirectionTest extends TestCase
{
    private const string PROJECT_ROOT = __DIR__ . '/../..';

    public function testApiModuleDoesNotReferenceEntity(): void
    {
        self::assertModuleReferencesOnlyAllowedNamespaces(
            'src/Api',
            static fn (string $namespace): bool => !self::isWithin($namespace, 'App\Entity'),
        );
    }

    public function testSourceModuleDependsOnNothingFromAppExceptItself(): void
    {
        self::assertModuleReferencesOnlyAllowedNamespaces(
            'src/Source',
            static fn (string $namespace): bool => self::isWithin($namespace, 'App\Source'),
        );
    }

    /**
     * @param callable(string): bool $isAllowed
     */
    private static function assertModuleReferencesOnlyAllowedNamespaces(string $relativeModuleDir, callable $isAllowed): void
    {
        foreach (self::phpFiles(self::PROJECT_ROOT . '/' . $relativeModuleDir) as $file) {
            foreach (self::referencedAppNamespaces($file) as $namespace) {
                self::assertTrue(
                    $isAllowed($namespace),
                    sprintf(
                        "%s references '%s', which violates the dependency direction rule for %s (docs/architecture.md §4).",
                        self::relativePath($file),
                        $namespace,
                        $relativeModuleDir,
                    ),
                );
            }
        }
    }

    private static function isWithin(string $namespace, string $prefix): bool
    {
        return $namespace === $prefix || str_starts_with($namespace, $prefix . '\\');
    }

    /**
     * @return list<SplFileInfo>
     */
    private static function phpFiles(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private static function referencedAppNamespaces(SplFileInfo $file): array
    {
        $contents = file_get_contents($file->getPathname());

        if ($contents === false) {
            throw new RuntimeException(sprintf('Could not read %s.', $file->getPathname()));
        }

        $namespaces = [];
        $insideNamespaceDeclaration = false;

        foreach (token_get_all($contents) as $token) {
            if (!\is_array($token)) {
                if ($insideNamespaceDeclaration && $token === ';') {
                    $insideNamespaceDeclaration = false;
                }

                continue;
            }

            [$id, $text] = $token;

            if ($id === T_NAMESPACE) {
                $insideNamespaceDeclaration = true;

                continue;
            }

            if ($insideNamespaceDeclaration || ($id !== T_NAME_QUALIFIED && $id !== T_NAME_FULLY_QUALIFIED)) {
                continue;
            }

            $normalized = ltrim($text, '\\');

            if (str_starts_with($normalized, 'App\\')) {
                $namespaces[] = $normalized;
            }
        }

        return $namespaces;
    }

    private static function relativePath(SplFileInfo $file): string
    {
        $projectRoot = realpath(self::PROJECT_ROOT);
        $realPath = $file->getRealPath();

        if ($projectRoot === false || $realPath === false) {
            throw new RuntimeException(sprintf('Could not resolve a real path for %s.', $file->getPathname()));
        }

        return ltrim(substr($realPath, \strlen($projectRoot)), '/');
    }
}
