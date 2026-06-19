<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;

class TranslationService
{
    public function all(): Collection
    {
        $frFiles = $this->getLangFiles('fr');
        $enFiles = $this->getLangFiles('en');
        $allFiles = array_unique(array_merge(array_keys($frFiles), array_keys($enFiles)));
        sort($allFiles);

        $entries = [];

        foreach ($allFiles as $group) {
            $frKeys = $frFiles[$group] ?? [];
            $enKeys = $enFiles[$group] ?? [];
            $allKeys = array_unique(array_merge(array_keys($frKeys), array_keys($enKeys)));
            sort($allKeys);

            foreach ($allKeys as $key) {
                $frValue = $frKeys[$key] ?? null;
                $enValue = $enKeys[$key] ?? null;

                $status = $this->resolveStatus($frValue, $enValue);

                $entries[] = [
                    'group' => $group,
                    'key' => $key,
                    'fr' => $frValue,
                    'en' => $enValue,
                    'status' => $status,
                ];
            }
        }

        return collect($entries);
    }

    public function getGroups(): array
    {
        $groups = array_unique(array_merge(
            array_keys($this->getLangFiles('fr')),
            array_keys($this->getLangFiles('en')),
        ));
        sort($groups);

        return $groups;
    }

    private function getLangFiles(string $locale): array
    {
        $path = lang_path($locale);
        if (! is_dir($path)) {
            return [];
        }

        $files = [];
        foreach (File::files($path) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $group = $file->getFilenameWithoutExtension();
            $keys = require $file->getRealPath();
            if (! is_array($keys)) {
                $keys = [];
            }
            $files[$group] = $keys;
        }

        return $files;
    }

    private function resolveStatus(mixed $fr, mixed $en): string
    {
        $isFr = is_string($fr);
        $isEn = is_string($en);

        if (! $isFr && ! $isEn) {
            return 'NESTED';
        }
        if (! $isFr) {
            return 'MISSING_FR';
        }
        if (! $isEn) {
            return 'MISSING_EN';
        }
        if (trim($fr) === '') {
            return 'EMPTY_FR';
        }
        if (trim($en) === '') {
            return 'EMPTY_EN';
        }

        return 'OK';
    }
}
