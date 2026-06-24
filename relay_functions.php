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

class Relay
{
    public static function publish($change, $score = null, $reverted = false, $comment = null)
    {
        self::send(json_encode([
            'change' => [
                'namespace' => $change['namespace'],
                'title' => $change['title'],
                'revision_id' => $change['revid'],
                'flags' => $change['flags'],
                'user' => $change['user'],
                'length' => $change['length'],
                'comment' => $change['comment'],
                'url' => $change['url'],
            ],
            'score' => $score,
            'reverted' => $reverted,
            'comment' => $comment,
        ]));
    }

    private static function send($payload)
    {
        global $logger;
        $url = 'http://' . Config::$relay_host . ':' . Config::$relay_port;
        $logger->info('Sending to ' . $url . ': ' . $payload);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
        curl_exec($ch);
    }
}
