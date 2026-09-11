<?php

namespace Ekumanov\ForumWidgets;

use Carbon\Carbon;
use Ekumanov\ForumWidgets\Api\GuestHeartbeatController;
use Flarum\Api\Context;
use Flarum\Api\Schema;
use Flarum\Discussion\Discussion;
use Flarum\Post\CommentPost;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;

class ForumResourceFields
{
    /**
     * Hard cap on the number of online users that the cache stores and the
     * widget renders. Sized to comfortably cover any real-world Flarum forum
     * (in practice forums of this scale see <100 concurrent online users)
     * while keeping the rendered DOM list snappy on mobile and the JSON
     * payload small with sparse fields[users] (~180 bytes per user, so 500
     * worst-case ≈ 90 KB). Beyond this cap the panel still degrades
     * gracefully via the "+N more" overflow row. Was a per-cache admin
     * setting (15 / 40) until v1.6 — bandwidth no longer needs the knob now
     * that user objects are sparsely serialized.
     */
    public const MAX_DISPLAYED_ONLINE = 500;

    protected ?array $onlineUserDataCache = null;
    protected ?string $onlineUserDataCacheKey = null;
    protected ?array $statsCache = null;

    public function __construct(
        protected Cache $cache,
        protected SettingsRepositoryInterface $settings
    ) {}

    public function __invoke(): array
    {
        return [
            // === Online Users ===

            Schema\Boolean::make('canViewOnlineUsers')
                ->get(fn ($model, Context $context) => $this->isOnlineUsersEnabled()
                    && $context->getActor()->hasPermission('ekumanov-forum-widgets.viewOnlineUsers')),

            // Total online count — only visible when feature enabled + permission
            Schema\Integer::make('totalOnlineUsers')
                ->visible(fn ($model, Context $context) => $this->isOnlineUsersEnabled()
                    && $context->getActor()->hasPermission('ekumanov-forum-widgets.viewOnlineUsers'))
                ->get(fn ($model, Context $context) => $this->getOnlineUserData($context->getActor())['total'] ?? 0),

            // Number of hidden online users
            Schema\Integer::make('hiddenOnlineUsers')
                ->visible(fn ($model, Context $context) => $this->isOnlineUsersEnabled()
                    && $context->getActor()->hasPermission('ekumanov-forum-widgets.viewOnlineUsers'))
                ->get(fn ($model, Context $context) => $this->getOnlineUserData($context->getActor())['hidden'] ?? 0),

            Schema\Relationship\ToMany::make('onlineUsers')
                ->type('users')
                ->includable()
                ->visible(fn ($model, Context $context) => $this->isOnlineUsersEnabled()
                    && $context->getActor()->hasPermission('ekumanov-forum-widgets.viewOnlineUsers'))
                ->get(fn ($model, Context $context) => $this->getOnlineUserModels($context->getActor())),

            // Expose widget settings to the frontend
            Schema\Integer::make('forumStatsWidgetPosition')
                ->get(fn () => (int) $this->settings->get('ekumanov-forum-widgets.widget_position', -10)),

            Schema\Str::make('forumStatsWidgetLayout')
                ->get(fn () => $this->settings->get('ekumanov-forum-widgets.widget_layout', 'full-width')),

            Schema\Str::make('forumStatsBarPositionDesktop')
                ->get(fn () => $this->settings->get('ekumanov-forum-widgets.bar_position_desktop', 'inside-toolbar')),

            Schema\Str::make('forumStatsBarPositionMobile')
                ->get(fn () => $this->settings->get('ekumanov-forum-widgets.bar_position_mobile', 'inside-toolbar')),

            Schema\Boolean::make('forumStatsShowToggle')
                ->get(fn () => (bool) $this->settings->get('ekumanov-forum-widgets.show_toggle', true)),

            Schema\Str::make('forumStatsExpandedPanelWidth')
                ->get(fn () => $this->settings->get('ekumanov-forum-widgets.expanded_panel_width', 'online-cell')),

            // Heartbeat: client pings /api/forums periodically so the auth middleware
            // refreshes last_seen_at. Gated by the online users master toggle since the
            // heartbeat exists to make the online widget accurate.
            Schema\Boolean::make('forumStatsEnableHeartbeat')
                ->get(fn () => $this->isOnlineUsersEnabled()
                    && (bool) $this->settings->get('ekumanov-forum-widgets.enable_heartbeat', true)),

            // === Online Guests ===
            // Reuses the viewOnlineUsers permission for *display* (the count + dotted
            // row). The heartbeat itself is intentionally NOT permission-gated: a
            // guest who can't see the widget should still be COUNTED so the admin's
            // number is accurate. Without this asymmetry, on forums where guests
            // lack viewOnlineUsers the feature silently no-ops — guests never get
            // forumStatsShowOnlineGuests in their API payload, so the JS bails and
            // no heartbeat fires.
            Schema\Boolean::make('forumStatsShowOnlineGuests')
                ->get(fn () => $this->isOnlineGuestsEnabled()),

            // Whether the bar's main online number should sum members + guests.
            // Frontend reads this to decide between two display modes.
            Schema\Boolean::make('forumStatsIncludeGuestsInTotal')
                ->visible(fn ($model, Context $context) => $context->getActor()->hasPermission('ekumanov-forum-widgets.viewOnlineUsers'))
                ->get(fn () => $this->isOnlineGuestsEnabled()
                    && (bool) $this->settings->get('ekumanov-forum-widgets.include_guests_in_total', true)),

            Schema\Integer::make('onlineGuestsCount')
                ->visible(fn ($model, Context $context) => $this->isOnlineGuestsEnabled()
                    && $context->getActor()->hasPermission('ekumanov-forum-widgets.viewOnlineUsers'))
                ->get(fn () => $this->getOnlineGuestsCount()),

            // === Forum Statistics ===

            Schema\Integer::make('forumStatsDiscussionsCount')
                ->visible(fn ($model, Context $context) => (bool) $this->settings->get('ekumanov-forum-widgets.show_discussions_count', true)
                    && $context->getActor()->hasPermission('ekumanov-forum-widgets.viewStats.discussionsCount'))
                ->get(fn ($model, Context $context) => $this->getStats()['discussion_count'] ?? 0),

            Schema\Integer::make('forumStatsPostsCount')
                ->visible(fn ($model, Context $context) => (bool) $this->settings->get('ekumanov-forum-widgets.show_posts_count', true)
                    && $context->getActor()->hasPermission('ekumanov-forum-widgets.viewStats.postsCount'))
                ->get(fn ($model, Context $context) => $this->getStats()['post_count'] ?? 0),

            Schema\Integer::make('forumStatsUsersCount')
                ->visible(fn ($model, Context $context) => (bool) $this->settings->get('ekumanov-forum-widgets.show_users_count', true)
                    && $context->getActor()->hasPermission('ekumanov-forum-widgets.viewStats.usersCount'))
                ->get(fn ($model, Context $context) => $this->getStats()['user_count'] ?? 0),

            Schema\Relationship\ToOne::make('latestRegisteredUser')
                ->type('users')
                ->includable()
                ->visible(fn ($model, Context $context) => (bool) $this->settings->get('ekumanov-forum-widgets.show_latest_registration', true)
                    && $context->getActor()->hasPermission('ekumanov-forum-widgets.viewStats.latestMember'))
                ->get(fn ($model, Context $context) => $this->getLatestUser()),
        ];
    }

