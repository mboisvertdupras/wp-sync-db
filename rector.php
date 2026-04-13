<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Set\ValueObject\LevelSetList;
use Rector\Set\ValueObject\SetList;

return static function (RectorConfig $rectorConfig): void {
    // Paths to process
    $rectorConfig->paths([
        __DIR__ . '/src',
        __DIR__ . '/compatibility',
        __DIR__ . '/wp-sync-db.php',
    ]);

    // Exclude template directory (view files with HTML/PHP mix)
    $rectorConfig->skip([
        __DIR__ . '/template',
    ]);

    // PHP 8.3 upgrade ruleset
    $rectorConfig->sets([
        LevelSetList::UP_TO_PHP_83,
    ]);

    // WordPress coding standards
    $rectorConfig->import(SetList::CODING_STYLE);
    $rectorConfig->import(SetList::DEAD_CODE);
    $rectorConfig->import(SetList::EARLY_RETURN);
    $rectorConfig->import(SetList::INSTANCEOF);
    $rectorConfig->import(SetList::NAMING);
    $rectorConfig->import(SetList::PRIVATIZATION);
    $rectorConfig->import(SetList::STRICT_BOOLEANS);
    $rectorConfig->import(SetList::TYPE_DECLARATION);
};
