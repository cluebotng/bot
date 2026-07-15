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
    // Returns the edit id for the vandalism
    public static function detectedVandalism($user, $title, $heuristic, $reason, $url, $old_rev_id, $rev_id)
    {
        global $logger;
        checkMySQL();
        $query = 'INSERT INTO `vandalism` ' .
            '(`id`,`user`,`article`,`heuristic`,`reason`,`diff`,`old_id`,`new_id`,`reverted`) ' .
            'VALUES ' .
            '(NULL,\'' . mysqli_real_escape_string(Globals::$cb_mysql, $user) . '\',' .
            '\'' . mysqli_real_escape_string(Globals::$cb_mysql, $title) . '\',' .
            '\'' . mysqli_real_escape_string(Globals::$cb_mysql, $heuristic) . '\',' .
            '\'' . mysqli_real_escape_string(Globals::$cb_mysql, $reason) . '\',' .
            '\'' . mysqli_real_escape_string(Globals::$cb_mysql, $url) . '\',' .
            '\'' . mysqli_real_escape_string(Globals::$cb_mysql, $old_rev_id) . '\',' .
            '\'' . mysqli_real_escape_string(Globals::$cb_mysql, $rev_id) . '\',0)';
        try {
            if (!mysqli_query(Globals::$cb_mysql, $query)) {
                Metrics::increment('bot_mysql_cb_query_failures_total', ['vandalism_insert']);
            }
        } catch (mysqli_sql_exception $e) {
            $logger->error("vandalism insert query returned an error for " . $title . ": " . $e->getMessage());
            Metrics::increment('bot_mysql_cb_query_failures_total', ['vandalism_insert']);
        }

        return mysqli_insert_id(Globals::$cb_mysql);
    }

    // Returns nothing
    public static function vandalismReverted($edit_id)
    {
        global $logger;
        checkMySQL();
        try {
            if (
                !mysqli_query(
                    Globals::$cb_mysql,
                    'UPDATE `vandalism` SET `reverted` = 1 WHERE `id` = \'' .
                    mysqli_real_escape_string(Globals::$cb_mysql, $edit_id) . '\''
                )
            ) {
                Metrics::increment('bot_mysql_cb_query_failures_total', ['vandalism_update_reverted']);
            }
        } catch (mysqli_sql_exception $e) {
            $logger->error("vandalism update (reverted) query returned an error for " . $edit_id .
                              ": " . $e->getMessage());
            Metrics::increment('bot_mysql_cb_query_failures_total', ['vandalism_update_reverted']);
        }
    }

    // Returns nothing
    public static function vandalismRevertBeaten($edit_id, $title, $user, $diff)
    {
        global $logger;
        checkMySQL();
        try {
            if (
                !mysqli_query(
                    Globals::$cb_mysql,
                    'UPDATE `vandalism` SET `reverted` = 0 WHERE `id` = \'' .
                    mysqli_real_escape_string(Globals::$cb_mysql, $edit_id) . '\''
                )
            ) {
                Metrics::increment('bot_mysql_cb_query_failures_total', ['vandalism_update_beaten']);
            }
        } catch (mysqli_sql_exception $e) {
            $logger->error("vandalism update (beaten) query returned an error for " . $edit_id .
                              ": " . $e->getMessage());
            Metrics::increment('bot_mysql_cb_query_failures_total', ['vandalism_update_beaten']);
        }
        try {
            if (
                !mysqli_query(
                    Globals::$cb_mysql,
                    'INSERT INTO `beaten` (`id`,`article`,`diff`,`user`) VALUES (NULL,\'' .
                    mysqli_real_escape_string(Globals::$cb_mysql, $title) . '\',\'' .
                    mysqli_real_escape_string(Globals::$cb_mysql, $diff) . '\',\'' .
                    mysqli_real_escape_string(Globals::$cb_mysql, $user) . '\')'
                )
            ) {
                Metrics::increment('bot_mysql_cb_query_failures_total', ['beaten_insert']);
            }
        } catch (mysqli_sql_exception $e) {
            $logger->error("beaten insert query returned an error for " . $title . ": " . $e->getMessage());
            Metrics::increment('bot_mysql_cb_query_failures_total', ['beaten_insert']);
        }
    }
}
