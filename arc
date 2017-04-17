#!/usr/bin/env php
<?php

if (file_exists(__DIR__.'/../../autoload.php')) {
    require __DIR__.'/../../autoload.php';
} else {
    require __DIR__.'/vendor/autoload.php';
}

$app = new Symfony\Component\Console\Application('Arc Installer', '0.0.0');
$app->add(new Arc\Installer\Console\NewCommand);

$app->run();
