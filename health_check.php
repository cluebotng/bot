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

 /* Configure API access */
Config::init();
Api::init($logger);

/* Get our last edit time */
$usercontribs = Api::$a->usercontribs(Config::$user, 1);
if (count($usercontribs) != 1) {
    $logger->addError('Failed to find usercontribs for ' . $Config::$user);
    exit(1);
}
$last_contrib_timestamp = $usercontribs[0]['timestamp'];

 /* If we edited in the last 1 hour, then all good */
if (strtotime($last_contrib_timestamp) > (time() - 3600)) {
    $logger->addInfo('Last contribution was within last 15min (' . $last_contrib_timestamp . ')');
    exit(0);
}

 /* Get out uptime, since this is for a container, just check the 'init' pid */
 $current_uptime = filemtime("/proc/1");

 /* If we have been running for less than 30min, then all good (back off) */
if ($current_uptime > (time() - 1800)) {
    $logger->addInfo('Uptime less than 30min (' . $current_uptime . ')');
    exit(0);
}

 /* Otherwise, we need to die */
 $logger->addError('Are you death or paradise? ' . $last_contrib_timestamp . ' / ' . $current_uptime);
 exit(1);
