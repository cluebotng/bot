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

class Action
{
    private static $months = [
        'January' => 1, 'February' => 2, 'March' => 3,
        'April' => 4, 'May' => 5, 'June' => 6, 'July' => 7,
        'August' => 8, 'September' => 9, 'October' => 10,
        'November' => 11, 'December' => 12,
    ];

    public static function doWarn($change, $report)
    {
        global $logger;
        $warning_level = self::getWarningLevel($change['user'], $tpcontent) + 1;
        if (!Config::$dry) {
            if ($warning_level >= 4) {
                /* Report them if they have been warned 4 times. */
                $logger->notice(
                    'Reporting user to AIV',
                    ['revision_id' => $change['revid'], 'user' => $change['user']]
                );
                Metrics::increment('bot_aiv_reports_total');
                self::aiv($change, $report);
            } else {
                /* Warn them if they haven't been warned 4 times. */
                $logger->notice(
                    'Adding warning to user talk page',
                    ['revision_id' => $change['revid'], 'user' => $change['user'], 'level' => $warning_level]
                );
                Metrics::increment('bot_warnings_issued_total', [(string)$warning_level]);
                self::warn($change, $report, $tpcontent, $warning_level);
                Relay::publishWarnedUser($change['user'], $warning_level);
            }
        }
    }

    public static function getWarningLevel($user, &$content = null)
    {
        $warning = 0;
        $content = Api::$q->getpage('User talk:' . $user);
        if (
            $content &&
            preg_match_all(
                '/<!-- Template:(uw-[a-z]*(\d)(im)?|Blatantvandal \(serious warning\)) -->.*' .
                '(\d{2}):(\d{2}), (\d+) ([a-zA-Z]+) (\d{4}) \(UTC\)/iU',
                $content,
                $match,
                PREG_SET_ORDER
            )
        ) {
            foreach ($match as $m) {
                if ($m[1] == 'Blatantvandal (serious warning)') {
                    $m[2] = 4;
                }
                if ((time() - gmmktime($m[4], $m[5], 0, self::$months[$m[7]], $m[6], $m[8])) <= (2 * 24 * 60 * 60)) {
                    if ($m[2] > $warning) {
                        $warning = $m[2];
                    }
                }
            }
        }

        return (int)$warning;
    }

    private static function aiv($change, $report)
    {
        $aivdata = Api::$q->getpage('Wikipedia:Administrator_intervention_against_vandalism/TB2');
        if (!preg_match('/' . preg_quote($change['user'], '/') . '/i', $aivdata)) {
            if (
                Api::$a->edit(
                    'Wikipedia:Administrator_intervention_against_vandalism/TB2',
                    $aivdata . "\n\n" . '* {{' .
                    (filter_var($change['user'], FILTER_VALIDATE_IP) ? 'IPvandal' : 'Vandal') .
                    '|' . $change['user'] . '}}'
                    . ' - ' . $report . ' (Automated) ~~~~' . "\n",
                    'Automatically reporting [[Special:Contributions/' . $change['user'] . ']].' .
                    ' (bot)',
                    false,
                    false
                ) === true
            ) {
                Metrics::set('bot_last_contribution_time', time());
            }
        }
    }

    private static function warn($change, $report, $content, $warning)
    {
        if (
            Api::$a->edit(
                'User talk:' . $change['user'],
                $content . "\n\n"
                . '{{subst:User:' . Config::$user . '/Warnings/Warning'
                . '|1=' . $warning
                . '|2=' . str_replace('File:', ':File:', $change['namespaced_title'])
                . '|3=' . $report
                . ' <!{{subst:ns:0}}-- MySQL ID: ' . $change['mysqlid'] . ' --{{subst:ns:0}}>'
                . '|4=' . $change['mysqlid']
                . '}} ~~~~'
                . "\n",
                'Warning [[Special:Contributions/' . $change['user'] . '|' . $change['user'] . ']] - #' . $warning,
                false,
                false
            ) === true
        ) {
            Metrics::set('bot_last_contribution_time', time());
        }
    }

