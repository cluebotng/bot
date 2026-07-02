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
function fetchRevisionData($url)
{
    $ch = curl_init();
    if (isset($proxyhost) and isset($proxyport) and $proxyport != null and $proxyhost != null) {
        curl_setopt_array($ch, [
            CURLOPT_PROXYTYPE => isset($proxytype) ? $proxytype : CURLPROXY_HTTP,
            CURLOPT_PROXY => $proxyhost,
            CURLOPT_PROXYPORT => $proxyport,
        ]);
    }
    curl_setopt_array($ch, [
        CURLOPT_USERAGENT      => 'ClueBot/2.0',
        CURLOPT_URL            => $url,
        CURLOPT_FOLLOWLOCATION => 1,
        CURLOPT_MAXREDIRS      => 10,
        CURLOPT_HEADER         => 0,
        CURLOPT_RETURNTRANSFER => 1,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPGET        => 1,
        CURLOPT_FORBID_REUSE   => 1,
        CURLOPT_FRESH_CONNECT  => 1,
        CURLOPT_ENCODING       => '',
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
    ]);
    $result = curl_exec($ch);
    $data = json_decode($result, true);
    if (!isset($data['query']['pages'])) {
        return null;
    }
    return current($data['query']['pages']);
}

function xmlizePart($doc, $key, $data)
{
    $element = $doc->createElement($key);
    if (is_array($data)) {
        foreach ($data as $arrKey => $value) {
            $element->appendChild(xmlizePart($doc, $arrKey, $value));
        }
    } else {
        $element->appendChild($doc->createTextNode($data));
    }

    return $element;
}

function xmlize($data)
{
    $doc = new \DOMDocument('1.0');
    $root = $doc->createElement('WPEditSet');
    $doc->appendChild($root);
    if (isset($data[0])) {
        foreach ($data as $entry) {
            $root->appendChild(xmlizePart($doc, 'WPEdit', $entry));
        }
    } else {
        $root->appendChild(xmlizePart($doc, 'WPEdit', $data));
    }

    return $doc->saveXML();
}

function parseFeedData($feedData)
{
    global $logger;

    $feedData['namespaced_title'] = ($feedData['namespaceid'] == 0 ? '' : $feedData['namespace']) . $feedData['title'];

    $api = fetchRevisionData(
        'https://en.wikipedia.org/w/api.php?action=query&rawcontinue=1&prop=revisions&titles=' .
        urlencode($feedData['namespaced_title']) .
        '&rvstartid=' . $feedData['revid'] . '&rvlimit=2&rvprop=timestamp|user|content&format=json',
    );

    if (
        !(isset($api['revisions'][1]['user'])
        and isset($api['revisions'][0]['timestamp'])
        and isset($api['revisions'][0]['*'])
        and isset($api['revisions'][1]['timestamp'])
        and isset($api['revisions'][1]['*']))
    ) {
        $logger->warning("Failed to get revision info", ['revision_id' => $feedData['revid']]);
        Metrics::increment('bot_edits_skipped_missing_revision_data_total');
        return null;
    }

    $cb = getCbData(
        $feedData['user'],
        $feedData['namespaceid'],
        $feedData['title'],
        $feedData['timestamp'] - (14 * 86400)
    );
    if (
        !(isset($cb['user_edit_count'])
        and isset($cb['user_distinct_pages'])
        and isset($cb['user_warns'])
        and isset($cb['user_reg_time']))
    ) {
        $logger->warning("Failed to get user info", ['revision_id' => $feedData['revid']]);
        Metrics::increment('bot_edits_skipped_missing_cb_data_total');
        return null;
    }
    if (
        !(isset($cb['common']['page_made_time'])
        and isset($cb['common']['creator'])
        and isset($cb['common']['num_recent_edits'])
        and isset($cb['common']['num_recent_reversions']))
    ) {
        $logger->warning("Failed to get common info", ['revision_id' => $feedData['revid']]);
        return null;
    }

    $feedData['all'] = [
        'EditType' => 'change',
        'EditID' => $feedData['revid'],
        'comment' => $feedData['comment'],
        'user' => $feedData['user'],
        'user_edit_count' => $cb['user_edit_count'],
        'user_distinct_pages' => $cb['user_distinct_pages'],
        'user_warns' => $cb['user_warns'],
        'prev_user' => $api['revisions'][1]['user'],
        'user_reg_time' => $cb['user_reg_time'],
        'common' => [
            'page_made_time' => $cb['common']['page_made_time'],
            'title' => $feedData['title'],
            'namespace' => $feedData['namespace'],
            'creator' => $cb['common']['creator'],
            'num_recent_edits' => $cb['common']['num_recent_edits'],
            'num_recent_reversions' => $cb['common']['num_recent_reversions'],
        ],
        'current' => [
            'minor' => (in_array('M', $feedData['flags'])) ? 'true' : 'false',
            'timestamp' => strtotime($api['revisions'][0]['timestamp']),
            'text' => $api['revisions'][0]['*'],
        ],
        'previous' => [
            'timestamp' => strtotime($api['revisions'][1]['timestamp']),
            'text' => $api['revisions'][1]['*'],
        ],
    ];

    return $feedData;
}

function isVandalism($data, &$score)
{
    $fp = fsockopen(Config::$core_host, Config::$core_port, $errno, $errstr, 15);
    if (!$fp) {
        return false;
    }
    fwrite($fp, str_replace('</WPEditSet>', '', xmlize($data)));
    fflush($fp);
    $returnXML = '';
    $endeditset = false;
    while (!feof($fp)) {
        $returnXML .= fgets($fp, 4096);
        if (strpos($returnXML, '</WPEdit>') === false and !$endeditset) {
            fwrite($fp, '</WPEditSet>');
            fflush($fp);
            $endeditset = true;
        }
    }
    fclose($fp);
    $data = simplexml_load_string($returnXML);
    if ($data == null) {
        $score = 0;
        $isVand = false;
    } else {
        $score = (string)$data->WPEdit->score;
        $isVand = ((string)$data->WPEdit->think_vandalism) == 'true';
    }

    return $isVand;
}
