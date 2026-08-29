<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Fills `{{ placeholders }}` in a prompt template.
 *
 * The n8n flow interpolated with JavaScript expressions —
 * `{{ $('suppliers').all().map(i => i.json).toJsonString() }}` — which is
 * powerful and also means a prompt can do anything, including something
 * expensive or wrong. Here a placeholder is a **name only**. The caller decides
 * what each name resolves to; the template chooses which of them it wants and
 * where they go.
 *
 * Unknown names are an **error**, not a silent pass-through. A prompt that
 * quietly sends the literal text `{{ suppliers }}` to a model produces a
 * confident answer built on nothing, and the only symptom is bad output much
 * later. Failing at render time costs one clear message instead.
 */
final class PromptRenderer
{
    /** `{{ name }}`, with any amount of space inside the braces. */
    private const PATTERN = '/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/';

    /**
     * @param array<string,string> $variables
     */
    public static function render(string $template, array $variables): string
    {
        $unknown = [];

        $rendered = preg_replace_callback(
            self::PATTERN,
            static function (array $match) use ($variables, &$unknown): string {
                $name = $match[1];

                if (!array_key_exists($name, $variables)) {
                    $unknown[] = $name;

                    return $match[0];
                }

                return $variables[$name];
            },
            $template
        );

        if ($unknown !== []) {
            $unknown = array_values(array_unique($unknown));

            throw new RuntimeException(sprintf(
                'This prompt uses %s, which %s not available here. Available: %s.',
                implode(', ', array_map(static fn (string $n): string => '{{ ' . $n . ' }}', $unknown)),
                count($unknown) === 1 ? 'is' : 'are',
                $variables === [] ? 'nothing' : implode(', ', array_keys($variables))
            ));
        }

        return (string) $rendered;
    }

    /**
     * The placeholder names a template actually uses.
     *
     * The prompt editor shows this so somebody editing a template can see at a
     * glance what it depends on, and so a typo is visible before it is saved.
     *
     * @return array<int,string>
     */
    public static function variablesUsed(string $template): array
    {
        preg_match_all(self::PATTERN, $template, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * Encode a reference list for injection.
     *
     * Pretty-printed rather than compact: these lists go into a prompt a human
     * has to be able to read while editing it, and the token cost of the
     * whitespace is trivial beside the list itself. Slashes unescaped so a URL
     * in a supplier record does not arrive full of backslashes.
     *
     * @param array<int,array<string,mixed>> $rows
     */
    public static function encodeList(array $rows): string
    {
        if ($rows === []) {
            // An empty array would read as `[]`, which a model tends to treat as
            // "there are none" — correct, but it will then invent values to fill
            // the gap. Saying so plainly gets a better failure.
            return '[]  // this list is empty — nothing has been cached from Clear Books yet';
        }

        return json_encode(
            $rows,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }
}
