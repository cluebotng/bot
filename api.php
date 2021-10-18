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

class Api
{
    public static $h;
    public static $a;
    public static $q;
    public static $i;

    public static function init($logger)
    {
        self::$h = new \Wikipedia\Http($logger);
        self::$a = new \Wikipedia\Api(self::$h, $logger);
        self::$q = new \Wikipedia\Query(self::$h, $logger);
        self::$i = new \Wikipedia\Index(self::$h, $logger);
    }
}
