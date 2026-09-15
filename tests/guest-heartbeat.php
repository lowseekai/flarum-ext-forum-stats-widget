<?php

/**
 * Standalone logic tests for the guest presence counter.
 *
 *     php tests/guest-heartbeat.php        # exit 0 = pass, 1 = failure
 *
 * The extension has no PHPUnit setup and deliberately no Composer dev
 * dependencies, so this file stubs the handful of framework interfaces the
 * controller touches and then loads the REAL src/Api/GuestHeartbeatController.php.
 * It exercises the shipped code rather than a reimplementation of it — the
 * point being that the counting rules are easy to break silently and hard to
 * notice in production, where a wrong number still looks like a number.
 *
 * Everything here is pure logic: no network, no database, no cache server.
 */

namespace Psr\Http\Message {
    interface ResponseInterface {}
    interface ServerRequestInterface {
        public function getHeaderLine(string $n): string;
        public function getServerParams(): array;
    }
}

namespace Psr\Http\Server {
    interface RequestHandlerInterface {}
}

namespace Laminas\Diactoros\Response {
    class EmptyResponse implements \Psr\Http\Message\ResponseInterface {
        public function __construct(public int $status = 204) {}
        public function getStatusCode(): int { return $this->status; }
    }
}

namespace Flarum\Settings {
    interface SettingsRepositoryInterface { public function get($k, $d = null); }
}

namespace Illuminate\Contracts\Cache {
    interface Repository {
        public function get($key, $default = null);
        public function put($key, $value, $ttl = null);
    }
}

namespace Test {
    /** In-memory stand-in for the Flarum cache; the map lives in $store. */
    class FakeCache implements \Illuminate\Contracts\Cache\Repository {
        public array $store = [];
        public function get($key, $default = null) { return $this->store[$key] ?? $default; }
        public function put($key, $value, $ttl = null) { $this->store[$key] = $value; return true; }
    }

    class FakeSettings implements \Flarum\Settings\SettingsRepositoryInterface {
        public function __construct(private array $vals = []) {}
        public function get($k, $d = null) { return $this->vals[$k] ?? $d; }
    }

    class FakeRequest implements \Psr\Http\Message\ServerRequestInterface {
        public function __construct(private string $ua, private string $ip) {}
        public function getHeaderLine(string $n): string {
            return strtolower($n) === 'user-agent' ? $this->ua : '';
        }
        public function getServerParams(): array { return ['REMOTE_ADDR' => $this->ip]; }
    }
}

namespace Ekumanov\ForumWidgets\Api {
    require __DIR__ . '/../src/Api/GuestHeartbeatController.php';

    /** Exposes the protected bot check for assertion. */
    class Probe extends GuestHeartbeatController {
        public function isBot(string $ua): bool { return $this->looksLikeBot($ua); }
    }
}

namespace Test {
    use Ekumanov\ForumWidgets\Api\GuestHeartbeatController as C;
    use Ekumanov\ForumWidgets\Api\Probe;

    $pass = 0;
    $fail = 0;

    function check(string $label, $got, $want): void {
        global $pass, $fail;
        $ok = $got === $want;
        $ok ? $pass++ : $fail++;
        printf("  [%s] %s\n", $ok ? 'PASS' : 'FAIL', $label);
        if (! $ok) {
            printf("         got:  %s\n         want: %s\n",
                json_encode($got), json_encode($want));
        }
    }

    /**
     * Mirror of ForumResourceFields::getOnlineGuestsCount(). Kept in step with
     * that method by hand; if the counting rule changes in one, change both.
     */
    function displayedCount(array $guests, int $cutoff): int {
        $n = 0;
        foreach ($guests as $entry) {
            $x = C::normalizeEntry($entry);
            if ($x === null) {
                continue;
            }
            [$ts, $pings] = $x;
            if ($ts > $cutoff && $pings >= C::MIN_PINGS_TO_COUNT) {
                $n++;
            }
        }
        return $n;
    }

    $settings = new FakeSettings(['ekumanov-forum-widgets.last_seen_interval' => 5]);
    $realUA = 'Mozilla/5.0 (X11; Linux x86_64; rv:154.0) Gecko/20100101 Firefox/154.0';

    echo "\n=== 1. User-Agent gate ===\n";
    $probe = new Probe(new FakeCache(), $settings);

