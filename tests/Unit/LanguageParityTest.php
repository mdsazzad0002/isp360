<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

// Every UI language file has exactly the keys of en.json, with the same {placeholders}, so a page never
// shows a raw key or loses a value in one language (audit H5). en.json is the source: a new key goes
// there first, then into every other file.
class LanguageParityTest extends TestCase
{
    private const DIR = __DIR__ . '/../../resources/js/lang';

    private static function flatten(array $messages, string $prefix = ''): array
    {
        $flat = [];
        foreach ($messages as $key => $value) {
            is_array($value)
                ? $flat += self::flatten($value, "{$prefix}{$key}.")
                : $flat["{$prefix}{$key}"] = (string) $value;
        }
        return $flat;
    }

    private static function load(string $file): array
    {
        $messages = json_decode(file_get_contents($file), true);
        self::assertIsArray($messages, basename($file) . ' is not valid JSON');
        return self::flatten($messages);
    }

    private static function placeholders(string $text): array
    {
        preg_match_all('/\{\s*(\w+)\s*\}/', $text, $m);
        $names = array_unique($m[1]);
        sort($names);
        return $names;
    }

    public function test_every_language_has_the_same_keys_and_placeholders_as_english(): void
    {
        $en = self::load(self::DIR . '/en.json');
        $files = glob(self::DIR . '/*.json');
        $this->assertGreaterThan(1, count($files));

        foreach ($files as $file) {
            $lang = basename($file, '.json');
            if ($lang === 'en') {
                continue;
            }
            $other = self::load($file);
            $this->assertSame([], array_values(array_diff(array_keys($en), array_keys($other))), "{$lang}.json is missing keys that en.json has");
            $this->assertSame([], array_values(array_diff(array_keys($other), array_keys($en))), "{$lang}.json has keys that en.json lacks (add them to en.json first)");

            foreach ($en as $key => $text) {
                $this->assertSame(self::placeholders($text), self::placeholders($other[$key]), "{$lang}.json \"{$key}\" has different {placeholders} from English");
            }
        }
    }
}
