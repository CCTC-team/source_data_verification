<?php

namespace CCTC\SourceDataVerificationModule\Classes;

/**
 * Hashes field values so a verification can be tied to the exact value that was checked without
 * copying participant data into the module's tables. Checkbox fields have one data row per ticked
 * option, so values are sorted before hashing.
 */
class ValueHasher
{
    public static function hash(array $values): string
    {
        // a missing row and an empty-string row both mean "blank"
        $values = array_values(array_filter(array_map('strval', $values), fn($v) => $v !== ''));
        sort($values, SORT_STRING);
        return hash('sha256', implode("\x1f", $values));
    }

    /**
     * @param array<string, string[]> $valuesByField raw values per field (missing fields count as empty)
     * @return array<string, string> field => hash
     */
    public static function hashAll(array $fieldNames, array $valuesByField): array
    {
        $out = [];
        foreach ($fieldNames as $field) {
            $out[$field] = self::hash($valuesByField[$field] ?? []);
        }
        return $out;
    }
}
