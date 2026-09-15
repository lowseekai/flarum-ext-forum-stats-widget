<?php

namespace Ekumanov\ForumWidgets\Api;

use Flarum\Settings\SettingsRepositoryInterface;
use Illuminate\Contracts\Cache\Repository as Cache;
use Laminas\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Records "this guest is here, right now" by hashing IP+UA and writing the
 * resulting fingerprint into a TTL'd map in the cache. The displayed guest
 * count is derived from the same map (entries with a recent timestamp). All
 * dedup is approximate: two guests behind the same NAT with the same browser
 * collapse into one, mobile users on a rotating IP can be double-counted.
 * That fuzziness is intentional — the alternative is a persistent client-side
 * identifier, which we explicitly chose not to introduce.
 */
class GuestHeartbeatController implements RequestHandlerInterface
{
    /**
     * Soft cap on the size of the guest presence map. Beyond this we evict
     * the oldest entry per write — bounds memory under sustained abuse
     * (one IP cycling User-Agent strings) regardless of how creative the
     * attacker gets.
     */
    public const MAX_ENTRIES = 2000;

    /**
     * Hard cap on heartbeats per IP per minute. Legitimate clients ping
     * roughly once per minute, so 6 leaves a generous buffer for retries
     * and clock drift while still throttling a flood from one source.
     */
    public const RATE_LIMIT_PER_MIN = 6;

    /**
     * How many heartbeats a fingerprint must send before it counts as a guest.
     *
     * Real visitors ping once a minute for as long as the tab is open, so they
     * clear this on their second beat. Scrapers overwhelmingly do not: they
     * render the page once, fire a single heartbeat and never come back —
     * which, against a 5-minute window, parked each of them in the tally for a
     * full five minutes. Measured on a live forum 2026-09-01, that single
     * effect was the whole distance between a displayed 70 and the ~5 people
     * actually reading.
     *
     * This is deliberately behavioural rather than another User-Agent or
     * IP/ASN heuristic. Both of those we have tried and lost: the current
     * fleet arrives from residential proxies wearing a byte-identical and
     * entirely genuine-looking desktop Chrome UA, so nothing about any single
     * request separates it from a real visitor. Coming back a minute later
     * does, and no amount of header spoofing fakes persistence.
     *
     * Cost: a guest is uncounted for their first ~60s, and one who leaves
     * inside a minute is never counted at all. For a number captioned "online
     * now", declining to count someone who has already left is arguably the
     * more honest answer.
     */
    public const MIN_PINGS_TO_COUNT = 2;

    /**
     * Shortest User-Agent we accept as a real browser. Genuine UAs run to
     * 50-150 characters; the scripted clients that reach this route send
     * things like the literal string "pc", which matches none of the needles
     * below and so used to be counted as a person. Nothing legitimate comes
     * anywhere near this short, and a UA-spoofing human is merely uncounted
     * rather than blocked, so the check costs nothing.
     */
    public const MIN_UA_LENGTH = 20;

    public const CACHE_KEY = 'ekumanov-forum-widgets.online-guests';

    /**
     * Lowercase User-Agent substrings that mark a request as a bot rather than
     * a human visitor. JS-rendering search crawlers (Googlebot, Bingbot, and
     * notably YandexRenderResourcesBot) execute the page bundle to render it,
     * which fires this heartbeat just like a real browser — so without this
     * gate they get counted as "online guests" and inflate the number the admin
     * sees. We drop them at the recording step (the only place we can: the bot
     * has already run the JS by the time it reaches us, so a client-side check
     * is useless). 'bot'/'crawl'/'spider'/'slurp' cover virtually every named
     * crawler; the rest catch headless scrapers and scripted clients. None of
     * these substrings appear in genuine browser User-Agents.
     */
    protected const BOT_UA_NEEDLES = [
        'bot', 'crawl', 'spider', 'slurp', 'headless',
        'python', 'curl', 'wget', 'okhttp', 'go-http', 'libwww', 'lighthouse',
    ];

