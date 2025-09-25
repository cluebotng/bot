<?php

namespace CluebotNG;

use mysqli_sql_exception;

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
function is_mysql_alive($con)
{
    try {
        return @mysqli_query($con, 'SELECT LAST_INSERT_ID()');
    } catch (mysqli_sql_exception $e) {
        return false;
    }
}

function checkMySQL()
{
    if (!Globals::$cb_mysql || !is_mysql_alive(Globals::$cb_mysql)) {
        Globals::$cb_mysql = mysqli_connect(
            'p:' . Config::$cb_mysql_host,
            Config::$cb_mysql_user,
            Config::$cb_mysql_pass,
            Config::$cb_mysql_db,
            Config::$cb_mysql_port
        );
        if (!Globals::$cb_mysql) {
            die('cb mysql error: ' . mysqli_connect_error());
        }
        mysqli_select_db(Globals::$cb_mysql, Config::$cb_mysql_db);
    }
}

function connect_to_mysql($exclude_credentials = array())
{
    global $logger;
    $mysql_credential_entry = null;
    if (count(Config::$mw_mysql_credentials) > 0) {
        $candidate_credentials = Config::$mw_mysql_credentials;
        foreach ($exclude_credentials as $excluded_credential) {
            unset($candidate_credentials[$excluded_credential]);
        }
        $mysql_credential_entry = rand(0, count($candidate_credentials) - 1);
        $mw_mysql_user = $candidate_credentials[$mysql_credential_entry]['user'];
        $mw_mysql_pass = $candidate_credentials[$mysql_credential_entry]['pass'];
    } elseif (count($exclude_credentials) > 0) {
        $logger->addError("ran out of database credentials");
        die();
    } else {
        $mw_mysql_user = Config::$mw_mysql_user;
        $mw_mysql_pass = Config::$mw_mysql_pass;
    }

    try {
        $mw_mysql = mysqli_init();
        $mw_mysql->options(MYSQLI_OPT_CONNECT_TIMEOUT, 10);
        $mw_mysql->real_connect(
            Config::$mw_mysql_host,
            $mw_mysql_user,
            $mw_mysql_pass,
            Config::$mw_mysql_db,
            Config::$mw_mysql_port
        );
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() == 1226 && $mysql_credential_entry != null) {
            $logger->addWarning("ran out of database connections for " . $mw_mysql_user);
            usleep(10000);
            $exclude_credentials[] = $mysql_credential_entry;
            return connect_to_mysql($exclude_credentials);
        }
    }
    if (!$mw_mysql) {
        die('replica mysql error: ' . mysqli_connect_error());
    }

    mysqli_select_db($mw_mysql, Config::$mw_mysql_db);
    return $mw_mysql;
}

