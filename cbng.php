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
function isValidRevisionData($data)
{
    return $data !== null
        and isset($data['revisions'][1]['user'])
        and isset($data['revisions'][0]['timestamp'])
        and isset($data['revisions'][0]['*'])
        and isset($data['revisions'][1]['timestamp'])
        and isset($data['revisions'][1]['*']);
}

function fetchRevisionData($url)
{
    $page = null;
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $ch = curl_init();
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
        $data = json_decode(curl_exec($ch), true);
        $page = isset($data['query']['pages']) ? current($data['query']['pages']) : null;

        if (isValidRevisionData($page)) {
            return $page;
        }

        // Try again after a short wait - hopefully the change has replicated
        sleep(1);
    }

    return $page;
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

    if (!isValidRevisionData($api)) {
        $logger->warning(
            "Failed to get revision info",
            ['revision_id' => $feedData['revid'], 'title' => $feedData['namespaced_title']]
        );
        Metrics::increment('bot_edits_skipped_missing_revision_data_total');
        return null;
    }

    $cutoff_timestamp = $feedData['timestamp'] - (14 * 86400);
    $cb = getCbData(
        $feedData['user'],
        $feedData['namespaceid'],
        $feedData['title'],
        $cutoff_timestamp
    );
    if (
        !(isset($cb['user_edit_count'])
        and isset($cb['user_distinct_pages'])
        and isset($cb['user_warns'])
        and isset($cb['user_reg_time']))
    ) {
        $logger->warning(
            "Failed to get user info",
            [
                'revision_id' => $feedData['revid'],
                'user' => $feedData['user'],
                'namespace_id' => $feedData['namespaceid'],
                'title' => $feedData['title'],
                'cutoff_timestamp' => $cutoff_timestamp,
            ]
        );
        Metrics::increment('bot_edits_skipped_missing_cb_data_total');
        return null;
    }
    if (
        !(isset($cb['common']['page_made_time'])
        and isset($cb['common']['creator'])
        and isset($cb['common']['num_recent_edits'])
        and isset($cb['common']['num_recent_reversions']))
    ) {
        $logger->warning(
            "Failed to get common info",
            [
                'revision_id' => $feedData['revid'],
                'user' => $feedData['user'],
                'namespace_id' => $feedData['namespaceid'],
                'title' => $feedData['title'],
                'cutoff_timestamp' => $cutoff_timestamp,
            ]
        );
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

    Metrics::observe('bot_edit_score', (float)$score);
    return $isVand;
}
