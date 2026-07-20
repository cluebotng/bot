<?php

namespace CluebotNG;

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

class HttpFeed
{
    private static $buffer = '';
    private static $lastEventId = null;
    private static $queue = [];
    private static $running = true;
    private static $lastCheckpointTime = 0;

    public static function stream()
    {
        global $logger;

        self::$lastEventId = KeyValueStore::getLastHttpEventId();
        if (self::$lastEventId !== null) {
            $logger->info("Using last event id: " . self::$lastEventId);
        }

        $recentAttempts = 0;
        $server_enforced_timeout = false;
        while (self::$running) {
            $recentAttempts++;
            [$uptime, $server_enforced_timeout] = self::connect($server_enforced_timeout);
            if (!self::$running) {
                break;
            }
            if ($uptime > 60) {
                $recentAttempts = 0;
            }
            $backoff = min($recentAttempts * 2, 120);
            $logger->info("EventStream disconnected, reconnecting in {$backoff}s");
            sleep($backoff);
        }

        $logger->info('EventStream stopped');
    }

    public static function shutdown()
    {
        global $logger;

        if (!self::$running) {
            return;
        }

        $logger->info('HttpFeed shutting down, no longer processing new events');
        self::$running = false;

        if (self::$lastEventId !== null) {
            KeyValueStore::saveLastHttpEventId(self::$lastEventId);
            $logger->info('Persisted last event id on shutdown: ' . self::$lastEventId);
        }
    }

