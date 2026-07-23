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

    public static function init($seedMetrics = true): void
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
            'bot_edits_whitelisted_user_total',
            'Total edits skipped because the user is whitelisted',
            []
        );
        self::registerCounter(
            'bot_edits_whitelisted_bot_total',
            'Total edits skipped because the bot is whitelisted',
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
            'bot_reverts_skipped_total',
            'Total reverts skipped',
            ['reason']
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
        self::registerGauge(
            'bot_run_enabled',
            'Whether the bot run flag is currently enabled (1) or disabled (0)',
            []
        );
        self::registerGauge(
            'bot_last_contribution_time',
            'Unix timestamp of the last bot contribution',
            []
        );
        self::registerGauge(
            'bot_start_time_seconds',
            'Unix timestamp of the bot process start time',
            []
        );
        self::registerGauge(
            'bot_forks_total',
            'Current number of active forked child processes',
            []
        );

        // Histograms
        self::registerHistogram(
            'bot_edit_score',
            'Distribution of ANN vandalism scores per edit',
            [],
            [0.05, 0.10, 0.15, 0.20, 0.25, 0.30, 0.35, 0.40, 0.45, 0.50,
             0.55, 0.60, 0.65, 0.70, 0.75, 0.80, 0.85, 0.90, 0.95, 1.00]
        );

        if ($seedMetrics) {
            self::seedMetricsStore();
        }
    }

    private static function seedMetricsStore(): void
    {
        global $logger;
        if (!Config::$metrics_enabled) {
            return;
        }
        try {
            @self::registry()->wipeStorage();
        } catch (\Throwable $e) {
            self::$registry = null;
            $logger->debug('Failed to wipe metrics storage: ' . $e->getMessage());
        }
        foreach (self::$definitions as $metric_name => $definition) {
            if (!empty($definition['labels'])) {
                continue;
            }
            try {
                if ($definition['type'] === 'counter') {
                    @self::registry()
                        ->getOrRegisterCounter('cbng', $metric_name, $definition['help'], $definition['labels'])
                        ->incBy(0, []);
                } elseif ($definition['type'] === 'gauge') {
                    @self::registry()
                        ->getOrRegisterGauge('cbng', $metric_name, $definition['help'], $definition['labels'])
                        ->set(0, []);
                }
            } catch (\Throwable $e) {
                self::$registry = null;
                $logger->debug('Failed to seed ' . $metric_name . ': ' . $e->getMessage());
            }
        }
    }

    private static function registerCounter(string $name, string $help, array $labelNames): void
    {
        self::$definitions[$name] = ['type' => 'counter', 'help' => $help, 'labels' => $labelNames];
    }

    private static function registerGauge(string $name, string $help, array $labelNames): void
    {
        self::$definitions[$name] = ['type' => 'gauge', 'help' => $help, 'labels' => $labelNames];
    }

    private static function registerHistogram(string $name, string $help, array $labelNames, array $buckets): void
    {
        self::$definitions[$name] = [
            'type' => 'histogram',
            'help' => $help,
            'labels' => $labelNames,
            'buckets' => $buckets
        ];
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
        $definition = self::$definitions[$name] ?? null;
        if ($definition === null) {
            $logger->warning("Unknown metric: $name");
            return;
        }
        try {
            @self::registry()
                ->getOrRegisterCounter('cbng', $name, $definition['help'], $definition['labels'])
                ->inc($labelValues);
        } catch (\Throwable $e) {
            self::$registry = null;
            $logger->debug('Failed to increment metric: ' . $e->getMessage());
        }
    }

    public static function set(string $name, float $value, array $labelValues = []): void
    {
        global $logger;
        if (!Config::$metrics_enabled) {
            return;
        }
        $definition = self::$definitions[$name] ?? null;
        if ($definition === null) {
            $logger->warning("Unknown metric: $name");
            return;
        }
        try {
            @self::registry()
                ->getOrRegisterGauge('cbng', $name, $definition['help'], $definition['labels'])
                ->set($value, $labelValues);
        } catch (\Throwable $e) {
            self::$registry = null;
            $logger->debug('Failed to set metric: ' . $e->getMessage());
        }
    }

    public static function observe(string $name, float $value, array $labelValues = []): void
    {
        global $logger;
        if (!Config::$metrics_enabled) {
            return;
        }
        $definition = self::$definitions[$name] ?? null;
        if ($definition === null) {
            $logger->warning("Unknown metric: $name");
            return;
        }
        try {
            @self::registry()
                ->getOrRegisterHistogram(
                    'cbng',
                    $name,
                    $definition['help'],
                    $definition['labels'],
                    $definition['buckets']
                )
                ->observe($value, $labelValues);
        } catch (\Throwable $e) {
            self::$registry = null;
            $logger->debug('Failed to observe metric: ' . $e->getMessage());
        }
    }
}
