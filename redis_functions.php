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
    private static $client = null;

    private static function connect()
    {
        global $logger;
        try {
            if (self::$client === null || !self::$client->ping()) {
                $redis = new \Redis();
                $redis->pconnect(Config::$cb_redis_host, Config::$cb_redis_port, 1);
                $redis->auth(Config::$cb_redis_pass);
                $redis->select(Config::$cb_redis_db);
                self::$client = $redis;
            }
        } catch (\RedisException $e) {
            $logger->warning('Redis connection failed: ' . $e->getMessage());
            self::$client = null;
        }
    }

    private static function executeGet($key)
    {
        global $logger;
        self::connect();
        if (self::$client !== null) {
            try {
                return self::$client->get($key);
            } catch (\RedisException $e) {
                $logger->warning('Redis get operation failed: ' . $e->getMessage());
            }
        }
        return null;
    }

    private static function executeSet($key, $value, $ttl)
    {
        global $logger;
        self::connect();
        if (self::$client !== null) {
            try {
                return self::$client->set($key, $value, $ttl);
            } catch (\RedisException $e) {
                $logger->warning('Redis set operation failed: ' . $e->getMessage());
            }
        }
    }

    public static function getLastRevertTime($page_title, $user)
    {
        $key = 'cbng:last_reverted:' . hash('sha256', $page_title . ':' . $user);
        $value = self::executeGet($key);
        return ($value !== false && $value !== null) ? (int) $value : null;
    }

    public static function saveRevertTime($page_title, $user)
    {
        $key = 'cbng:last_reverted:' . hash('sha256', $page_title . ':' . $user);
        self::executeSet($key, time(), 24 * 60 * 60);
    }

    public static function getLastHttpEventId()
    {
        $value = self::executeGet('cbng:http_feed_last_id');
        return ($value !== false) ? $value : null;
    }

    public static function saveLastHttpEventId($id)
    {
        self::executeSet('cbng:http_feed_last_id', $id, 10 * 60);
    }
}
