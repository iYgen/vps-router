<?php

namespace App;

class App
{
    private static ?array $config = null;

    public static function config(): array
    {
        if (self::$config === null) {
            $path = $_SERVER['PANEL_CONFIG_PATH'] ?? (getenv('PANEL_CONFIG_PATH') ?: '/etc/panel/config.php');
            if (!file_exists($path)) {
                throw new \RuntimeException("Config file not found: $path. Copy config.php.example there and edit it.");
            }
            self::$config = require $path;
        }

        return self::$config;
    }
}
