<?php

require_once __DIR__ . '/../vendor/autoload.php';

$GLOBALS['logger'] = new \Monolog\Logger('cluebotng-tests');
$GLOBALS['logger']->pushHandler(new \Monolog\Handler\NullHandler());

require_once __DIR__ . '/../cluebot-ng.config.php';
require_once __DIR__ . '/../api.php';
require_once __DIR__ . '/../metric_functions.php';
require_once __DIR__ . '/../action_functions.php';
require_once __DIR__ . '/FakeWiki.php';