    // Real User-Agents observed in live traffic. The Chrome/145 Mac string is
    // the one the scraper fleet wears, and is indistinguishable from a genuine
    // Mac Chrome visitor — it must NOT be filtered. Behaviour catches it later.
    foreach ([
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36',
        'Mozilla/5.0 (X11; Linux x86_64; rv:154.0) Gecko/20100101 Firefox/154.0',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 18_7 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/27.0 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (Linux; Android 10; K) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/30.0 Chrome/143.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0.0.0 Safari/537.36',
    ] as $i => $ua) {
        check("real browser #$i passes the gate", $probe->isBot($ua), false);
    }

    foreach ([
        'pc',                       // observed scripted client; matches no needle
        '',                         // absent
        '   ',                      // whitespace only
        'Mozilla/5.0',              // truncated junk, under the length floor
        'Mozilla/5.0 (compatible; YandexRenderResourcesBot/1.0; +http://yandex.com/bots) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/108.0.0.0',
        'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
        'python-requests/2.31.0',
        'curl/8.4.0',
    ] as $i => $ua) {
        check("bot or short UA #$i is filtered", $probe->isBot($ua), true);
    }

    echo "\n=== 2. normalizeEntry: both map formats ===\n";
    check('legacy bare int timestamp',   C::normalizeEntry(1756700000), [1756700000, 1]);
    check('legacy numeric string',       C::normalizeEntry('1756700000'), [1756700000, 1]);
    check('current [ts, pings]',         C::normalizeEntry([1756700000, 2]), [1756700000, 2]);
    check('ping count floored to 1',     C::normalizeEntry([1756700000, 0]), [1756700000, 1]);
    check('missing ping count defaults', C::normalizeEntry([1756700000]), [1756700000, 1]);
    check('garbage rejected',            C::normalizeEntry('abc'), null);
    check('null rejected',               C::normalizeEntry(null), null);

    echo "\n=== 3. one-shot traffic vs a real visitor ===\n";
    $cache = new FakeCache();
    $ctl = new C($cache, $settings);
    $scraperUA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36';

    // Replays a burst measured in production: 38 distinct residential IPs, one
    // heartbeat each, all wearing a legitimate desktop Chrome UA.
    for ($i = 0; $i < 38; $i++) {
        $ctl->handle(new FakeRequest($scraperUA, "91.180.134.$i"));
    }
    $map = $cache->store[C::CACHE_KEY];
    check('38 one-shot fingerprints are recorded', count($map), 38);
    check('...and none of them are counted', displayedCount($map, time() - 300), 0);

    $ctl->handle(new FakeRequest($realUA, '203.0.113.9'));
    check('real visitor uncounted after 1st ping', displayedCount($cache->store[C::CACHE_KEY], time() - 300), 0);
    $ctl->handle(new FakeRequest($realUA, '203.0.113.9'));
    check('real visitor counted after 2nd ping', displayedCount($cache->store[C::CACHE_KEY], time() - 300), 1);

    for ($i = 0; $i < 5; $i++) {
        $ctl->handle(new FakeRequest($realUA, '203.0.113.9'));
    }
    $map = $cache->store[C::CACHE_KEY];
    $hash = substr(hash('sha256', '203.0.113.9|' . $realUA), 0, 16);
    check('still one guest after 7 pings', displayedCount($map, time() - 300), 1);
    check('ping tally capped at the threshold', $map[$hash][1], C::MIN_PINGS_TO_COUNT);

    echo "\n=== 4. upgrade over a live legacy map ===\n";
    $cache2 = new FakeCache();
    $legacy = [];
    for ($i = 0; $i < 20; $i++) {
        $legacy['legacy' . $i] = time();      // pre-1.6.6 bare-timestamp format
    }
    $cache2->store[C::CACHE_KEY] = $legacy;
    check('legacy entries await their 2nd ping', displayedCount($legacy, time() - 300), 0);

    (new C($cache2, $settings))->handle(new FakeRequest($realUA, '198.51.100.4'));
    $map2 = $cache2->store[C::CACHE_KEY];
    check('legacy entries survive the first write', count($map2), 21);
    check('every entry normalized to array form', array_sum(array_map('is_array', $map2)), 21);

    echo "\n=== 5. per-IP rate limit still enforced ===\n";
    $ctl3 = new C(new FakeCache(), $settings);
    $codes = [];
    for ($i = 0; $i < 9; $i++) {
        $codes[] = $ctl3->handle(new FakeRequest($realUA, '198.51.100.77'))->getStatusCode();
    }
    check('six accepted, then silently ignored', $codes, [204, 204, 204, 204, 204, 204, 204, 204, 204]);

    printf("\n%d passed, %d failed\n\n", $pass, $fail);
    exit($fail === 0 ? 0 : 1);
}
