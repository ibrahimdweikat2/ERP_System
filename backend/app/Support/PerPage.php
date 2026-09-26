<?php

namespace App\Support;

/**
 * Page size for list endpoints: the client's per_page (1–100) when given, otherwise
 * the endpoint's own default, so callers that send nothing keep their behaviour.
 */
final class PerPage
{
    public static function resolve(int $default): int
    {
        $requested = filter_var(request()->query('per_page'), FILTER_VALIDATE_INT);

        return $requested === false ? $default : max(1, min(100, $requested));
    }
}
