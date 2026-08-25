<?php

namespace CluebotNG;

use mysqli_sql_exception;

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

class ReplicaDb
{
    private static function connect($exclude_users = [])
    {
        global $logger;

        if (count(Config::$mw_mysql_credentials) > 0) {
            $candidate_credentials = array_filter(
                Config::$mw_mysql_credentials,
                fn($cred) => !in_array($cred['user'], $exclude_users, true)
            );
            if (empty($candidate_credentials)) {
                $logger->error("ran out of database credentials");
                Metrics::increment('bot_mysql_mw_credentials_exhausted_total');
                die();
            }
            $selected = $candidate_credentials[array_rand($candidate_credentials)];
            $mw_mysql_user = $selected['user'];
            $mw_mysql_pass = $selected['pass'];
        } elseif (!empty($exclude_users)) {
            $logger->error("ran out of database credentials");
            die();
        } else {
            $mw_mysql_user = Config::$mw_mysql_user;
            $mw_mysql_pass = Config::$mw_mysql_pass;
        }

        try {
            $mw_mysql = mysqli_init();
            $mw_mysql->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
            $mw_mysql->real_connect(
                Config::$mw_mysql_host,
                $mw_mysql_user,
                $mw_mysql_pass,
                Config::$mw_mysql_db,
                Config::$mw_mysql_port
            );
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() == 1226) {
                $logger->debug("ran out of database connections for " . $mw_mysql_user);
                Metrics::increment('bot_mysql_mw_credential_conn_limit_total', [$mw_mysql_user]);
                Metrics::increment('bot_mysql_mw_connection_retries_total');
                usleep(5000);
                $exclude_users[] = $mw_mysql_user;
                return self::connect($exclude_users);
            }
            die('replica mysql error: ' . $e->getMessage());
        }
        if (!$mw_mysql) {
            die('replica mysql error: ' . mysqli_connect_error());
        }