    /**
     * Cloudflare's published edge ranges (https://www.cloudflare.com/ips/).
     * CF-Connecting-IP is only honoured when the request actually arrived from
     * one of these — otherwise that header is whatever the caller chose to send.
     * Last reviewed 2026-06; refresh if Cloudflare ever changes its ranges (rare).
     */
    protected const CLOUDFLARE_RANGES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    public function __construct(
        protected Cache $cache,
        protected SettingsRepositoryInterface $settings
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        // Feature gate. We still answer 204 (rather than 404 / error) so an
        // older client whose admin just turned the feature off doesn't spam
        // the logs — it just wastes a request until the page is reloaded.
        // `enable_heartbeat` is included so that disabling the heartbeat truly
        // records nothing, even for a stale client still posting on its own.
        if (! (bool) $this->settings->get('ekumanov-forum-widgets.show_online_users', true)
            || ! (bool) $this->settings->get('ekumanov-forum-widgets.show_online_guests', true)
            || ! (bool) $this->settings->get('ekumanov-forum-widgets.enable_heartbeat', true)) {
            return new EmptyResponse(204);
        }

        // Don't count bots. Render-capable crawlers run the page JS and so post
        // this heartbeat exactly like a browser would; left unfiltered they
        // dominate the guest tally (one render bot crawling from many IPs can
        // be most of the "online guests"). We still answer 204 so the bot's
        // own client doesn't treat it as an error and retry. Checked before the
        // rate limit so bot IPs don't even consume a rate-limit slot.
        $ua = $request->getHeaderLine('User-Agent');
        if ($this->looksLikeBot($ua)) {
            return new EmptyResponse(204);
        }

        $ip = $this->resolveClientIp($request);

        // Per-IP rate limit, fixed 60s window. Cheap and memory-bounded
        // (one cache entry per active IP, all expire after 60s).
        $rlKey = 'ekumanov-forum-widgets.guest-rl.' . hash('sha256', $ip);
        $count = (int) $this->cache->get($rlKey, 0);
        if ($count >= self::RATE_LIMIT_PER_MIN) {
            return new EmptyResponse(204);
        }
        $this->cache->put($rlKey, $count + 1, 60);

        // Identifier collapses tabs from the same browser/network into one.
        // Truncated to keep the cached map compact (full SHA-256 is 64 hex
        // chars × thousands of entries adds up).
        $hash = substr(hash('sha256', $ip . '|' . $ua), 0, 16);

        $intervalMin = max(1, (int) $this->settings->get('ekumanov-forum-widgets.last_seen_interval', 5));
        $now = time();
        $cutoff = $now - $intervalMin * 60;

        // Single-key hashmap of {hash → [lastSeenTs, pingCount]}. Race-y under
        // contention (two concurrent writers can lose each other's update) but
        // for a fuzzy counter, an occasional dropped tick is acceptable.
        $guests = $this->cache->get(self::CACHE_KEY, []);
        if (! is_array($guests)) {
            $guests = [];
        }

        // Inline prune keeps the map bounded in steady state. Entries written
        // by an older release are bare timestamps, and normalizeEntry() reads
        // both shapes, so upgrading doesn't require flushing a live map.
        $pruned = [];
        foreach ($guests as $key => $entry) {
            $normalized = self::normalizeEntry($entry);
            if ($normalized !== null && $normalized[0] > $cutoff) {
                $pruned[$key] = $normalized;
            }
        }
        $guests = $pruned;

        // Hard cap: when full and the entry is new, evict the oldest. When
        // updating an existing entry, the count stays put so no eviction.
        if (! isset($guests[$hash]) && count($guests) >= self::MAX_ENTRIES) {
            uasort($guests, fn ($a, $b) => $a[0] <=> $b[0]);
            $guests = array_slice($guests, 1, null, true);
        }

        // Bump the ping tally, capped at the threshold: beyond it the exact
        // number tells us nothing and would only bloat the serialized map.
        $pings = $guests[$hash][1] ?? 0;
        $guests[$hash] = [$now, min($pings + 1, self::MIN_PINGS_TO_COUNT)];

        // Wrapping TTL = window + small grace. After this, the whole map
        // can be evicted; the next heartbeat rebuilds it from scratch.
        $this->cache->put(self::CACHE_KEY, $guests, $intervalMin * 60 + 60);

        return new EmptyResponse(204);
    }

