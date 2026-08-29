<?php

namespace App\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

final class UiTranslationGuard
{
    /** @var list<string> */
    public const LOCALES = ['en', 'zh_CN'];

    /**
     * @return list<string>
     */
    public function validate(string $languageRoot, string $viewRoot, string $baselinePath): array
    {
        $errors = [];
        $baselineKeys = $this->baselineKeys($baselinePath, $errors);
        $localeKeys = [];
        $localePaths = [];

        foreach (self::LOCALES as $locale) {
            $inventory = $this->translationInventory(
                $languageRoot.'/'.$locale.'/ui.php',
                $locale,
                $errors,
            );
            $localeKeys[$locale] = $inventory['keys'];
            $localePaths[$locale] = $inventory['paths'];

            if ($baselineKeys === []) {
                continue;
            }

            foreach (array_keys(array_diff_key($baselineKeys, $localeKeys[$locale])) as $key) {
                $errors[] = "$locale is missing required key $key";
            }

            foreach (array_keys(array_diff_key($localeKeys[$locale], $baselineKeys)) as $key) {
                $errors[] = "$locale has unregistered key $key; update the canonical baseline explicitly";
            }
        }

        foreach ($this->bladeReferences($viewRoot, $errors) as $key => $locations) {
            $missingLocales = [];
            foreach (self::LOCALES as $locale) {
                if (! isset($localePaths[$locale][$key])) {
                    $missingLocales[] = $locale;
                }
            }

            if ($missingLocales !== []) {
                $errors[] = sprintf(
                    'Blade reference %s at %s is missing from %s',
                    $key,
                    implode(', ', $locations),
                    implode(' and ', $missingLocales),
                );
            }
        }

        sort($errors, SORT_STRING);

        return array_values(array_unique($errors));
    }

    public function assertValid(string $languageRoot, string $viewRoot, string $baselinePath): void
    {
        $errors = $this->validate($languageRoot, $viewRoot, $baselinePath);

        if ($errors !== []) {
            throw new RuntimeException("UI translation guard failed:\n- ".implode("\n- ", $errors));
        }
    }

    /**
     * @param  list<string>  $errors
     * @return array<string, true>
     */
    private function baselineKeys(string $path, array &$errors): array
    {
        if (! is_file($path)) {
            $errors[] = "canonical baseline is missing: $path";

            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            $errors[] = "canonical baseline could not be read: $path";

            return [];
        }

        $keys = [];
        $orderedKeys = [];
        foreach ($lines as $lineNumber => $line) {
            $key = trim($line);
            if ($key === '' || str_starts_with($key, '#')) {
                continue;
            }

            if (! preg_match('/^ui\.[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)+$/', $key)) {
                $errors[] = sprintf('invalid canonical key at %s:%d: %s', $path, $lineNumber + 1, $key);

                continue;
            }

            if (isset($keys[$key])) {
                $errors[] = sprintf('duplicate canonical key at %s:%d: %s', $path, $lineNumber + 1, $key);

                continue;
            }

            $keys[$key] = true;
            $orderedKeys[] = $key;
        }

        $sortedKeys = $orderedKeys;
        sort($sortedKeys, SORT_STRING);
        if ($orderedKeys !== $sortedKeys) {
            $errors[] = "canonical baseline must be sorted: $path";
        }

        if ($keys === []) {
            $errors[] = "canonical baseline contains no keys: $path";
        }

        return $keys;
    }

    /**
     * @param  list<string>  $errors
     * @return array{keys: array<string, true>, paths: array<string, true>}
     */
    private function translationInventory(string $path, string $locale, array &$errors): array
    {
        if (! is_file($path)) {
            $errors[] = "$locale language file is missing: $path";

            return ['keys' => [], 'paths' => []];
        }

        try {
            $translations = (static fn (string $file): mixed => require $file)($path);
        } catch (Throwable $exception) {
            $errors[] = "$locale language file could not be loaded: {$exception->getMessage()}";

            return ['keys' => [], 'paths' => []];
        }

        if (! is_array($translations)) {
            $errors[] = "$locale language file must return an array: $path";

            return ['keys' => [], 'paths' => []];
        }

        return $this->flattenTranslationInventory($translations, 'ui', $locale, $errors);
    }

    /**
     * @param  array<mixed>  $translations
     * @param  list<string>  $errors
     * @return array{keys: array<string, true>, paths: array<string, true>}
     */
    private function flattenTranslationInventory(
        array $translations,
        string $prefix,
        string $locale,
        array &$errors,
    ): array {
        $keys = [];
        $paths = [];

        foreach ($translations as $key => $value) {
            if (! is_string($key) || $key === '') {
                $errors[] = "$locale contains a non-string or empty translation key below $prefix";

                continue;
            }

            $path = $prefix.'.'.$key;
            $paths[$path] = true;
            if (is_array($value)) {
                $nested = $this->flattenTranslationInventory($value, $path, $locale, $errors);
                $keys += $nested['keys'];
                $paths += $nested['paths'];

                continue;
            }

            if (! is_string($value)) {
                $errors[] = "$locale translation $path must be a string";

                continue;
            }

            $keys[$path] = true;
        }

        return ['keys' => $keys, 'paths' => $paths];
    }

    /**
     * @param  list<string>  $errors
     * @return array<string, list<string>>
     */
    private function bladeReferences(string $root, array &$errors): array
    {
        if (! is_dir($root)) {
            $errors[] = "Blade view directory is missing: $root";

            return [];
        }

        $references = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        $pattern = '/(?:\b(?:__|trans|trans_choice|Lang::get)|@(?:lang|choice))\s*\(\s*([\'\"])(ui\.[A-Za-z0-9_.-]+)\1/';

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $source = file_get_contents($file->getPathname());
            if ($source === false) {
                $errors[] = "Blade view could not be read: {$file->getPathname()}";

                continue;
            }

            preg_match_all($pattern, $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
            foreach ($matches as $match) {
                $key = rtrim($match[2][0], '.');

                $line = substr_count(substr($source, 0, $match[2][1]), "\n") + 1;
                $relativePath = ltrim(substr($file->getPathname(), strlen(rtrim($root, DIRECTORY_SEPARATOR))), DIRECTORY_SEPARATOR);
                $references[$key][] = $relativePath.':'.$line;
            }
        }

        ksort($references, SORT_STRING);
        foreach ($references as &$locations) {
            $locations = array_values(array_unique($locations));
            sort($locations, SORT_STRING);
        }
        unset($locations);

        return $references;
    }
}
