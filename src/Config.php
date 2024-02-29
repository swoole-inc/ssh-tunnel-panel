<?php

namespace App;

class Config
{
    public static array $config;
    public static string $file;

    static function load()
    {
        self::$file = ROOT_PATH . '/config.json';
        if (!is_file(self::$file)) {
            $config = [
                'window' => [
                    'width' => 600,
                    'height' => 800,
                    'left' => 400,
                    'top' => 400,
                ],
                'list' => [],
            ];
            file_put_contents(self::$file, json_encode($config));
        } else {
            $config = json_decode(file_get_contents(self::$file), true);
        }
        self::$config = $config;
    }

    static function save()
    {
        file_put_contents(self::$file, json_encode(self::$config, JSON_PRETTY_PRINT));
    }
}
