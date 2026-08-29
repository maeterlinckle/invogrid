<?php

declare(strict_types=1);

namespace App\Core;

/**
 * One-shot messages and form state that survive a redirect.
 */
final class Flash
{
    private const MESSAGES = '__flash_messages';
    private const ERRORS   = '__flash_errors';
    private const OLD      = '__flash_old';

    public static function success(string $message): void
    {
        self::add('success', $message);
    }

    public static function error(string $message): void
    {
        self::add('error', $message);
    }

    public static function warning(string $message): void
    {
        self::add('warning', $message);
    }

    public static function info(string $message): void
    {
        self::add('info', $message);
    }

    public static function add(string $type, string $message): void
    {
        $messages   = Session::get(self::MESSAGES, []);
        $messages[] = ['type' => $type, 'message' => $message];
        Session::put(self::MESSAGES, $messages);
    }

    /** @return array<int,array{type:string,message:string}> */
    public static function messages(): array
    {
        return (array) Session::pull(self::MESSAGES, []);
    }

    /** @param array<string,string> $errors */
    public static function errors(array $errors): void
    {
        Session::put(self::ERRORS, $errors);
    }

    /** @return array<string,string> */
    public static function takeErrors(): array
    {
        return (array) Session::pull(self::ERRORS, []);
    }

    /** @param array<string,mixed> $input */
    public static function old(array $input): void
    {
        unset($input['_token'], $input['password'], $input['password_confirmation'], $input['current_password']);
        Session::put(self::OLD, $input);
    }

    /** @return array<string,mixed> */
    public static function takeOld(): array
    {
        return (array) Session::pull(self::OLD, []);
    }
}