    protected function isOnlineUsersEnabled(): bool
    {
        return (bool) $this->settings->get('ekumanov-forum-widgets.show_online_users', true);
    }

    protected function isOnlineGuestsEnabled(): bool
    {
        return $this->isOnlineUsersEnabled()
            && (bool) $this->settings->get('ekumanov-forum-widgets.show_online_guests', true);
    }

    /**
     * Counts entries in the guest presence map that are still within the
     * configured window AND have sent at least MIN_PINGS_TO_COUNT heartbeats.
     * The second condition is what keeps one-shot scrapers out of the tally:
     * they render once and never return, while a real visitor re-pings every
     * minute. The map is the same one the heartbeat writes to — this is just a
     * reader. No DB queries; pure cache hit on every read.
     */
    protected function getOnlineGuestsCount(): int
    {
        $guests = $this->cache->get(GuestHeartbeatController::CACHE_KEY, []);
        if (! is_array($guests) || empty($guests)) {
            return 0;
        }

        $intervalMin = max(1, (int) $this->settings->get('ekumanov-forum-widgets.last_seen_interval', 5));
        $cutoff = time() - $intervalMin * 60;

        $count = 0;
        foreach ($guests as $entry) {
            $normalized = GuestHeartbeatController::normalizeEntry($entry);
            if ($normalized === null) {
                continue;
            }

            [$ts, $pings] = $normalized;
            if ($ts > $cutoff && $pings >= GuestHeartbeatController::MIN_PINGS_TO_COUNT) {
                $count++;
            }
        }

        return $count;
    }