        mysqli_select_db($mw_mysql, Config::$mw_mysql_db);
        return $mw_mysql;
    }

    private static function runQuery($mw_mysql, string $identifier, string $context, string $sql)
    {
        global $logger;
        try {
            $res = mysqli_query($mw_mysql, $sql);
            if ($res !== false) {
                $data = mysqli_fetch_assoc($res);
                if ($data !== null) {
                    Metrics::increment('bot_mysql_mw_query_total', [$identifier]);
                    return $data;
                }
                $logger->warning("$identifier query returned no data for $context");
                Metrics::increment('bot_mysql_mw_query_failures_total', [$identifier, 'no_data']);
                return null;
            }
            $logger->error("$identifier query failed for $context: " . mysqli_error($mw_mysql));
            Metrics::increment('bot_mysql_mw_query_failures_total', [$identifier, 'error']);
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() == 1969) {
                $logger->warning("$identifier query timed out for $context");
                Metrics::increment('bot_mysql_mw_query_failures_total', [$identifier, 'timeout']);
            } else {
                $logger->error("$identifier query returned an error for $context: " . $e->getMessage());
                Metrics::increment('bot_mysql_mw_query_failures_total', [$identifier, 'error']);
            }
        }
        return null;
    }

    private static function parseMwTimestamp(string $timestamp): int
    {
        return \DateTime::createFromFormat('YmdHis', $timestamp, new \DateTimeZone('UTC'))->getTimestamp();
    }

    private static function getPageMetadata($mw_mysql, $nsid, $title)
    {
        $data = self::runQuery(
            $mw_mysql,
            'page_metadata',
            "$title ($nsid)",
            'SET STATEMENT max_statement_time=120 FOR ' .
            'SELECT `rev_timestamp`, `actor_name` FROM `revision`' .
            ' JOIN `actor_revision` ON `actor_id` = `rev_actor`' .
            ' WHERE `rev_id` = (' .
            '  SELECT MIN(`rev_id`) FROM `revision`' .
            '  JOIN `page` ON `page_id` = `rev_page`' .
            '  WHERE' .
            '  `page_namespace` = "' . mysqli_real_escape_string($mw_mysql, $nsid) . '"' .
            '  AND ' .
            '  `page_title` = "' . mysqli_real_escape_string($mw_mysql, $title) . '"' .
            ')'
        );
        if ($data === null) {
            return ['page_made_time' => null, 'creator' => null];
        }
        return [
            'page_made_time' => self::parseMwTimestamp($data['rev_timestamp']),
            'creator' => $data['actor_name'],
        ];
    }

    private static function getPageRecentEdits($mw_mysql, $nsid, $title, $timestamp)
    {
        $data = self::runQuery(
            $mw_mysql,
            'page_recent_edits',
            "$title ($nsid) > $timestamp",
            'SET STATEMENT max_statement_time=120 FOR ' .
            'SELECT COUNT(*) as count FROM `page`' .
            ' JOIN `revision` ON `rev_page` = `page_id`' .
            ' WHERE `page_namespace` = "' .
            mysqli_real_escape_string($mw_mysql, $nsid) .
            '" AND `page_title` = "' .
            mysqli_real_escape_string($mw_mysql, $title) .
            '" AND `rev_timestamp` > "' .
            mysqli_real_escape_string($mw_mysql, gmdate('YmdHis', $timestamp)) . '"'
        );
        return $data !== null ? $data['count'] : null;
    }

    private static function getPageRecentReverts($mw_mysql, $nsid, $title, $timestamp)
    {
        $data = self::runQuery(
            $mw_mysql,
            'page_recent_reverts',
            "$title ($nsid) > $timestamp",
            'SET STATEMENT max_statement_time=120 FOR ' .
            'SELECT COUNT(*) as count FROM `page`' .
            ' JOIN `revision` ON `rev_page` = `page_id`' .
            ' JOIN `comment` ON `rev_comment_id` = `comment_id`' .
            " WHERE `page_namespace` = '" .
            mysqli_real_escape_string($mw_mysql, $nsid) .
            "' AND `page_title` = '" .
            mysqli_real_escape_string($mw_mysql, $title) .
            "' AND `rev_timestamp` > '" .
            mysqli_real_escape_string($mw_mysql, gmdate('YmdHis', $timestamp)) .
            "' AND `comment_text` LIKE 'Revert%'"
        );
        return $data !== null ? $data['count'] : null;
    }

    private static function getUserRegistration($mw_mysql, $user)
    {
        $data = self::runQuery(
            $mw_mysql,
            'user_registration',
            $user,
            'SET STATEMENT max_statement_time=120 FOR ' .
            'SELECT `user_registration`, `user_editcount` FROM `user` WHERE `user_name` = "' .
            mysqli_real_escape_string($mw_mysql, $user) . '"'
        );
        if ($data === null) {
            return ['user_reg_time' => null, 'user_edit_count' => null];
        }
        return [
            'user_reg_time' => $data['user_registration'] ? self::parseMwTimestamp($data['user_registration']) : null,
            'user_edit_count' => $data['user_editcount'],
        ];
    }

    private static function getUserRegistrationViaRevision($mw_mysql, $user)
    {
        $data = self::runQuery(
            $mw_mysql,
            'user_registration_via_revision',
            $user,
            'SET STATEMENT max_statement_time=120 FOR ' .
            'SELECT `rev_timestamp` FROM `revision_userindex` ' .
            ' JOIN `actor_revision` ON `actor_id` = `rev_actor`' .
            ' WHERE `actor_name` = "' .
            mysqli_real_escape_string($mw_mysql, $user) . '" ORDER BY `rev_timestamp` LIMIT 0,1'
        );
        return $data !== null ? self::parseMwTimestamp($data['rev_timestamp']) : null;
    }

    private static function getUserWarningsCount($mw_mysql, $userPage)
    {
        $data = self::runQuery(
            $mw_mysql,
            'user_warnings_count',
            $userPage,
            'SET STATEMENT max_statement_time=120 FOR ' .
            'SELECT COUNT(*) as count FROM `page`' .
            ' JOIN `revision` ON `rev_page` = `page_id`' .
            ' JOIN `comment` ON `rev_comment_id` = `comment_id`' .
            " WHERE `page_namespace` = 3 AND `page_title` = '" .
            mysqli_real_escape_string($mw_mysql, $userPage) .
            "' AND (`comment_text` LIKE '%warning%' OR `comment_text`" .
            " LIKE 'General note: Nonconstructive%')"
        );
        return $data !== null ? $data['count'] : null;
    }

    private static function getUserDistinctPages($mw_mysql, $userPage)
    {
        $data = self::runQuery(
            $mw_mysql,
            'user_distinct_pages',
            $userPage,
            'SET STATEMENT max_statement_time=120 FOR ' .
            'SELECT count(distinct rev_page) AS count FROM' .
            ' `revision_userindex` JOIN `actor_revision` ON `actor_id` = `rev_actor`' .
            " WHERE `actor_name` = '" . mysqli_real_escape_string($mw_mysql, $userPage) . "'"
        );
        return $data !== null ? $data['count'] : null;
    }

    public static function getCurrentReplicaLag()
    {
        global $logger;
        $mw_mysql = self::connect();

        $current_lag = 0;
        try {
            $res = mysqli_query($mw_mysql, 'SELECT lag FROM heartbeat_p.heartbeat WHERE shard = "s1" LIMIT 1');
            if ($res !== false) {
                $row = mysqli_fetch_assoc($res);
                if ($row !== null) {
                    $current_lag = $row['lag'];
                }
            }
        } catch (mysqli_sql_exception $e) {
            $logger->error("replica lag query returned an error: " . $e->getMessage());
        }

        mysqli_close($mw_mysql);
        return $current_lag;
    }

    public static function getCbData($user = '', $nsid = '', $title = '', $timestamp = '')
    {
        $mw_mysql = self::connect();
        $userPage = str_replace(' ', '_', $user);
        $title = str_replace(' ', '_', $title);

        $pageMetadata = self::getPageMetadata($mw_mysql, $nsid, $title);
        if ($pageMetadata['page_made_time'] === null) {
            mysqli_close($mw_mysql);
            return null;
        }

        $userRegistration = self::getUserRegistration($mw_mysql, $user);
        if ($userRegistration['user_reg_time'] === null) {
            $userRegistration['user_reg_time'] = self::getUserRegistrationViaRevision($mw_mysql, $user);
        }

        $data = [
            'common' => [
                'creator' => $pageMetadata['creator'],
                'page_made_time' => $pageMetadata['page_made_time'],
                'num_recent_edits' => self::getPageRecentEdits($mw_mysql, $nsid, $title, $timestamp),
                'num_recent_reversions' => self::getPageRecentReverts($mw_mysql, $nsid, $title, $timestamp),
            ],
            'user_reg_time' => $userRegistration['user_reg_time'],
            'user_warns' => self::getUserWarningsCount($mw_mysql, $userPage),
            'user_edit_count' => $userRegistration['user_edit_count'],
            'user_distinct_pages' => self::getUserDistinctPages($mw_mysql, $userPage),
        ];

        mysqli_close($mw_mysql);
        return $data;
    }
}
