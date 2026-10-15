<?php
namespace WD29\Bridge;

/** Read admin form values without silently repairing malformed identifiers. */
final class AdminInput
{
    public static function text(array $input, string $name, ?string $default = null, int $limit = 4096): ?string
    {
        if (!array_key_exists($name, $input)) { return $default; }
        $value = $input[$name];
        if (!is_string($value) && !is_int($value)) { return null; }
        $value = (string) $value;
        if (function_exists('wp_unslash')) { $value = wp_unslash($value); }
        if (strlen($value) > $limit) { return null; }
        $clean = function_exists('sanitize_text_field') ? sanitize_text_field($value)
            : preg_replace('/[\x00-\x1F\x7F]/', '', strip_tags($value));
        // Reject altered values: an injected tag must not become a valid ID/action.
        return $clean === $value ? $clean : null;
    }

    public static function requiredText(array $input, string $name, string $default = '', int $limit = 4096): string
    {
        $value = self::text($input, $name, $default, $limit);
        if ($value === null) { throw new \RuntimeException('Invalid form value.'); }
        return $value;
    }

    /** Opaque keys/JSON keep exact bytes; they are never rendered without escaping. */
    public static function opaque(array $input, string $name, string $default = '', int $limit = 65536): string
    {
        if (!array_key_exists($name, $input)) { return $default; }
        if (!is_string($input[$name])) { throw new \RuntimeException('Invalid form value.'); }
        $value = function_exists('wp_unslash') ? wp_unslash($input[$name]) : $input[$name];
        if (strlen($value) > $limit || strpos($value, "\0") !== false) { throw new \RuntimeException('Invalid form value.'); }
        return $value;
    }

    public static function rows(array $input, string $name): array
    {
        $value = $input[$name] ?? [];
        if (!is_array($value)) { throw new \RuntimeException('Invalid form rows.'); }
        return function_exists('wp_unslash') ? wp_unslash($value) : $value;
    }
}
