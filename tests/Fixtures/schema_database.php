<?php

declare(strict_types=1);

// Loaded only by the migration tests in a separate PHP process.
final class Db
{
    public static $instance;

    public static function getInstance()
    {
        return self::$instance;
    }
}

function pSQL(string $value): string
{
    return $value;
}