    public static function doRevert($change, $score)
    {
        global $logger;
        $rev = Api::$a->revisions($change['namespaced_title'], 5, 'older', false, null, true);
        if (empty($rev)) {
            $logger->warning(
                'Skipping revert due to no previous revisions',
                ['revision_id' => $change['revid']]
            );
            Metrics::increment('bot_reverts_skipped_total', ['reason' => 'missing_revisions']);
            Relay::publishEdit($change, $score, false, 'Failed to find previous revision');
            return false;
        }
        $revid = 0;
        $revdata = null;
        foreach ($rev as $revdata) {
            if ($revdata['user'] != $change['user']) {
                $revid = $revdata['revid'];
                break;
            }
        }
        if ($revdata === null) {
            $logger->warning(
                'Skipping revert due to missing previous revision by another user',
                ['revision_id' => $change['revid'], 'candidate_revisions' => count($revdata)],
            );
            Metrics::increment('bot_reverts_skipped_total', ['reason' => 'previous_revisions_by_user']);
            Relay::publishEdit($change, $score, false, 'Prevision revision by same user');
            return false;
        }
        if ($revdata['user'] == Config::$user) {
            $logger->notice('Skipping revert of own account', ['revision_id' => $change['revid']]);
            Metrics::increment('bot_reverts_skipped_total', ['reason' => 'own_account']);
            Relay::publishEdit($change, $score, false, 'User is self');
            return false;
        }
        if (in_array($revdata['user'], Config::$friends)) {
            $logger->notice('Skipping revert of a friend', ['revision_id' => $change['revid']]);
            Metrics::increment('bot_reverts_skipped_total', ['reason' => 'friends_account']);
            Relay::publishEdit($change, $score, false, 'User is a friend');
            return false;
        }
        if (Config::$dry) {
            $logger->warning('Faking revert due to configuration', ['revision_id' => $change['revid']]);
            return true;
        }
        $ret = Api::$a->rollback(
            $change['namespaced_title'],
            $change['user'],
            'Reverting possible vandalism by [[Special:Contribs/' . $change['user'] . '|' . $change['user'] . ']] ' .
            'to ' . (($revid == 0) ? 'older version' : 'version by ' . $revdata['user']) . '. ' .
            '[[WP:CBFP|Report False Positive?]] ' .
            'Thanks, [[WP:CBNG|' . Config::$user . ']]. (' . $change['mysqlid'] . ') (Bot)',
        );
        if ($ret === true) {
            Metrics::set('bot_last_contribution_time', time());
        }
        return $ret;
    }

    public static function shouldRevert($change)
    {
        $reason = 'Default revert';
        if (!Globals::$run) {
            return [false, 'Run disabled'];
        }
        if ($change['user'] == Config::$user) {
            return [false, 'User is myself'];
        }
        if (!self::findAndParseBots($change)) {
            return [false, 'Exclusion compliance'];
        }
        if ($change['all']['user'] == $change['all']['common']['creator']) {
            return [false, 'User is creator'];
        }
        if ($change['all']['user_edit_count'] > 50) {
            if ($change['all']['user_warns'] / $change['all']['user_edit_count'] < 0.1) {
                return [false, 'User has edit count'];
            } else {
                $reason = 'User has edit count, but warns > 10%';
            }
        }
        if (Globals::$tfa == $change['namespaced_title']) {
            return [true, 'Angry-reverting on TFA'];
        }
        if (
            preg_match(
                '/\* \[\[(' . preg_quote($change['namespaced_title'], '/') . ')\]\] \- .*/i',
                Globals::$aoptin
            )
        ) {
            return [true, 'Angry-reverting on angry-optin'];
        }

        $last_revert_time = KeyValueStore::getLastRevertTime($change['namespaced_title'], $change['user']);
        if (!$last_revert_time or (time() - $last_revert_time) > (24 * 60 * 60)) {
            KeyValueStore::saveRevertTime($change['namespaced_title'], $change['user']);
            return [true, $reason];
        }

        return [false, 'Reverted before'];
    }

    public static function findAndParseBots($change)
    {
        $text = $change['all']['current']['text'];
        if (stripos($text, '{{nobots}}') !== false) {
            return false;
        }
        $botname = preg_quote(Config::$user, '/');
        $botname = str_replace(' ', '(_| )?', $botname);
        if (preg_match('/\{\{bots\s*\|\s*deny\s*\=[^}]*(' . $botname . '|\*)[^}]*\}\}/i', $text)) {
            return false;
        }
        if (preg_match('/\{\{bots\s*\|\s*allow\s*\=([^}]*)\}\}/i', $text, $matches)) {
            if (!preg_match('/(' . $botname . '|\*)/i', $matches[1])) {
                return false;
            }
        }

        return true;
    }

    public static function isWhitelistedUser($user)
    {
        return in_array($user, Globals::$wl, true);
    }

    public static function isWhitelistedBot($user)
    {
        return in_array($user, Config::$bot_whitelist, true);
    }
}
