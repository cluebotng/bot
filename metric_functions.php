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
    private static array $definitions = [];

    public static function init(): void
    {
        // Counters
        self::registerCounter(
            'bot_aiv_reports_total',
            'Total AIV reports filed',
            []
        );
        self::registerCounter(
            'bot_warnings_issued_total',
            'Total warnings issued by level',
            ['level']
        );
        self::registerCounter(
            'bot_stream_events_received_total',
            'Total events received from the MediaWiki EventStream',
            ['event_type']
        );
        self::registerCounter(
            'bot_stream_events_skipped_total',
            'Total events skipped at the stream level',
            ['reason']
        );
        self::registerCounter(
            'bot_edits_received_total',
            'Total edits received for processing',
            []
        );
        self::registerCounter(
            'bot_edits_skipped_namespace_total',
            'Total edits skipped due to namespace',
            ['namespace']
        );
        self::registerCounter(
            'bot_edits_skipped_new_article_total',
            'Total edits skipped because they are new articles',
            []
        );
        self::registerCounter(
            'bot_forks_started_total',
            'Total child processes forked',
            []
        );
        self::registerCounter(
            'bot_forks_finished_total',
            'Total child processes that completed successfully',
            []
        );
        self::registerCounter(
            'bot_edits_skipped_missing_revision_data_total',
            'Total edits skipped due to failed revision data fetch',
            []
        );
        self::registerCounter(
            'bot_edits_skipped_missing_cb_data_total',
            'Total edits skipped due to failed cluebot data fetch',
            []
        );
        self::registerCounter(
            'bot_edits_skipped_missing_data_total',
            'Total edits skipped due to missing edit data',
            []
        );
        self::registerCounter(
            'bot_edits_below_threshold_total',
            'Total edits below the vandalism score threshold',
            []
        );
        self::registerCounter(
            'bot_edits_whitelisted_total',
            'Total edits skipped because the user is whitelisted',
            []
        );
        self::registerCounter(
            'bot_edits_vandalism_detected_total',
            'Total edits flagged as potential vandalism above threshold',
            []
        );
        self::registerCounter(
            'bot_revert_decisions_total',
            'Total revert decisions made',
            ['decision', 'reason']
        );
        self::registerCounter(
            'bot_reverts_attempted_total',
            'Total revert attempts',
            []
        );
        self::registerCounter(
            'bot_reverts_succeeded_total',
            'Total successful reverts',
            []
        );
        self::registerCounter(
            'bot_reverts_beaten_total',
            'Total reverts beaten by another editor',
            []
        );
        self::registerCounter(
            'bot_mysql_mw_query_failures_total',
            'Total replica MySQL query failures',
            ['query', 'reason']
        );
        self::registerCounter(
            'bot_mysql_mw_credential_conn_limit_total',
            'Total times a replica MySQL credential hit its connection limit',
            ['user']
        );
        self::registerCounter(
            'bot_mysql_mw_connection_retries_total',
            'Total replica MySQL connection retries',
            []
        );
        self::registerCounter(
            'bot_mysql_mw_credentials_exhausted_total',
            'Total times all replica MySQL credentials were exhausted',
            []
        );
        self::registerCounter(
            'bot_mysql_cb_connection_failures_total',
            'Total ClueBot MySQL connection failures',
            []
        );
        self::registerCounter(
            'bot_mysql_cb_query_failures_total',
            'Total ClueBot MySQL query failures',
            ['query']
        );
        self::registerCounter(
            'bot_redis_operation_failures_total',
            'Total Redis operation failures',
            ['operation']
        );

        // Gauges
        self::registerGauge(
            'bot_tfa_last_reload_seconds',
            'Unix timestamp of the last TFA page reload',
            []
        );
        self::registerGauge(
            'bot_whitelist_last_reload_seconds',
            'Unix timestamp of the last successful huggle whitelist reload',
            []
        );
        self::registerGauge(
            'bot_whitelist_entries',
            'Current number of entries in the huggle whitelist',
            []
        );
    }

    private static function registerCounter(string $name, string $help, array $labelNames): void
    {
        self::$definitions[$name] = ['type' => 'counter', 'help' => $help, 'labels' => $labelNames];
    }

    private static function registerGauge(string $name, string $help, array $labelNames): void
    {
        self::$definitions[$name] = ['type' => 'gauge', 'help' => $help, 'labels' => $labelNames];
    }

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

    public static function increment(string $name, array $labelValues = []): void
    {
        global $logger;
        if (!Config::$metrics_enabled) {
            return;
        }
        $def = self::$definitions[$name] ?? null;
        if ($def === null) {
            $logger->warning("Unknown metric: $name");
            return;
        }
        try {
            self::registry()
                ->getOrRegisterCounter('cbng', $name, $def['help'], $def['labels'])
                ->inc($labelValues);
        } catch (\Throwable $e) {
            self::$registry = null;
            $logger->warning('Failed to increment metric: ' . $e->getMessage());
        }
    }

    public static function set(string $name, float $value, array $labelValues = []): void
    {
        global $logger;
        if (!Config::$metrics_enabled) {
            return;
        }
        $def = self::$definitions[$name] ?? null;
        if ($def === null) {
            $logger->warning("Unknown metric: $name");
            return;
        }
        try {
            self::registry()
                ->getOrRegisterGauge('cbng', $name, $def['help'], $def['labels'])
                ->set($value, $labelValues);
        } catch (\Throwable $e) {
            self::$registry = null;
            $logger->warning('Failed to set metric: ' . $e->getMessage());
        }
    }
}
