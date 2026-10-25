<?php
declare(strict_types=1);

namespace RockSoftware\MessagingClient\Test\TestCase;

use Cake\TestSuite\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Every text the plugin shows has a Finnish translation, so an application in
 * Finnish never shows a stray English sentence.
 */
class TranslationsTest extends TestCase
{
    public function testEveryTextHasAFinnishTranslation(): void
    {
        $root = dirname(__DIR__, 2);
        $po = (string)file_get_contents($root . '/resources/locales/fi/rock_software_messaging_client.po');
        preg_match_all('/^msgid(?:_plural)? "(.+)"$/m', $po, $ids);
        $translated = array_map(fn(string $id): string => stripcslashes($id), $ids[1]);

        $missing = [];
        foreach (['src', 'templates'] as $dir) {
            if (!is_dir($root . '/' . $dir)) {
                continue;
            }
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                foreach (self::texts((string)file_get_contents($file->getPathname())) as $text) {
                    if ($text !== '' && !in_array($text, $translated, true)) {
                        $missing[] = $text;
                    }
                }
            }
        }

        $this->assertSame([], array_values(array_unique($missing)));
    }

    /**
     * The msgids of `__d('rock_software_messaging_client', …)` and `__dn(…)`
     * calls, in single or double quotes, a literal split with `.` joined back.
     *
     * @param string $source PHP source.
     * @return list<string>
     */
    private static function texts(string $source): array
    {
        $literal = "(?:'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\")";
        $joined = "{$literal}(?:\\s*\\.\\s*{$literal})*";
        preg_match_all(
            "/__d(n?)\\(\\s*'rock_software_messaging_client',\\s*({$joined})(?:,\\s*({$joined}))?/",
            $source,
            $calls,
            PREG_SET_ORDER,
        );
        $texts = [];
        foreach ($calls as $call) {
            $texts[] = self::join($call[2]);
            if ($call[1] === 'n' && ($call[3] ?? '') !== '') {
                $texts[] = self::join($call[3]);
            }
        }

        return $texts;
    }

    /**
     * @param string $expression Quoted literals joined with `.`.
     * @return string
     */
    private static function join(string $expression): string
    {
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'|\"((?:[^\"\\\\]|\\\\.)*)\"/", $expression, $parts, PREG_SET_ORDER);
        $text = '';
        foreach ($parts as $part) {
            $text .= ($part[1] ?? '') !== '' ? $part[1] : ($part[2] ?? '');
        }

        return str_replace(["\\'", '\\"'], ["'", '"'], $text);
    }
}
