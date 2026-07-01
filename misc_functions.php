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
function refreshDataTick()
{
    global $logger;
    if (!Globals::$huggle_wl_reload_time || Globals::$huggle_wl_reload_time + 3600 <= time()) {
        $logger->info('Reloading huggle whitelist on timer');
        Globals::$huggle_wl_reload_time = time();
        loadHuggleWhitelist();
    }

    if (!Globals::$tfas || Globals::$tfas + 3600 <= time()) {
        Globals::$tfas = time();
        Metrics::set('bot_tfa_last_reload_seconds', (float)Globals::$tfas);
        if (
            preg_match(
                '/{{TFAFULL\|([^}]+)}}/iU',
                Api::$q->getpage('Wikipedia:Today\'s featured article/' . date('F j, Y')),
                $tfam
            )
        ) {
            Globals::$tfa = $tfam[1];
        }
    }
}

function loadHuggleWhitelist()
{
    global $logger;
    if (($hgWLRaw = @file_get_contents('https://huggle.bena.rocks/?action=read&wp=en.wikipedia.org')) != null) {
        Globals::$wl = array_slice(explode('|', $hgWLRaw), 0, -1);
        $logger->info('Loaded huggle whitelist (' . count(Globals::$wl) . ')');
        Metrics::set('bot_whitelist_last_reload_seconds', (float)time());
        Metrics::set('bot_whitelist_entries', (float)count(Globals::$wl));
    } else {
        $logger->warning('Failed to load huggle whitelist');
    }
}

function doInit()
{
    global $logger;
    Config::init();
    Metrics::init();

    Api::init($logger);
    if (!Api::$a->login(Config::$user, Config::$pass)) {
        $logger->error('Failed to authenticate');
        die();
    }
    Globals::$atime = time();
    Globals::$tfas = 0;
    Globals::$stdin = fopen('php://stdin', 'r');
    Globals::$run = Api::$q->getpage('User:' . Config::$user . '/Run');
    Globals::$optin = Api::$q->getpage('User:' . Config::$user . '/Optin');
    Globals::$aoptin = Api::$q->getpage('User:' . Config::$user . '/AngryOptin');
    refreshDataTick();
}
