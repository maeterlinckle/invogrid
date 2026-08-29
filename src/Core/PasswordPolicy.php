<?php

declare(strict_types=1);

namespace App\Core;

/**
 * What counts as an acceptable password, in one place.
 *
 * There are three ways into this application's password handling — the command
 * line, an administrator setting somebody's password, and a person changing
 * their own — and a policy written out three times is a policy that drifts. The
 * numbers come from configuration; the wording of the rule and the sentence a
 * person reads come from here.
 */
final class PasswordPolicy
{
    public static function minLength(): int
    {
        // Twelve is long enough to be worth having and short enough that a
        // three-word passphrase clears it without an argument.
        return max(8, (int) Config::get('security.password.min_length', 12));
    }

    public static function minClasses(): int
    {
        return min(4, max(1, (int) Config::get('security.password.min_classes', 3)));
    }

    /** The Validator rule string, so no caller writes the numbers out again. */
    public static function rule(): string
    {
        return sprintf('required|password:%d,%d', self::minLength(), self::minClasses());
    }

    /** The sentence shown under a password box, and printed by the CLI. */
    public static function describe(): string
    {
        return sprintf(
            'At least %d characters, using %d of: lower-case letters, upper-case letters, '
                . 'numbers, symbols. A passphrase is fine, and easier to remember.',
            self::minLength(),
            self::minClasses()
        );
    }

    /**
     * Everything wrong with this password, as sentences.
     *
     * Returns a list rather than the first failure: somebody who is told their
     * password is too short, fixes that, and is then told it also has to have a
     * number, has been made to guess twice at a rule that could have been
     * stated once.
     *
     * `$context` is whatever this password must not be built out of — the
     * username, the person's name, the name of the application. A password that
     * is the username with a digit on the end survives every character-class
     * rule ever written and is the first thing anybody tries.
     *
     * @param  array<int,string>  $context
     * @return array<int,string>
     */
    public static function problems(string $password, array $context = []): array
    {
        $problems = [];

        if (mb_strlen($password) < self::minLength()) {
            $problems[] = sprintf('It must be at least %d characters.', self::minLength());
        }

        if (Validator::characterClasses($password) < self::minClasses()) {
            $problems[] = sprintf(
                'It must use at least %d of: lower-case letters, upper-case letters, numbers, symbols.',
                self::minClasses()
            );
        }

        // Long enough that a short word inside a passphrase is not caught, but
        // short enough to catch "nick" in "Nick2026!!".
        $haystack = mb_strtolower($password);

        foreach ($context as $term) {
            $term = mb_strtolower(trim($term));

            if (mb_strlen($term) >= 3 && str_contains($haystack, $term)) {
                $problems[] = sprintf('It must not contain "%s".', $term);
                break;
            }
        }

        return $problems;
    }
}
