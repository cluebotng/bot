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

class Db
{
    private static function connect()
    {
        $cb_mysql = mysqli_connect(
            'p:' . Config::$cb_mysql_host,
            Config::$cb_mysql_user,
            Config::$cb_mysql_pass,
            Config::$cb_mysql_db,
            Config::$cb_mysql_port
        );
        if (!$cb_mysql) {
            Metrics::increment('bot_mysql_cb_connection_failures_total');
            die('cb mysql error: ' . mysqli_connect_error());
        }
        mysqli_select_db($cb_mysql, Config::$cb_mysql_db);
        return $cb_mysql;
    }

    private static function runQuery($cb_mysql, string $identifier, string $context, string $sql)
    {
        global $logger;
        try {
            return mysqli_query($cb_mysql, $sql);
        } catch (mysqli_sql_exception $e) {
            $logger->error("$identifier query returned an error for $context: " . $e->getMessage());
            Metrics::increment('bot_mysql_cb_query_failures_total', [$identifier]);
            return false;
        }
    }

    // Returns the edit id for the vandalism
    public static function detectedVandalism($user, $title, $heuristic, $reason, $url, $old_rev_id, $rev_id)
    {
        $cb_mysql = self::connect();
        $query = 'INSERT INTO `vandalism` ' .
            '(`id`,`user`,`article`,`heuristic`,`reason`,`diff`,`old_id`,`new_id`,`reverted`) ' .
            'VALUES ' .
            '(NULL,\'' . mysqli_real_escape_string($cb_mysql, $user) . '\',' .
            '\'' . mysqli_real_escape_string($cb_mysql, $title) . '\',' .
            '\'' . mysqli_real_escape_string($cb_mysql, $heuristic) . '\',' .
            '\'' . mysqli_real_escape_string($cb_mysql, $reason) . '\',' .
            '\'' . mysqli_real_escape_string($cb_mysql, $url) . '\',' .
            '\'' . mysqli_real_escape_string($cb_mysql, $old_rev_id) . '\',' .
            '\'' . mysqli_real_escape_string($cb_mysql, $rev_id) . '\',0)';
        self::runQuery($cb_mysql, 'vandalism_insert', $title, $query);

        $edit_id = mysqli_insert_id($cb_mysql);
        mysqli_close($cb_mysql);
        return $edit_id;
    }

    // Returns nothing
    public static function vandalismReverted($edit_id)
    {
        $cb_mysql = self::connect();
        self::runQuery(
            $cb_mysql,
            'vandalism_update_reverted',
            $edit_id,
            'UPDATE `vandalism` SET `reverted` = 1 WHERE `id` = \'' .
            mysqli_real_escape_string($cb_mysql, $edit_id) . '\''
        );
        mysqli_close($cb_mysql);
    }

    // Returns nothing
    public static function vandalismRevertBeaten($edit_id, $title, $user, $diff)
    {
        $cb_mysql = self::connect();
        self::runQuery(
            $cb_mysql,
            'vandalism_update_beaten',
            $edit_id,
            'UPDATE `vandalism` SET `reverted` = 0 WHERE `id` = \'' .
            mysqli_real_escape_string($cb_mysql, $edit_id) . '\''
        );
        self::runQuery(
            $cb_mysql,
            'beaten_insert',
            $title,
            'INSERT INTO `beaten` (`id`,`article`,`diff`,`user`) VALUES (NULL,\'' .
            mysqli_real_escape_string($cb_mysql, $title) . '\',\'' .
            mysqli_real_escape_string($cb_mysql, $diff) . '\',\'' .
            mysqli_real_escape_string($cb_mysql, $user) . '\')'
        );
        mysqli_close($cb_mysql);
    }
}
