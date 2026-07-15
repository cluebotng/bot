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

class Process
{
    public static function processEdit($change)
    {
        global $logger;

        Metrics::increment('bot_edits_received_total');

        // Reload config from our 'special' pages
        switch ($change['namespace'] . $change['title']) {
            case 'User:' . Config::$user . '/Run':
                $logger->info('Reloading /Run', ['revision_id' => $change['revid']]);
                refreshRunFlag();
                break;
            case 'User:' . Config::$user . '/Optin':
                $logger->info('Reloading /Optin', ['revision_id' => $change['revid']]);
                Globals::$optin = Api::$q->getpage('User:' . Config::$user . '/Optin');
                break;
            case 'User:' . Config::$user . '/AngryOptin':
                $logger->info('Reloading /AngryOptin', ['revision_id' => $change['revid']]);
                Globals::$aoptin = Api::$q->getpage('User:' . Config::$user . '/AngryOptin');
                break;
        }

        // Check this is an allowed namespace (same as for IRC)
        if (
            $change['namespace'] != 'Main:' and
            !preg_match(
                '/\* \[\[(' . preg_quote($change['namespace'] . $change['title'], '/') .
                ')\]\] \- .*/i',
                Globals::$optin
            )
        ) {
            $logger->debug('Skipping due to namespace', ['revision_id' => $change['revid']]);
            Metrics::increment('bot_edits_skipped_namespace_total', [$change['namespace']]);
            return;
        }

        // Re-authenticate if required
        if ((time() - Globals::$atime) >= 600) {
            if (!Api::$a->loggedin()) {
                $logger->warning('Lost authentication');
                if (!Api::$a->login(Config::$user, Config::$pass)) {
                    $logger->error('Failed to re-authenticate');
                    die(); // Before we fork, this is the parent
                }
            }
            Globals::$atime = time();
        }

        // Start actually processing things
        $logger->info('Processing: ' . $change['namespace'] . $change['title'], ['revision_id' => $change['revid']]);

        // Ignore new articles
        if (in_array('N', $change['flags'])) {
            $logger->info('Skipping: New article', ['revision_id' => $change['revid']]);
            Metrics::increment('bot_edits_skipped_new_article_total');
            return;
        }

        if (Config::$fork) {
            $pid = pcntl_fork();
            if ($pid == -1) {
                $logger->error("Failed to fork");
                die();
            }
            if ($pid != 0) {
                // Parent
                $logger->debug("Created fork with " . $pid);
                Globals::$activeChildren[$pid] = true;
                Metrics::set('bot_forks_total', count(Globals::$activeChildren));
                return;
            }
            // Child
            $logger->debug("Fork started");
            mt_srand();
            Metrics::reset();
        }
        $change = parseFeedData($change);
        if ($change === null) {
            Metrics::increment('bot_edits_skipped_missing_data_total');
        } else {
            self::processEditThread($change);
        }
        if (Config::$fork) {
            $logger->debug("Fork finished");
            // Avoid propagating shutdown signals from die() which cause curl's connection to get dropped
            posix_kill(posix_getpid(), SIGKILL);
        }
    }

    public static function processEditThread($change)
    {
        global $logger;
        $score = null;

        if (!isVandalism($change['all'], $score)) {
            $logger->info('Skipping: Below threshold', ['revision_id' => $change['revid'], 'score' => $score]);
            Metrics::increment('bot_edits_below_threshold_total');
            Relay::publishEdit($change, $score, false, 'Below threshold');
            return;
        }

        if (Action::isWhitelisted($change['user'])) {
            $logger->info('Skipping: User whitelisted', ['revision_id' => $change['revid'], 'score' => $score]);
            Metrics::increment('bot_edits_whitelisted_total');
            Relay::publishEdit($change, $score, false, 'User whitelisted');
            return;
        }

        Metrics::increment('bot_edits_vandalism_detected_total');

        $reason = 'ANN scored at ' . $score;
        $heuristic = '';
        $diff = 'https://en.wikipedia.org/w/index.php' .
            '?title=' . urlencode($change['title']) .
            '&diff=' . urlencode($change['revid']) .
            '&oldid=' . urlencode($change['old_revid']);
        $report = '[[' . str_replace('File:', ':File:', $change['title']) . ']] was '
            . '[' . $diff . ' changed] by '
            . '[[Special:Contributions/' . $change['user'] . '|' . $change['user'] . ']] '
            . '[[User:' . $change['user'] . '|(u)]] '
            . '[[User talk:' . $change['user'] . '|(t)]] '
            . $reason . ' on ' . gmdate('c');
        $ircreport = "\x0315[[\x0307" . $change['title'] . "\x0315]] by \"\x0303" . $change['user'] .
            "\x0315\" (\x0312 " . $change['url'] . " \x0315) \x0306" . $score . "\x0315 (";
        $change['mysqlid'] = Db::detectedVandalism(
            $change['user'],
            $change['title'],
            $heuristic,
            $reason,
            $change['url'],
            $change['old_revid'],
            $change['revid']
        );
        list($shouldRevert, $revertReason) = Action::shouldRevert($change);
        Metrics::increment('bot_revert_decisions_total', [$shouldRevert ? 'yes' : 'no', $revertReason]);
        if ($shouldRevert) {
            $logger->notice('Reverting: ' . $revertReason, ['revision_id' => $change['revid'], 'score' => $score]);
            Metrics::increment('bot_reverts_attempted_total');
            $rbret = Action::doRevert($change);
            if ($rbret !== false) {
                Metrics::increment('bot_reverts_succeeded_total');
                Relay::publishEdit($change, $score, true, $revertReason);
                Action::doWarn($change, $report);
                Db::vandalismReverted($change['mysqlid']);
            } else {
                $rv2 = Api::$a->revisions($change['title'], 1);
                if (!empty($rv2) && $change['user'] != $rv2[0]['user']) {
                    $logger->notice(
                        'Revert Beaten By: ' . $rv2[0]['user'],
                        ['revision_id' => $change['revid'], 'score' => $score]
                    );
                    Metrics::increment('bot_reverts_beaten_total');
                    Relay::publishEdit($change, $score, false, 'Beaten by ' . $rv2[0]['user']);
                    Db::vandalismRevertBeaten($change['mysqlid'], $change['title'], $rv2[0]['user'], $change['url']);
                }
            }
        } else {
            $logger->notice('Not Reverting: ' . $revertReason, ['revision_id' => $change['revid'], 'score' => $score]);
            Relay::publishEdit($change, $score, false, $revertReason);
        }
    }
}
