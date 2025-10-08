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

class IRC
{
    public static function split($message)
    {
        if (!$message) {
            return;
        }
        $return = array();
        $i = 0;
        $quotes = false;
        if ($message[$i] == ':') {
            $return['type'] = 'relayed';
            ++$i;
        } else {
            $return['type'] = 'direct';
        }
        $return['rawpieces'] = array();
        $temp = '';
        for (; $i < strlen($message); ++$i) {
            if ($quotes and $message[$i] != '"') {
                $temp .= $message[$i];
            } else {
                switch ($message[$i]) {
                    case ' ':
                        $return['rawpieces'][] = $temp;
                        $temp = '';
                        break;
                    case '"':
                        if ($quotes or $temp == '') {
                            $quotes = !$quotes;
                            break;
                        }
                    // Ignore
                    case ':':
                        if ($temp == '') {
                            ++$i;
                            $return['rawpieces'][] = substr($message, $i);
                            $i = strlen($message);
                            break;
                        }
                    // Ignore
                    default:
                        $temp .= $message[$i];
                }
            }
        }
        if ($temp != '') {
            $return['rawpieces'][] = $temp;
        }
        if ($return['type'] == 'relayed') {
            $return['source'] = $return['rawpieces'][0];
            $return['command'] = strtolower($return['rawpieces'][1]);
            $return['target'] = $return['rawpieces'][2];
            $return['pieces'] = array_slice($return['rawpieces'], 3);
        } else {
            $return['source'] = 'Server';
            $return['command'] = strtolower($return['rawpieces'][0]);
            $return['target'] = 'You';
            $return['pieces'] = array_slice($return['rawpieces'], 1);
        }
        $return['raw'] = $message;

        return $return;
    }

    public static function spam($message)
    {
        if (Config::$relay_enable_spam) {
            return self::message('#wikipedia-en-cbngfeed', $message);
        }
    }

    public static function revert($message)
    {
        if (Config::$relay_enable_revert) {
            return self::message('#wikipedia-en-cbngrevertfeed', $message);
        }
    }

    private static function message($channel, $message)
    {
        global $logger;
        if (Config::$relay_use_http) {
            $url = 'http://' . Config::$relay_host . ':' . Config::$relay_port;
            $payload = json_encode(["channel" => $channel, "string" => $message]);
            $logger->addInfo('Sending to ' . $url . ': ' . $payload);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_TIMEOUT, 1);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 1);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
            curl_exec($ch);
            curl_close($ch);
        } else {
            $logger->addInfo('Saying to ' . $channel . ': ' . $message);
            $udp = fsockopen('udp://' . Config::$relay_host, Config::$relay_port);
            if ($udp !== false) {
                fwrite($udp, $channel . ':' . $message);
                fclose($udp);
            }
        }
    }
}
