<?php

// phpcs:disable Generic.Files.LineLength

namespace CluebotNG\Tests;

/*
 * In-memory stand-in for Api::$h, Api::$a and Api::$q.
 *
 * - `get` answers action=parse (pre-save transform) using real API responses captured in tests/fixtures,
 *   with the level 1 header rewritten to the current month.
 * - `edit` stores the page, applying the parts of the save-time pre-save transform we rely on
 *   (`~~~~` and, for the parse failure fallback, `{{subst:.../Warnings/Warning|1=N...}}`).
 * - `getpage` returns the stored page.
 */
class FakeWiki
{
    public $apiurl = 'https://en.wikipedia.org/w/api.php';
    public array $pages = [];
    public array $gets = [];
    public array $edits = [];
    public bool $parseFails = false;
    public bool $editResult = true;
    private static ?int $now = null;

    // Fixed for the whole run, so a minute/month rollover cannot make expectations differ
    public static function now(): int
    {
        return self::$now ??= time();
    }

    public static function fixture(int $level): string
    {
        $data = json_decode(file_get_contents(__DIR__ . '/fixtures/warning-level' . $level . '.json'), true);
        return preg_replace('/^== \w+ \d{4} ==/', '== ' . gmdate('F Y', self::now()) . ' ==', $data['parse']['text']['*']);
    }

    public static function signature(): string
    {
        return '[[User:ClueBot NG|ClueBot NG]] ([[User talk:ClueBot NG|talk]]) ' . gmdate('H:i, j F Y', self::now()) . ' (UTC)';
    }

    public function get($url)
    {
        $this->gets[] = $url;
        if ($this->parseFails) {
            return '<html>upstream error</html>';
        }
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        if (!preg_match('/\|1=(\d)\|/', $query['text'], $m)) {
            return json_encode(['error' => ['code' => 'unexpected']]);
        }
        return json_encode(['parse' => ['title' => $query['title'], 'text' => ['*' => self::fixture((int)$m[1])]]]);
    }

    public function unserialize($response)
    {
        $response = json_decode($response, true);
        return is_array($response) ? $response : [];
    }

    public function getpage($page)
    {
        return $this->pages[$page] ?? null;
    }

    public function edit($page, $data, $summary = '', $minor = false, $bot = true)
    {
        $this->edits[] = compact('page', 'data', 'summary', 'minor', 'bot');
        if ($this->editResult !== true) {
            return false;
        }
        $data = preg_replace_callback(
            '/\{\{subst:User:ClueBot NG\/Warnings\/Warning\|1=(\d)\|.*?\|4=\d+\}\}/s',
            fn($m) => self::fixture((int)$m[1]),
            $data
        );
        $this->pages[$page] = rtrim(str_replace('~~~~', self::signature(), $data));
        return true;
    }
}
