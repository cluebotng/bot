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
require_once 'includes.php';

\pcntl_async_signals(true);
\pcntl_signal(SIGCHLD, function ($signo, $siginfo) {
    global $logger;
    while (($x = \pcntl_waitpid(0, $status, WNOHANG)) != -1) {
        if ($x == 0) {
            break;
        }
        unset(Globals::$activeChildren[$x]);
        Metrics::set('bot_forks_total', count(Globals::$activeChildren));
        if (\pcntl_wifsignaled($status)) {
            $sig = \pcntl_wtermsig($status);
            if ($sig !== SIGKILL) {
                $logger->error("Child process {$x} killed by signal " . $sig);
            }
        } elseif (\pcntl_wifexited($status)) {
            $exitCode = \pcntl_wexitstatus($status);
            if ($exitCode !== 0) {
                $logger->error("Child process {$x} exited with status {$exitCode}");
            }
        }
    }
});

$shutdownHandler = function ($signo) {
    global $logger;
    $logger->info('Received shutdown signal ' . $signo . ', beginning graceful shutdown');
    HttpFeed::shutdown();
};
\pcntl_signal(SIGTERM, $shutdownHandler);
\pcntl_signal(SIGINT, $shutdownHandler);

date_default_timezone_set('UTC');
doInit();
MetricServer::run();
HttpFeed::stream();

$logger->info('Waiting for ' . Process::pendingChangesTotal() . ' pending changes');
while (Process::pendingChangesTotal() > 0) {
    Process::dispatchPending();
    usleep(100000);
}

$logger->info('Waiting for ' . count(Globals::$activeChildren) . ' workers to finish');
while (!empty(Globals::$activeChildren)) {
    usleep(100000);
}

$logger->info('Shutdown complete, exiting');
exit(0);
