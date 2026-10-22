<?php

namespace App\Support;

class Csv
{
    /** Spreadsheet apps run cells starting with these characters as formulas (CSV injection). */
    public static function safe(string $value): string
    {
        return $value !== '' && str_contains("=+-@\t\r", $value[0]) ? "'".$value : $value;
    }
}
