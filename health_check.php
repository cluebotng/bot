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

 /* Stripped down setup logic */
 date_default_timezone_set('Europe/London');
 include 'vendor/autoload.php';
 $logger = new \Monolog\Logger('cluebotng');
 $logger->pushHandler(new \Monolog\Handler\StreamHandler('php://stderr', \Monolog\Logger::INFO));

 require_once 'cluebot-ng.config.php';
 require_once 'api.php';
 require_once 'globals.php';
 require_once 'metric_functions.php';

 /* Configure API access */
Config::init();
Metrics::init(false);
Api::init($logger);

/* Get container start time */
$start_time = filemtime("/proc/1");
Metrics::set('bot_start_time_seconds', (float)$start_time);

/* If we have been running for less than 1 hour, then all good (back off) */
if ($start_time > (time() - 3600)) {
    $logger->info('Uptime less than 30min (' . $start_time . ')');
    exit(0);
}

/* Get our last edit time */
$usercontribs = Api::$a->usercontribs(Config::$user, 1);
if (count($usercontribs) != 1) {
    $logger->error('Failed to find contributions for ' . Config::$user);
    exit(1);
}
$last_contrib_timestamp = $usercontribs[0]['timestamp'];
Metrics::set('bot_last_contribution_seconds', (float)strtotime($last_contrib_timestamp));

/* If we edited within the last 3 hours, then all good */
if (strtotime($last_contrib_timestamp) > (time() - 10800)) {
    $logger->info('Last contribution was beyond threshold (' . $last_contrib_timestamp . ')');
    exit(0);
}

/* Otherwise, we need to die */
$logger->error('Are you death or paradise? ' . $last_contrib_timestamp . ' / ' . $start_time);
exit(1);
