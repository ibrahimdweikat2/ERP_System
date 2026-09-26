<?php

namespace App\Support;

/**
 * Free-text search for list endpoints: matches the term against any of the given
 * columns (JSON paths such as customer_snapshot->name included). Wildcards typed by
 * the user are escaped, so "%" and "_" are searched literally.
 */
final class Search
{
    /** @param  list<string>  $columns */
    public static function apply(object $query, mixed $term, array $columns): object
    {
        $term = is_string($term) ? trim(mb_substr($term, 0, 120)) : '';
        if ($term === '') {
            return $query;
        }
        $like = '%'.addcslashes($term, '%_\\').'%';

        return $query->where(function ($q) use ($columns, $like) {
            foreach ($columns as $column) {
                $q->orWhere($column, 'like', $like);
            }
        });
    }
}
