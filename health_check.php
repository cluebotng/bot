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
require_once 'globals.php';
require_once 'metric_functions.php';
require_once 'mysql_functions.php';
require_once 'db_functions.php';

Config::init();
Metrics::init(false);
const REPLICATION_LAG_HISTORY_PATH = "/tmp/cluebotng_replica_lag_high_since";

/* Get container start time,
 * If we have been running for less than 1 hour, then all good (back off) */
$start_time = (float)filemtime("/proc/1");
Metrics::set('bot_start_time_seconds', $start_time);

if ($start_time > (time() - 3600)) {
    $logger->info('Uptime less than 30min (' . $start_time . ')');
    exit(0);
}

/* If we edited within the last 3 hours, then all good (things are working) */
$last_contribution_time = Db::getLastVandalismTime();
if ($last_contribution_time !== null && $last_contribution_time > (time() - 10800)) {
    $logger->info('Last contribution was within threshold (' . $last_contribution_time . ')');
    exit(0);
}

/* We haven't edited recently - if the replica is lagging badly, the bot pauses consumption
 * on purpose, so that explains it and we shouldn't restart for it */
$replication_lag = ReplicaDb::getCurrentReplicaLag();
if ($replication_lag >= Config::$mw_mysql_replication_lag_max) {
    $logger->info('No recent edits, but replica lag is high (' . $replication_lag . 's), treating as healthy');
    @file_put_contents(REPLICATION_LAG_HISTORY_PATH, (string)time());
    exit(0);
}

/* Lag is currently normal, but give it an hour to catch up if it was high recently -
 * the bot may still be working through the backlog and not have edited yet */
$last_replication_high_lag_time = @file_get_contents(REPLICATION_LAG_HISTORY_PATH);
if ($last_replication_high_lag_time !== false && (int)$last_replication_high_lag_time > (time() - 3600)) {
    $logger->info('No recent edits, but replica lag was high within the last hour, treating as healthy');
    exit(0);
}

/* Otherwise, we need to die */
$logger->error('Are you death or paradise? ' . $last_contribution_time . ' / ' . $start_time);
exit(1);
