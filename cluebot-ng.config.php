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

class Config
{
    public static $user = 'ClueBot NG';
    public static $pass = ""; // Read from disk
    public static $status = 'auto';
    public static $angry = false;
    public static $owner = 'Cobi';
    public static $friends = ['ClueBot', 'DASHBotAV'];
    public static $mw_mysql_host = 'enwiki.labsdb';
    public static $mw_mysql_port = 3306;
    public static $mw_mysql_user = '';
    public static $mw_mysql_pass = '';
    public static $mw_mysql_credentials = array();
    public static $mw_mysql_db = 'enwiki_p';
    public static $cb_mysql_host = 'tools-db';
    public static $cb_mysql_port = 3306;
    public static $cb_mysql_user = '';
    public static $cb_mysql_pass = '';
    public static $cb_mysql_db = 's52585__cb';
    public static $core_host = 'core';
    public static $core_port = 3565;
    public static $relay_host = 'irc-relay';
    public static $relay_port = 3334;
    public static $fork = true;
    public static $dry = false;
    public static $metrics_endpoint = null;

    public static function init()
    {
        if ($bot_password = getenv('CBNG_BOT_PASSWORD')) {
            self::$pass = $bot_password;
        } else {
            self::$pass = trim(file_get_contents(getenv('HOME') . '/.cluebotng.bot.password'));
        }

        self::$mw_mysql_user = getenv('TOOL_REPLICA_USER');
        self::$mw_mysql_pass = getenv('TOOL_REPLICA_PASSWORD');
        self::$cb_mysql_user = getenv('TOOL_TOOLSDB_USER');
        self::$cb_mysql_pass = getenv('TOOL_TOOLSDB_PASSWORD');

        if ($mysql_credentials = getenv('CBNG_BOT_MYSQL_CREDENTIALS')) {
            self::$mw_mysql_credentials = json_decode($mysql_credentials, true);
        }
    }
}
