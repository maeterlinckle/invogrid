<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Small rule-based validator.
 *
 * Rules: required, email, min:n, max:n, numeric, integer, decimal, date, url,
 *        in:a,b,c, matches:field, unique:table,column[,ignoreId], exists:table,column,
 *        boolean, alphadash, password:length,classes.
 *
 * `password:minLength,minClasses` holds no thresholds of its own — they are
 * passed in at the call site, so tightening them is a change in one place
 * rather than a change to this class.
 */
final class Validator
{
    /** @var array<string,mixed> */
    private array $data;
    /** @var array<string,string> */
    private array $errors = [];
    /** @var array<string,string> */
    private array $labels;

    /**
     * @param array<string,mixed>  $data
     * @param array<string,string> $labels
     */
    public function __construct(array $data, array $labels = [])
    {
        $this->data   = $data;
        $this->labels = $labels;
    }

    /**
     * @param array<string,mixed>  $data
     * @param array<string,string> $rules
     * @param array<string,string> $labels
     */
    public static function make(array $data, array $rules, array $labels = []): self
    {
        $validator = new self($data, $labels);

        foreach ($rules as $field => $ruleString) {
            $validator->apply($field, $ruleString);
        }

        return $validator;
    }

    private function apply(string $field, string $ruleString): void
    {
        $value    = $this->data[$field] ?? null;
        $value    = is_string($value) ? trim($value) : $value;
        $rules    = array_filter(array_map('trim', explode('|', $ruleString)));
        $optional = !in_array('required', $rules, true);

        if ($optional && ($value === null || $value === '')) {
            return; // nothing to validate
        }

        foreach ($rules as $rule) {
            $parameter = null;
            if (str_contains($rule, ':')) {
                [$rule, $parameter] = explode(':', $rule, 2);
            }

            if ($this->fails($rule, $value, $parameter, $field)) {
                break; // one message per field is enough
            }
        }
    }

    private function fails(string $rule, mixed $value, ?string $parameter, string $field): bool
    {
        $label = $this->labels[$field] ?? ucfirst(str_replace('_', ' ', $field));

        switch ($rule) {
            case 'required':
                if ($value === null || $value === '' || (is_array($value) && $value === [])) {
                    return $this->fail($field, "$label is required.");
                }
                break;

            case 'email':
                if (filter_var((string) $value, FILTER_VALIDATE_EMAIL) === false) {
                    return $this->fail($field, "$label must be a valid email address.");
                }
                break;

            case 'url':
                if (filter_var((string) $value, FILTER_VALIDATE_URL) === false
                    || !preg_match('#^https?://#i', (string) $value)) {
                    return $this->fail($field, "$label must be a valid http(s) URL.");
                }
                break;

            case 'min':
                if (mb_strlen((string) $value) < (int) $parameter) {
                    return $this->fail($field, "$label must be at least $parameter characters.");
                }
                break;

            case 'max':
                if (mb_strlen((string) $value) > (int) $parameter) {
                    return $this->fail($field, "$label must be no more than $parameter characters.");
                }
                break;

            case 'numeric':
            case 'decimal':
                if (!is_numeric($value)) {
                    return $this->fail($field, "$label must be a number.");
                }
                break;

            case 'integer':
                if (filter_var((string) $value, FILTER_VALIDATE_INT) === false) {
                    return $this->fail($field, "$label must be a whole number.");
                }
                break;

            case 'min_value':
                if ((float) $value < (float) $parameter) {
                    return $this->fail($field, "$label must be at least $parameter.");
                }
                break;

            case 'max_value':
                if ((float) $value > (float) $parameter) {
                    return $this->fail($field, "$label must be no more than $parameter.");
                }
                break;

            case 'date':
                $date = \DateTimeImmutable::createFromFormat('Y-m-d', (string) $value);
                if ($date === false || $date->format('Y-m-d') !== (string) $value) {
                    return $this->fail($field, "$label must be a valid date.");
                }
                break;

            case 'in':
                $allowed = explode(',', (string) $parameter);
                if (!in_array((string) $value, $allowed, true)) {
                    return $this->fail($field, "$label is not a valid choice.");
                }
                break;

            case 'boolean':
                if (!in_array((string) $value, ['0', '1', 'true', 'false', 'on', 'off', 'yes', 'no'], true)) {
                    return $this->fail($field, "$label must be true or false.");
                }
                break;

            case 'alphadash':
                if (!preg_match('/^[A-Za-z0-9._\- ]+$/', (string) $value)) {
                    return $this->fail($field, "$label may only contain letters, numbers, spaces, dots, dashes and underscores.");
                }
                break;

            case 'password':
                // password:minLength,minClasses — the thresholds come from the
                // application or account policy, so this rule holds no numbers
                // of its own.
                $parts   = explode(',', (string) $parameter);
                $length  = (int) ($parts[0] ?? 12);
                $classes = (int) ($parts[1] ?? 3);

                if (mb_strlen((string) $value) < $length) {
                    return $this->fail($field, "$label must be at least $length characters.");
                }

                if (self::characterClasses((string) $value) < $classes) {
                    return $this->fail($field, "$label must include at least $classes of: "
                        . 'lower-case letters, upper-case letters, numbers, symbols.');
                }
                break;

            case 'matches':
                if ((string) $value !== (string) ($this->data[(string) $parameter] ?? '')) {
                    return $this->fail($field, "$label does not match.");
                }
                break;

            case 'unique':
                // unique:table,column[,ignoreId]
                $parts  = explode(',', (string) $parameter);
                $table  = $parts[0];
                $column = $parts[1] ?? $field;
                $ignore = isset($parts[2]) ? (int) $parts[2] : 0;

                $sql    = sprintf('SELECT COUNT(*) FROM `%s` WHERE `%s` = ?', $table, $column);
                $params = [$value];
                if ($ignore > 0) {
                    $sql     .= ' AND id <> ?';
                    $params[] = $ignore;
                }

                if ((int) Database::scalar($sql, $params) > 0) {
                    return $this->fail($field, "$label is already in use.");
                }
                break;

            case 'exists':
                // exists:table,column
                $parts  = explode(',', (string) $parameter);
                $table  = $parts[0];
                $column = $parts[1] ?? 'id';

                $sql = sprintf('SELECT COUNT(*) FROM `%s` WHERE `%s` = ?', $table, $column);
                if ((int) Database::scalar($sql, [$value]) === 0) {
                    return $this->fail($field, "$label does not exist.");
                }
                break;
        }

        return false;
    }

    /**
     * How many of the four character classes a password draws on.
     *
     * Counted rather than required individually: "three of four" lets somebody
     * use a long passphrase with numbers in it without also forcing a symbol
     * they will write on a sticky note.
     */
    public static function characterClasses(string $password): int
    {
        $classes = 0;
        $classes += preg_match('/[a-z]/', $password) === 1 ? 1 : 0;
        $classes += preg_match('/[A-Z]/', $password) === 1 ? 1 : 0;
        $classes += preg_match('/[0-9]/', $password) === 1 ? 1 : 0;
        $classes += preg_match('/[^A-Za-z0-9]/', $password) === 1 ? 1 : 0;

        return $classes;
    }

    private function fail(string $field, string $message): bool
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = $message;
        }

        return true;
    }

    public function addError(string $field, string $message): void
    {
        $this->errors[$field] = $message;
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    public function failed(): bool
    {
        return !$this->passes();
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }
}