function getCbData($user = '', $nsid = '', $title = '', $timestamp = '')
{
    global $logger;
    $mw_mysql = connect_to_mysql();
    $userPage = str_replace(' ', '_', $user);
    $title = str_replace(' ', '_', $title);
    $data = array(
        'common' => array(
            'creator' => false,
            'page_made_time' => false,
            'num_recent_edits' => false,
            'num_recent_reversions' => false,
        ),
        'user_reg_time' => false,
        'user_warns' => false,
        'user_edit_count' => false,
        'user_distinct_pages' => false,
    );
    try {
        $res = mysqli_query(
            $mw_mysql,
            'SET STATEMENT max_statement_time=30 FOR ' .
            'SELECT `rev_timestamp`, `actor_name` FROM `page`' .
            ' JOIN `revision` ON `rev_page` = `page_id`' .
            ' JOIN `actor` ON `actor_id` = `rev_actor`' .
            ' WHERE `page_namespace` = "' .
            mysqli_real_escape_string($mw_mysql, $nsid) .
            '" AND `page_title` = "' .
            mysqli_real_escape_string($mw_mysql, $title) .
            '" ORDER BY `rev_id` LIMIT 1'
        );
        if ($res === false) {
            $logger->addWarning("page metadata query returned no data for " . $title .
                                " (" . $nsid . "): " . mysqli_error($mw_mysql));
        } else {
            $d = mysqli_fetch_assoc($res);
            $data['common']['page_made_time'] = $d['rev_timestamp'];
            $data['common']['creator'] = $d['actor_name'];
        }
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() == 1969) {
            $logger->addWarning("page metadata query timed out for " . $title . " (" . $nsid . ")");
        } else {
            $logger->addError("page metadata query returned an error for " . $title .
                              " (" . $nsid . "): " . $e->getMessage());
        }
    }

    try {
        $res = mysqli_query(
            $mw_mysql,
            'SET STATEMENT max_statement_time=30 FOR ' .
            'SELECT COUNT(*) as count FROM `page`' .
            ' JOIN `revision` ON `rev_page` = `page_id`' .
            ' WHERE `page_namespace` = "' .
            mysqli_real_escape_string($mw_mysql, $nsid) .
            '" AND `page_title` = "' .
            mysqli_real_escape_string($mw_mysql, $title) .
            '" AND `rev_timestamp` > "' .
            mysqli_real_escape_string($mw_mysql, $timestamp) . '"'
        );

        if ($res === false) {
            $logger->addWarning("page recent edits query returned no data for " . $title .
                                " (" . $nsid . ") > " . $timestamp . ": " . mysqli_error($mw_mysql));
        } else {
            $d = mysqli_fetch_assoc($res);
            $data['common']['num_recent_edits'] = $d['count'];
        }
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() == 1969) {
            $logger->addWarning("page recent edits query timed out for " . $title . " (" . $nsid . ") > " .
                                $timestamp);
        } else {
            $logger->addError("page recent edits query returned an error for " . $title .
                              " (" . $nsid . ") > " . $timestamp . ": " . $e->getMessage());
        }
    }

    try {
        $res = mysqli_query(
            $mw_mysql,
            'SET STATEMENT max_statement_time=30 FOR ' .
            'SELECT COUNT(*) as count FROM `page`' .
            ' JOIN `revision` ON `rev_page` = `page_id`' .
            ' JOIN `comment` ON `rev_comment_id` = `comment_id`' .
            " WHERE `page_namespace` = '" .
            mysqli_real_escape_string($mw_mysql, $nsid) .
            "' AND `page_title` = '" .
            mysqli_real_escape_string($mw_mysql, $title) .
            "' AND `rev_timestamp` > '" .
            mysqli_real_escape_string($mw_mysql, $timestamp) .
            "' AND `comment_text` LIKE 'Revert%'"
        );

        if ($res === false) {
            $logger->addWarning("page recent reverts query returned no data for " . $title .
                                " (" . $nsid . ") > " . $timestamp . ": " . mysqli_error($mw_mysql));
        } else {
            $d = mysqli_fetch_assoc($res);
            $data['common']['num_recent_reversions'] = $d['count'];
        }
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() == 1969) {
            $logger->addWarning("page recent reverts query timed out for " . $title .
                                " (" . $nsid . ") > " . $timestamp);
        } else {
            $logger->addError("page recent reverts query returned an error for " . $title .
                              " (" . $nsid . ") > " . $timestamp . ": " . $e->getMessage());
        }
    }

    if (
        filter_var($user, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ||
        filter_var($user, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)
    ) {
        $data['user_reg_time'] = time();

        try {
            $res = mysqli_query(
                $mw_mysql,
                'SET STATEMENT max_statement_time=30 FOR ' .
                'SELECT COUNT(*) AS `user_editcount` FROM `revision_userindex` ' .
                ' JOIN `actor` ON `actor_id` = `rev_actor`' .
                ' WHERE `actor_name` = "' .
                mysqli_real_escape_string($mw_mysql, $user) . '"'
            );

            if ($res === false) {
                $logger->addWarning("user edit count query returned no data for (invalid ip) " .
                                    $user . ": " . mysqli_error($mw_mysql));
            } else {
                $d = mysqli_fetch_assoc($res);
                $data['user_edit_count'] = $d['user_editcount'];
            }
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() == 1969) {
                $logger->addWarning("user edit count query timed out for " . $user);
            } else {
                $logger->addError("user edit count query returned an error for " . $user . ": " .
                                  $e->getMessage());
            }
        }
    } else {
        try {
            $res = mysqli_query(
                $mw_mysql,
                'SET STATEMENT max_statement_time=30 FOR ' .
                'SELECT `user_registration` FROM `user` WHERE `user_name` = "' .
                mysqli_real_escape_string($mw_mysql, $user) . '"'
            );

            $d = mysqli_fetch_assoc($res);
            if ($res === false) {
                $logger->addWarning("user registration query returned no data for " .
                                    $user . ": " . mysqli_error($mw_mysql));
            } else {
                $data['user_reg_time'] = $d['user_registration'];
            }
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() == 1969) {
                $logger->addWarning("user registration query timed out for " . $user);
            } else {
                $logger->addError("user registration query returned an error for " . $user . ": " .
                                  $e->getMessage());
            }
        }

        if (!$data['user_reg_time']) {
            try {
                $res = mysqli_query(
                    $mw_mysql,
                    'SET STATEMENT max_statement_time=30 FOR ' .
                    'SELECT `rev_timestamp` FROM `revision_userindex` ' .
                    ' JOIN `actor` ON `actor_id` = `rev_actor`' .
                    ' WHERE `actor_name` = "' .
                    mysqli_real_escape_string($mw_mysql, $user) . '" ORDER BY `rev_timestamp` LIMIT 0,1'
                );

                if ($res === false) {
                    $logger->addWarning("user registration via revision query returned no data for " .
                                        $user . ": " . mysqli_error($mw_mysql));
                } else {
                    $d = mysqli_fetch_assoc($res);
                    $data['user_reg_time'] = $d['rev_timestamp'];
                }
            } catch (mysqli_sql_exception $e) {
                if ($e->getCode() == 1969) {
                    $logger->addWarning("user registration via revision query timed out for " . $user);
                } else {
                    $logger->addError("user registration via revision query returned an error for " . $user .
                                      ": " . $e->getMessage());
                }
            }
        }

        try {
            $res = mysqli_query(
                $mw_mysql,
                'SET STATEMENT max_statement_time=30 FOR ' .
                'SELECT `user_editcount` FROM `user` WHERE `user_name` =  "' .
                mysqli_real_escape_string($mw_mysql, $user) . '"'
            );

            if ($res === false) {
                $logger->addWarning("user edit count query returned no data for " .
                                    $user . ": " . mysqli_error($mw_mysql));
            } else {
                $d = mysqli_fetch_assoc($res);
                $data['user_edit_count'] = $d['user_editcount'];
            }
        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() == 1969) {
                $logger->addWarning("user edit count query timed out for " . $user);
            } else {
                $logger->addError("user edit count query returned an error for " . $user . ": " .
                                  $e->getMessage());
            }
        }
    }

    try {
        $res = mysqli_query(
            $mw_mysql,
            'SET STATEMENT max_statement_time=30 FOR ' .
            'SELECT COUNT(*) as count FROM `page`' .
            ' JOIN `revision` ON `rev_page` = `page_id`' .
            ' JOIN `comment` ON `rev_comment_id` = `comment_id`' .
            " WHERE `page_namespace` = 3 AND `page_title` = '" .
            mysqli_real_escape_string($mw_mysql, $userPage) .
            "' AND (`comment_text` LIKE '%warning%' OR `comment_text`" .
            " LIKE 'General note: Nonconstructive%')"
        );

        if ($res === false) {
            $logger->addWarning("user warnings count query returned no data for " .
                                $userPage . ": " . mysqli_error($mw_mysql));
        } else {
            $d = mysqli_fetch_assoc($res);
            $data['user_warns'] = $d['count'];
        }
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() == 1969) {
            $logger->addWarning("user warnings count query timed out for " . $user);
        } else {
            $logger->addError("user warnings count query returned an error for " . $user . ": " .
                              $e->getMessage());
        }
    }

    try {
        $res = mysqli_query(
            $mw_mysql,
            'SET STATEMENT max_statement_time=30 FOR ' .
            'SELECT count(distinct rev_page) AS count FROM' .
            ' `revision_userindex` JOIN `actor` ON `actor_id` = `rev_actor`' .
            " WHERE `actor_name` = '" . mysqli_real_escape_string($mw_mysql, $userPage) . "'"
        );

        if ($res !== false) {
            $d = mysqli_fetch_assoc($res);
            $data['user_distinct_pages'] = $d['count'];
        }
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() == 1969) {
            $logger->addWarning("user distinct page edits query timed out for " . $userPage);
        } else {
            $logger->addError("user distinct page edits query returned an error for " . $userPage .
                              ": " . $e->getMessage());
        }
    }
    if ($data['common']['page_made_time']) {
        $data['common']['page_made_time'] = gmmktime(
            (int)substr($data['common']['page_made_time'], 8, 2),
            (int)substr($data['common']['page_made_time'], 10, 2),
            (int)substr($data['common']['page_made_time'], 12, 2),
            (int)substr($data['common']['page_made_time'], 4, 2),
            (int)substr($data['common']['page_made_time'], 6, 2),
            (int)substr($data['common']['page_made_time'], 0, 4)
        );
    }
    if ($data['user_reg_time']) {
        $data['user_reg_time'] = gmmktime(
            (int)substr($data['user_reg_time'], 8, 2),
            (int)substr($data['user_reg_time'], 10, 2),
            (int)substr($data['user_reg_time'], 12, 2),
            (int)substr($data['user_reg_time'], 4, 2),
            (int)substr($data['user_reg_time'], 6, 2),
            (int)substr($data['user_reg_time'], 0, 4)
        );
    }

    mysqli_close($mw_mysql);
    return $data;
}
