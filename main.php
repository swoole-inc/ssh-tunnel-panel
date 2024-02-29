<?php
define('ROOT_PATH', __DIR__);

require ROOT_PATH . '/vendor/autoload.php';

if ($_SERVER['argc'] > 1) {
    $name = $_SERVER['argv'][1];
    $class = 'App\\Service\\' . $name;
    $obj = new $class;
    $obj->service();
} else {
    $app = new App\Main();
    $app->run();
}