    /**
     * True when the User-Agent looks like a crawler or scripted client rather
     * than a human's browser. An absent or implausibly short UA counts as a
     * bot: every real browser sends a long one, so anything under
     * MIN_UA_LENGTH (the empty string included) means a script. Otherwise a
     * substring match on a lowercased UA — none of the needles occur in
     * genuine browser UAs, so false positives are not a practical concern.
     */
    protected function looksLikeBot(string $ua): bool
    {
        $ua = trim($ua);
        if (strlen($ua) < self::MIN_UA_LENGTH) {
            return true;
        }

        $ua = strtolower($ua);
        foreach (self::BOT_UA_NEEDLES as $needle) {
            if (str_contains($ua, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read one guest-map entry in either the current [timestamp, pings] shape
     * or the bare timestamp written before this release, so an upgrade or a
     * rollback degrades gracefully instead of miscounting a live map. A legacy
     * entry reports a single ping, so it simply waits for its next heartbeat
     * before it counts — the map self-corrects within a minute.
     *
     * @return array{0: int, 1: int}|null Null when the entry is unusable.
     */
    public static function normalizeEntry(mixed $entry): ?array
    {
        if (is_array($entry)) {
            if (! isset($entry[0]) || ! is_numeric($entry[0])) {
                return null;
            }

            return [(int) $entry[0], max(1, (int) ($entry[1] ?? 1))];
        }

        if (is_numeric($entry)) {
            return [(int) $entry, 1];
        }

        return null;
    }

    /**
     * Read the real client IP, trusting forwarded headers only when the actual
     * connecting peer is entitled to set them. This matters because the route is
     * unauthenticated and CSRF-exempt: both the rate limit and the guest
     * fingerprint key off this value, so a blindly-trusted CF-Connecting-IP /
     * X-Forwarded-For would let anyone hitting the origin directly forge a fresh
     * identity per request — bypassing the per-IP limit and inflating the count.
     *
     * Resolution order:
     *   1. Peer is a Cloudflare edge → CF-Connecting-IP is the genuine visitor.
     *      (When a front nginx rewrites REMOTE_ADDR via real_ip this branch is
     *      simply skipped and REMOTE_ADDR already holds the visitor — same result.)
     *   2. Peer is private/loopback → a local reverse proxy; its X-Forwarded-For
     *      first hop is the client. A direct public attacker has a public
     *      REMOTE_ADDR and never reaches this branch.
     *   3. Otherwise the peer IS the client — use REMOTE_ADDR, never a header.
     */
    protected function resolveClientIp(ServerRequestInterface $request): string
    {
        $remote = trim((string) ($request->getServerParams()['REMOTE_ADDR'] ?? ''));

        if ($remote !== '' && $this->ipInRanges($remote, self::CLOUDFLARE_RANGES)) {
            $cf = trim($request->getHeaderLine('CF-Connecting-IP'));
            if ($cf !== '') {
                return $cf;
            }
        }

        if ($remote !== '' && $this->isPrivateOrReserved($remote)) {
            $xff = $request->getHeaderLine('X-Forwarded-For');
            if ($xff !== '') {
                $first = trim(explode(',', $xff)[0]);
                if ($first !== '') {
                    return $first;
                }
            }
        }

        return $remote;
    }

    /** True if $ip falls inside any of the given CIDR blocks (IPv4 or IPv6). */
    protected function ipInRanges(string $ip, array $cidrs): bool
    {
        foreach ($cidrs as $cidr) {
            if ($this->ipInRange($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /** Binary CIDR containment test that works for both IPv4 and IPv6. */
    protected function ipInRange(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $bits = (int) $bits;

        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton((string) $subnet);

        // Reject malformed input and never compare a v4 address to a v6 subnet.
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $whole = intdiv($bits, 8);
        $partial = $bits % 8;

        if ($whole > 0 && strncmp($ipBin, $subnetBin, $whole) !== 0) {
            return false;
        }

        if ($partial === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $partial)) & 0xFF);

        return ($ipBin[$whole] & $mask) === ($subnetBin[$whole] & $mask);
    }

    /** True for RFC1918 / loopback / other reserved space — i.e. a local proxy hop. */
    protected function isPrivateOrReserved(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP) !== false
            && filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
            ) === false;
    }
}
