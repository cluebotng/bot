<?php

namespace CluebotNG;

/*
 * Copyright (C) 2015 Jacobi Carter and Chris Breneman
 *
 * This file is part of ClueBot NG.
 *
 * ClueBot NG is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * ClueBot NG is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with ClueBot NG.  If not, see <http://www.gnu.org/licenses/>.
 */

date_default_timezone_set('Europe/London');
include 'vendor/autoload.php';

// Logger
$logger = new \Monolog\Logger('cluebotng');

// Log to disk unless we are in a build pack
if (!getenv('NO_HOME')) {
    $logger->pushHandler(
        new \Monolog\Handler\RotatingFileHandler(
            getenv('HOME') . '/logs/cluebotng.log',
            2,
            \Monolog\Logger::INFO,
            true,
            0600,
            false
        )
    );
} else {
    $logger->pushHandler(new \Monolog\Handler\StreamHandler('php://stdout', \Monolog\Logger::INFO));
}

require_once 'cluebot-ng.config.php';
require_once 'action_functions.php';
require_once 'cbng.php';
require_once 'feed_functions.php';
require_once 'irc_functions.php';
require_once 'mysql_functions.php';
require_once 'globals.php';
require_once 'api.php';
require_once 'process_functions.php';
require_once 'misc_functions.php';
require_once 'db_functions.php';
