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

class IrcFeed
{
    public static $host = 'irc.wikimedia.org';
    public static $port = 6667;
    public static $channel = '#en.wikipedia';
    private static $fd;

    public static function connectLoop()
    {
        self::$fd = fsockopen(self::$host, self::$port, $feederrno, $feederrstr, 30);
        if (!self::$fd) {
            return;
        }
        $nick = str_replace(' ', '_', Config::$user);
        self::send('USER ' . $nick . ' "1" "1" :ClueBot Wikipedia Bot 2.0.');
        self::send('NICK ' . $nick);
        while (!feof(self::$fd)) {
            $rawline = fgets(self::$fd, 1024);
            $line = str_replace(array("\n", "\r"), '', $rawline);
            self::loop($line);
        }
    }

    public static function send($line)
    {
        fwrite(self::$fd, $line . "\n");
    }

    private static function loop($line)
    {
        global $logger;
        $d = self::split($line);
        if ($d === null) {
            return;
        }
        if ($d['type'] == 'direct') {
            switch ($d['command']) {
                case 'ping':
                    self::send('PONG :' . $d['pieces'][0]);
                    break;
            }
        } else {
            switch ($d['command']) {
                case '376':
                case '422':
                    self::send('JOIN ' . self::$channel);
                    break;
                case 'privmsg':
                    if (strtolower($d['target']) == self::$channel) {
                        $rawmessage = $d['pieces'][0];
                        $message = str_replace("\002", '', $rawmessage);
                        $message = preg_replace('/\003(\d\d?(,\d\d?)?)?/', '', $message);
                        $data = self::parseFeed($message);
                        if ($data === null) {
                            return;
                        }
                        Process::processEdit($data);
                    }
                    break;
            }
        }

        refreshDataTick();
    }

    private static function split($message)
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

    private static function parseFeed($message)
    {
        if (
            preg_match(
                '/^\[\[((Talk|User|Wikipedia|File|MediaWiki|Template|Help|Category' .
                '|Portal|Special|Book|Draft|TimedText|Module|Gadget|Gadget(?: |_)definition|Media)(( |_)talk)?:)?' .
                '([^\x5d]*)\]\] (\S*) (https?:\/\/en\.wikipedia\.org\/w\/index\.php\?diff=(\d*)&oldid=(\d*).*|' .
                'https?:\/\/en\.wikipedia\.org\/wiki\/\S+)? \* ([^*]*) \* (\(([^)]*)\))? (.*)$/S',
                $message,
                $m
            )
        ) {
            return array(
                'namespace' => $m[1] ? $m[1] : 'Main:',
                'namespaceid' => namespace2id($m[1] ? substr($m[1], 0, -1) : 'Main'),
                'title' => $m[5],
                'flags' => str_split($m[6]),
                'url' => $m[7],
                'revid' => $m[8],
                'old_revid' => $m[9],
                'user' => $m[10],
                'length' => $m[12],
                'comment' => $m[13],
                'timestamp' => time(),
            );
        }

        return null;
    }
}
