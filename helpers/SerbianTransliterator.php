<?php

namespace app\helpers;

use Turanjanin\SerbianTransliterator\Transliterator;
use function preg_split;
use function str_starts_with;
use function str_ends_with;

class SerbianTransliterator extends Transliterator
{
    public static function toCyrillic(string $text): string
    {
        $text = static::normalizeLatin($text);

        $whiteSpace = '(?>\s+)';
        $html = '<[^>]+>';
        $icuPluralMessages = '\{(?:[^{}]+|(?R))*\}';

        $icuPluralParts = '(?P<selector>=\d+|zero|one|two|few|many|other)
            \s*
            \{
                (?P<message>
                    (?:
                        [^{}]+
                        |
                        \{ (?&message) \}
                    )*
                )
            \}';

        $tokens = preg_split("#({$whiteSpace}|{$html}|{$icuPluralMessages})#u", $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        $transliterated = [];
        foreach ($tokens as $token) {
            $token = preg_replace_callback("#{$icuPluralParts}#xu", function ($matches) {
                return str_replace($matches['message'], static::toCyrillic($matches['message']), $matches[0]);
            }, $token);

            if (static::shouldBeIgnored($token)) {
                $transliterated[] = $token;
                continue;
            }

            $transliterated[] = static::wordToCyrillic($token);
        }

        return implode('', $transliterated);
    }

    private static function shouldBeIgnored(string $token): bool
    {
        // HTML tags
        if (str_starts_with($token, '<') && str_ends_with($token, '>')) {
            return true;
        }

        // Placeholders
        if (str_starts_with($token, '{') && str_ends_with($token, '}')) {
            return true;
        }

        // Validation attributes
        if (str_starts_with($token, ':')) {
            return true;
        }

        return static::looksLikeForeignWord($token);
    }
}