    protected function getOnlineUserData(User $actor): array
    {
        $canSeeHidden = $actor->hasPermission('user.viewLastSeenAt');
        $cacheKey = $canSeeHidden
            ? 'ekumanov-forum-widgets.online-users.admin'
            : 'ekumanov-forum-widgets.online-users.regular';

        // In-memory cache for the same request
        if ($this->onlineUserDataCacheKey === $cacheKey && $this->onlineUserDataCache !== null) {
            return $this->onlineUserDataCache;
        }

        $ttl = max(1, (int) $this->settings->get('ekumanov-forum-widgets.online_users_cache_ttl', 30));
        $interval = max(1, (int) $this->settings->get('ekumanov-forum-widgets.last_seen_interval', 5));
        $maxUsers = self::MAX_DISPLAYED_ONLINE;

        $data = $this->cache->remember($cacheKey, $ttl, function () use ($canSeeHidden, $interval, $maxUsers) {
            // Only consider users who have confirmed their email. An unconfirmed
            // registration is provisional (often spam or abandoned), so it should
            // not surface as an online user — mirrors the user-count / latest-
            // registration gate in buildStats(). Applies to both the count and
            // the rendered avatar list since both branches clone this query.
            $allOnlineQuery = User::query()
                ->where('is_email_confirmed', true)
                ->where('last_seen_at', '>', Carbon::now()->subMinutes($interval));

            $totalAll = (clone $allOnlineQuery)->count();

            if ($canSeeHidden) {
                // Admin/privileged: sees all online users (hidden and non-hidden)
                $users = $allOnlineQuery->orderBy('last_seen_at', 'desc')
                    ->limit($maxUsers)
                    ->get();

                return [
                    'users' => $users->map(fn (User $u) => $u->getAttributes())->all(),
                    'total' => $totalAll,
                    'hidden' => 0,
                ];
            } else {
                // Regular: only visible user avatars/names, plus count of hidden ones
                $visibleQuery = clone $allOnlineQuery;
                $visibleQuery->where(function ($q) {
                    $q->whereNull('preferences')
                        ->orWhereRaw("JSON_EXTRACT(preferences, '$.discloseOnline') IS NULL")
                        ->orWhereRaw("JSON_EXTRACT(preferences, '$.discloseOnline') != false");
                });

                $totalVisible = (clone $visibleQuery)->count();
                $hidden = $totalAll - $totalVisible;

                $users = $visibleQuery->orderBy('last_seen_at', 'desc')
                    ->limit($maxUsers)
                    ->get();

                return [
                    'users' => $users->map(fn (User $u) => $u->getAttributes())->all(),
                    'total' => $totalAll,
                    'hidden' => $hidden,
                ];
            }
        }) ?: ['users' => [], 'total' => 0, 'hidden' => 0];

        $this->onlineUserDataCacheKey = $cacheKey;
        $this->onlineUserDataCache = $data;

        return $data;
    }

    /**
     * Reconstruct User models from cached attributes — one indexed lookup on a
     * cache hit (the existence guard below), otherwise zero queries.
     */
    protected function getOnlineUserModels(User $actor): array
    {
        $data = $this->getOnlineUserData($actor);

        if (empty($data['users'])) {
            return [];
        }

        $ids = array_column($data['users'], 'id');

        // Defense-in-depth: drop any cached users whose DB row no longer
        // exists. A row-less model (exists = true) makes core's loadAggregate()
        // crash on a null and 500 the entire forum document. The FlushCaches
        // listener already invalidates this cache on any Eloquent user delete,
        // so this only ever triggers on a delete that bypassed Eloquent
        // entirely (raw SQL). One indexed whereIn lookup.
        $existing = array_flip(User::query()->whereIn('id', $ids)->pluck('id')->all());

        $models = [];
        foreach ($data['users'] as $attributes) {
            if (! isset($existing[$attributes['id']])) {
                continue;
            }

            $user = new User();
            $user->setRawAttributes($attributes, true);
            $user->exists = true;
            $models[] = $user;
        }

        return $models;
    }

    protected function getStats(): array
    {
        if ($this->statsCache !== null) {
            return $this->statsCache;
        }

        $ttl = max(0, (int) $this->settings->get('ekumanov-forum-widgets.stats_cache_duration', 600));

        if ($ttl === 0) {
            $this->statsCache = $this->buildStats();
        } else {
            $this->statsCache = $this->cache->remember(
                'ekumanov-forum-widgets.stats',
                $ttl,
                fn () => $this->buildStats()
            ) ?: [];
        }

        return $this->statsCache;
    }

    protected function buildStats(): array
    {
        $ignorePrivate = (bool) $this->settings->get('ekumanov-forum-widgets.ignore_private_discussions', false);

        // Keep the latest-registration card limited to confirmed accounts.
        // The aggregate user count below intentionally includes every account:
        // it represents the forum's total registered-user count, not only
        // activated accounts.
        $latestUser = User::query()
            ->where('is_email_confirmed', true)
            ->orderBy('joined_at', 'desc')
            ->first();

        return [
            'discussion_count' => $ignorePrivate
                ? Discussion::query()->where('is_private', false)->count()
                : Discussion::query()->count(),
            'post_count' => CommentPost::query()->count(),
            'user_count' => User::query()->count(),
            'latest_user' => $latestUser ? $latestUser->getAttributes() : null,
        ];
    }

    /**
     * Reconstruct the latest User model from cached attributes — one indexed PK
     * lookup on a cache hit (the existence guard below), otherwise zero queries.
     */
    protected function getLatestUser(): ?User
    {
        $stats = $this->getStats();
        $attributes = $stats['latest_user'] ?? null;

        if (! $attributes) {
            return null;
        }

        // Defense-in-depth: a stale cache pointing at a deleted row makes core's
        // loadAggregate() crash on a null and 500 the entire forum document.
        // The latest registration is exactly who spam cleanups delete, so this
        // is the most likely ghost. The FlushCaches listener already invalidates
        // this cache on any Eloquent user delete, so this only ever triggers on
        // a delete that bypassed Eloquent entirely (raw SQL). One indexed PK lookup.
        if (! User::query()->whereKey($attributes['id'])->exists()) {
            return null;
        }

        $user = new User();
        $user->setRawAttributes($attributes, true);
        $user->exists = true;

        return $user;
    }
}
