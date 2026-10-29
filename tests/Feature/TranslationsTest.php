<?php

/*
| Guards against a missing or stale translation: all three languages carry the same texts, and every text the code asks for exists.
*/

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

const LANGUAGES = ['en', 'es', 'de'];

/** @return array<string, string> a nested JSON file flattened to dotted keys */
function flatMessages(string $language): array
{
    $flat = [];
    $walk = function (array $node, string $prefix) use (&$walk, &$flat) {
        foreach ($node as $key => $value) {
            is_array($value) ? $walk($value, "{$prefix}{$key}.") : $flat["{$prefix}{$key}"] = (string) $value;
        }
    };
    $walk(json_decode(File::get(resource_path("js/locales/{$language}.json")), true, flags: JSON_THROW_ON_ERROR), '');

    return $flat;
}

/** @return list<string> the {placeholders} / :placeholders in a text, sorted */
function placeholders(string $text): array
{
    preg_match_all('/\{(\w+)\}|(?<![\w:]):(\w+)/', $text, $found);
    $names = array_values(array_filter([...$found[1], ...$found[2]]));
    sort($names);

    return $names;
}

describe('the screens (resources/js/locales)', function () {
    it('have the same texts in every language', function (string $language) {
        expect(array_keys(flatMessages($language)))->toEqualCanonicalizing(array_keys(flatMessages('en')));
    })->with(['es', 'de']);

    it('keep the same placeholders in every language', function (string $language) {
        $en = flatMessages('en');
        $bad = [];
        foreach (flatMessages($language) as $key => $text) {
            if (placeholders($text) !== placeholders($en[$key] ?? '')) {
                $bad[] = $key;
            }
        }

        expect($bad)->toBe([]);
    })->with(['es', 'de']);

    it('are never left empty or in English by accident', function (string $language) {
        $en = flatMessages('en');
        $same = array_keys(array_filter(flatMessages($language), fn ($text, $key) => trim($text) === '' || ($text === $en[$key] && strlen($text) > 25), ARRAY_FILTER_USE_BOTH));

        expect($same)->toBe([]);
    })->with(['es', 'de']);

    it('include every text the screens ask for', function () {
        $en = flatMessages('en');
        $missing = [];
        foreach (File::allFiles(resource_path('js')) as $file) {
            if (! in_array($file->getExtension(), ['vue', 'ts'], true) || str_contains($file->getPathname(), '/locales/')) {
                continue;
            }
            // t('a.b'), $t('a.b'), te('a.b') and <i18n-t keypath="a.b">
            preg_match_all('/(?<![\w.])\$?t\(\s*[\'"]([\w.]+)[\'"]|keypath="([\w.]+)"/', File::get($file->getPathname()), $found);
            foreach (array_filter([...$found[1], ...$found[2]]) as $key) {
                if (! isset($en[$key])) {
                    $missing[] = $file->getRelativePathname().": {$key}";
                }
            }
        }

        expect($missing)->toBe([]);
    });

    it('have no text that nothing asks for', function () {
        $used = [];
        foreach (File::allFiles(resource_path('js')) as $file) {
            if (in_array($file->getExtension(), ['vue', 'ts'], true) && ! str_contains($file->getPathname(), '/locales/')) {
                $used[] = File::get($file->getPathname());
            }
        }
        $source = implode("\n", $used);

        // A key may be built from a variable (notification kinds, statuses), so only the group prefix has to appear.
        $unused = array_filter(array_keys(flatMessages('en')), fn (string $key) => ! str_contains($source, $key) && ! str_contains($source, substr($key, 0, (int) strrpos($key, '.')).'.'));

        expect(array_values($unused))->toBe([]);
    });
});

describe('the server (lang/)', function () {
    it('has the same framework texts in every language', function (string $language) {
        foreach (['auth', 'passwords', 'pagination', 'validation'] as $file) {
            $en = Arr::dot(require lang_path("en/{$file}.php"));
            $other = Arr::dot(require lang_path("{$language}/{$file}.php"));

            expect(array_keys($other))->toEqualCanonicalizing(array_keys($en), "{$language}/{$file}.php");
        }
    })->with(['es', 'de']);

    it('has the same app texts in every language', function () {
        $es = json_decode(File::get(lang_path('es.json')), true, flags: JSON_THROW_ON_ERROR);
        $de = json_decode(File::get(lang_path('de.json')), true, flags: JSON_THROW_ON_ERROR);

        expect(array_keys($de))->toEqualCanonicalizing(array_keys($es));
        foreach ($es as $key => $text) {
            expect(placeholders($text))->toBe(placeholders($key), "es: {$key}")
                ->and(placeholders($de[$key]))->toBe(placeholders($key), "de: {$key}");
        }
    });

    it('has a translation for every text the code asks for', function () {
        $es = json_decode(File::get(lang_path('es.json')), true, flags: JSON_THROW_ON_ERROR);
        $missing = [];

        $files = [
            ...File::allFiles(app_path()),
            ...File::allFiles(resource_path('views')),
            ...File::allFiles(base_path('routes')),
            File::get(base_path('bootstrap/app.php')) ? new SplFileInfo(base_path('bootstrap/app.php')) : null,
        ];
        foreach (array_filter($files) as $file) {
            // __('text') and trans('text'), single or double quoted, with escapes
            preg_match_all('/(?<![\w>$])(?:__|trans)\(\s*(["\'])((?:\\\\.|(?!\1).)*)\1/s', File::get($file->getPathname()), $found);
            foreach ($found[2] as $i => $text) {
                $text = stripcslashes($text);
                // Dotted keys belong to the framework files (auth.failed, passwords.sent).
                if (! preg_match('/^[a-z_]+(\.[a-z_]+)+$/', $text) && ! isset($es[$text])) {
                    $missing[] = $file->getFilename().': '.$text;
                }
            }
        }

        expect(array_values(array_unique($missing)))->toBe([]);
    });
});
