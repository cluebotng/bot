<?php

/*
 * Copyright (C) 2026 Jacobi Carter and Chris Breneman
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

namespace CluebotNG;

use Prometheus\CollectorRegistry;
use Prometheus\Storage\Redis;

class Metrics
{
    private static ?CollectorRegistry $registry = null;

    private static function registry(): CollectorRegistry
    {
        if (self::$registry === null) {
            self::$registry = new CollectorRegistry(new Redis([
                'host'     => Config::$cb_redis_host,
                'port'     => Config::$cb_redis_port,
                'password' => Config::$cb_redis_pass ?: null,
                'database' => Config::$cb_redis_db,
                'timeout'  => 1,
            ]));
        }
        return self::$registry;
    }

    public static function reset(): void
    {
        self::$registry = null;
    }

    public static function getMetricFamilySamples()
    {
        return self::registry()->getMetricFamilySamples();
    }

    public static function increment(string $name, string $help, array $labelNames = [], array $labelValues = []): void
    {
        global $logger;
        if (Config::$metrics_enabled) {
            try {
                self::registry()
                    ->getOrRegisterCounter('cbng', $name, $help, $labelNames)
                    ->inc($labelValues);
            } catch (\Throwable $e) {
                self::$registry = null;
                $logger->warning('Failed to increment metric: ' . $e->getMessage());
            }
        }
    }

    public static function set(
        string $name,
        string $help,
        float $value,
        array $labelNames = [],
        array $labelValues = []
    ): void {
        global $logger;
        if (Config::$metrics_enabled) {
            try {
                self::registry()
                    ->getOrRegisterGauge('cbng', $name, $help, $labelNames)
                    ->set($value, $labelValues);
            } catch (\Throwable $e) {
                self::$registry = null;
                $logger->warning('Failed to set metric: ' . $e->getMessage());
            }
        }
    }
}
