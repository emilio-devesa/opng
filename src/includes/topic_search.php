<?php

const OPNG_MAX_SEARCH_QUERY_BYTES = 255;

function topic_search_expression(string $query): ?string
{
    if (
        strlen($query) > OPNG_MAX_SEARCH_QUERY_BYTES
        || preg_match('//u', $query) !== 1
    ) {
        return null;
    }

    $terms = preg_split('/[^\p{L}\p{N}]+/u', trim($query), -1, PREG_SPLIT_NO_EMPTY);
    if ($terms === false || $terms === []) {
        return null;
    }

    return implode(' ', array_map(
        static fn (string $term): string => '+' . $term . '*',
        $terms
    ));
}