    private static function connect($quiet = false)
    {
        global $logger;

        $headers = ['Accept: text/event-stream'];
        if (self::$lastEventId !== null) {
            $headers[] = 'Last-Event-ID: ' . self::$lastEventId;
        }

        $ch = curl_init('https://stream.wikimedia.org/v2/stream/mediawiki.recentchange');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_WRITEFUNCTION  => [self::class, 'processChunk'],
            CURLOPT_TIMEOUT        => 0,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TCP_KEEPALIVE  => 1,
            CURLOPT_BUFFERSIZE     => 128,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT      => 'ClueBot/2.0',
        ]);

        $mh = curl_multi_init();
        curl_multi_add_handle($mh, $ch);

        $log_message = 'Connecting to EventStream';
        $quiet ? $logger->debug($log_message) : $logger->info($log_message);
        $start_time = time();

        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 0.1);

            while ($event = array_shift(self::$queue)) {
                self::process($event);
            }

            if (self::$lastEventId !== null && (time() - self::$lastCheckpointTime) >= 300) {
                KeyValueStore::saveLastHttpEventId(self::$lastEventId);
                self::$lastCheckpointTime = time();
            }
            refreshDataTick();
        } while ($running > 0 && self::$running);

        $uptime = time() - $start_time;
        $server_enforced_timeout = false;

        $info = curl_multi_info_read($mh);
        if ($info !== false && $info['result'] !== CURLE_OK) {
            $log_message = 'EventStream hit curl error: ' . curl_strerror($info['result']);
            // CURLE_HTTP2_STREAM - the server will close the connection after 15min.
            // x-ref: https://wikitech.wikimedia.org/wiki/Event_Platform/EventStreams_HTTP_Service
            if ($info['result'] === 92 && $uptime > 720) {
                $logger->debug($log_message, ['curl_result' => $info['result'], 'uptime' => $uptime]);
                $server_enforced_timeout = true;
            } else {
                $logger->error($log_message, ['curl_result' => $info['result'], 'uptime' => $uptime]);
            }
        }

        curl_multi_remove_handle($mh, $ch);
        curl_multi_close($mh);

        return [$uptime, $server_enforced_timeout];
    }

    private static function processChunk($ch, $chunk)
    {
        self::$buffer .= $chunk;

        while (($pos = strpos(self::$buffer, "\n")) !== false) {
            $line = substr(self::$buffer, 0, $pos);
            self::$buffer = substr(self::$buffer, $pos + 1);

            if (str_starts_with($line, 'id:')) {
                self::$lastEventId = trim(substr($line, 3));
            } elseif (str_starts_with($line, 'data:')) {
                if ($event = json_decode(trim(substr($line, 5)), true)) {
                    if (($event['server_name'] ?? null) === 'en.wikipedia.org') {
                        self::$queue[] = $event;
                    }
                }
            }
        }

        return strlen($chunk);
    }

    private static function process($event)
    {
        global $logger;

        Metrics::increment('bot_stream_events_received_total', [$event['type'] ?? 'unknown']);

        // Skip these types, they don't directly have revisions, no point spending time constructing
        // an artificial url to throw them away in the namespace check later.
        if ($event['type'] === 'log') {
            $logger->debug('Skipping due to event type: ' . print_r($event, true));
            Metrics::increment('bot_stream_events_skipped_total', ['event_type_log']);
            return;
        }

        // Make the namespace name consistent
        if ($event['namespace'] === 0) {
            $namespace = 'Main:';
            $title = $event['title'];
        } else {
            $colonPos = strpos($event['title'], ':');
            $namespace = substr($event['title'], 0, $colonPos + 1);
            $title = substr($event['title'], $colonPos + 1);
        }

        // Build an array of flags compatible with what we parse from IRC
        // https://github.com/wikimedia/mediawiki/blob/1.38.0/includes/rcfeed/IRCColourfulRCFeedFormatter.php#L111
        $flags = [];
        if (empty($event['patrolled'])) {
            $flags[] = '!';
        }
        if ($event['type'] === 'new') {
            $flags[] = 'N';
        }
        if (!empty($event['minor'])) {
            $flags[] = 'M';
        }
        if (!empty($event['bot'])) {
            $flags[] = 'B';
        }

        // Sometimes we have the 'formatted' length and sometimes the raw number,
        // convert the raw numbers to match the formatted length
        if (array_key_exists('length', $event) && is_string($event['length'])) {
            $length = $event['length'];
        } else {
            $lengthDiff = ($event['length']['new'] ?? 0) - ($event['length']['old'] ?? 0);
            $length = ($lengthDiff >= 0 ? '+' : '') . $lengthDiff;
        }

        // Different events use different url keys, but they are the same (diff.php)
        $url = $event['notify_url'] ?? $event['url'];

        // Different events structure the revision data differently, handle the 2 structured cases
        // and fallback to parsing the url (as we do in the IRC feed)
        if (array_key_exists('revision', $event)) {
            // Normal edit type changes
            $revid = $event['revision']['new'];
            $old_revid = $event['revision']['old'] ?? 0;
        } elseif (array_key_exists('revid', $event)) {
            // Revert type changes
            $revid = $event['revid'];
            $old_revid = $event['old_revid'] ?? 0;
        } else {
            // Category update type changes
            parse_str(parse_url($url, PHP_URL_QUERY), $parsedUrlParams);
            if (array_key_exists('diff', $parsedUrlParams)) {
                $revid = $parsedUrlParams['diff'];
                $old_revid = $parsedUrlParams['oldid'] ?? 0;
            } else {
                $logger->error('Could not determine revision IDs for event: ' . print_r($event, true));
                Metrics::increment('bot_stream_events_skipped_total', ['no_revision_ids']);
                return;
            }
        }

        // Structured event that we process
        $data = [
            'namespace'   => $namespace,
            'namespaceid' => $event['namespace'],
            'title'       => $title,
            'flags'       => $flags,
            'url'         => $url,
            'revid'       => $revid,
            'old_revid'   => $old_revid,
            'user'        => $event['user'],
            'length'      => $length,
            'comment'     => $event['comment'],
            'timestamp'   => $event['timestamp'],
        ];

        // Send the edit through the normal processing workflow
        Process::processEdit($data);
    }
}
