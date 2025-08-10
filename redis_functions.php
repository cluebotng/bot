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

class KeyValueStore
{
    public static function checkConnection()
    {
        global $logger;
        if (!Globals::$cb_redis || !Globals::$cb_redis->ping()) {
            try {
                $redis = new \Redis();
                $redis->pconnect(Config::$cb_redis_host, Config::$cb_redis_port);
                $redis->auth(Config::$cb_redis_pass);
                $redis->select(Config::$cb_redis_db);

                Globals::$cb_redis = $redis;
            } catch (\RedisException $e) {
                $logger->addWarning("Redis connection failed: " . $e->getMessage());
            }
        }
    }

    public static function getKey($page_title, $user)
    {
        return hash('sha256', $page_title . ':' . $user);
    }

    public static function getLastRevertTime($page_title, $user)
    {
        self::checkConnection();
        return Globals::$cb_redis->get(self::getKey($page_title, $user));
    }

    public static function saveRevertTime($page_title, $user)
    {
        self::checkConnection();
        return Globals::$cb_redis->set(self::getKey($page_title, $user), time());
    }
}
