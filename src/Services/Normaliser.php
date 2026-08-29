<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Reduce a company name to something two spellings of it can be compared by.
 *
 * This is the deterministic half of supplier matching — the pass that runs when
 * the LLM stage found no match. It exists because the disagreements between a
 * scanned invoice and a Clear Books record are almost always clerical rather
 * than substantive: "ACME SUPPLIES LTD." against "Acme Supplies Limited",
 * "Smith & Sons" against "Smith and Sons".
 *
 * Two forms are produced, and the matcher tries them in order:
 *
 *  - `key()` — lower case, punctuation gone, `&` folded to `and`, legal
 *    suffixes removed, words separated by single spaces. This is the one stored
 *    in `clearbooks_cache.normalised_name` and indexed.
 *  - `compact()` — the same with the spaces taken out as well, which settles
 *    "Clearbooks" against "Clear Books". Looser, so the matcher only accepts it
 *    when exactly one candidate matches.
 *
 * **Suffixes are stripped, not equated.** Treating Ltd and Limited as the same
 * word would still leave "Acme Ltd" and "Acme" apart, and the second is what an
 * invoice's letterhead very often says.
 */
final class Normaliser
{
    /**
     * Legal and trading suffixes, stripped from the end of a name.
     *
     * Only ever removed from the **end**, and only while something is left
     * afterwards: "Limited Editions Ltd" must not reduce to "editions", and a
     * supplier genuinely called "Company" must not reduce to nothing.
     *
     * @var array<int,string>
     */
    private const SUFFIXES = [
        'limited', 'ltd', 'plc', 'llp', 'llc', 'lp',
        'inc', 'incorporated', 'corp', 'corporation',
        'co', 'company', 'cic', 'cio', 'group holdings',
        'uk', 'gb',
    ];

    /** Noise words dropped from the front. */
    private const PREFIXES = ['the'];

    /**
     * The comparison key: what is left of a name once spelling stops counting.
     */
    public static function key(string $name): string
    {
        $value = mb_strtolower(trim($name));

        // Accented letters are folded to their plain equivalents where the
        // platform can, so "Café Ltd" and "Cafe Ltd" agree. iconv is not
        // guaranteed to be present, hence the guarded call and the fallback of
        // simply leaving them alone — a name that keeps its accents still
        // matches itself.
        if (function_exists('iconv')) {
            $folded = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);

            if (is_string($folded) && $folded !== '') {
                // Some libiconv builds render an unmappable character as `?`
                // or as `"a`; either would corrupt the key, so the folded form
                // is only taken when it kept the same word count.
                $value = preg_match('/[?]/', $folded) === 1 ? $value : mb_strtolower($folded);
            }
        }

        // "&" is a spelling of "and", not punctuation: fold it before the
        // punctuation pass removes it and joins the words either side.
        $value = str_replace(['&amp;', '&'], ' and ', $value);

        // Apostrophes close up rather than split — "O'Brien" is one word, and
        // turning it into "o brien" would not match "OBrien".
        $value = str_replace(["'", '’', '`'], '', $value);

        // Everything else that is not a letter or a digit is a separator.
        $value = (string) preg_replace('/[^a-z0-9]+/u', ' ', $value);

        $words = array_values(array_filter(explode(' ', trim($value)), static fn (string $w): bool => $w !== ''));

        while ($words !== [] && in_array($words[0], self::PREFIXES, true) && count($words) > 1) {
            array_shift($words);
        }

        // Repeatedly, because "Acme Trading Co Ltd" carries two of them.
        $stripping = true;
        while ($stripping && count($words) > 1) {
            $stripping = false;

            foreach (self::SUFFIXES as $suffix) {
                $parts = explode(' ', $suffix);
                $tail  = array_slice($words, -count($parts));

                if ($tail === $parts && count($words) > count($parts)) {
                    array_splice($words, -count($parts));
                    $stripping = true;
                    break;
                }
            }
        }

        return implode(' ', $words);
    }

    /**
     * The looser key: word boundaries stop counting too.
     *
     * Deliberately separate rather than folded into key(). "A B C Supplies" and
     * "ABC Supplies" are the same company often enough to be worth catching,
     * and different companies rarely often enough that the matcher must insist
     * on a single candidate before it believes one.
     */
    public static function compact(string $name): string
    {
        return str_replace(' ', '', self::key($name));
    }

    /**
     * Every distinct comparison key for a supplier, its trading names included.
     *
     * An invoice is headed with whichever name the supplier trades under, which
     * is frequently not the name on the Clear Books record.
     *
     * @param array<int,string> $tradingNames
     * @return array<int,string>
     */
    public static function keysFor(string $name, array $tradingNames = []): array
    {
        $keys = [];

        foreach (array_merge([$name], $tradingNames) as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }

            $key = self::key($candidate);

            if ($key !== '' && !in_array($key, $keys, true)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
