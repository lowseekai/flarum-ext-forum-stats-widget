<?php

namespace Ekumanov\ForumWidgets;

use Ekumanov\ForumWidgets\Api\GuestHeartbeatController;
use Flarum\Api\Endpoint;
use Flarum\Api\Resource\ForumResource;
use Flarum\Extend;

return [
    (new Extend\Frontend('forum'))
        ->js(__DIR__ . '/js/dist/forum.js')
        ->css(__DIR__ . '/resources/css/forum.css'),

    (new Extend\Frontend('admin'))
        ->js(__DIR__ . '/js/dist/admin.js')
        ->css(__DIR__ . '/resources/css/admin.css'),

    new Extend\Locales(__DIR__ . '/locale'),

    (new Extend\Settings())
        ->default('ekumanov-forum-widgets.widget_layout', 'full-width')
        ->default('ekumanov-forum-widgets.bar_position_desktop', 'inside-toolbar')
        ->default('ekumanov-forum-widgets.bar_position_mobile', 'inside-toolbar')
        ->default('ekumanov-forum-widgets.show_online_users', true)
        ->default('ekumanov-forum-widgets.last_seen_interval', 5)
        ->default('ekumanov-forum-widgets.online_users_cache_ttl', 30)
        ->default('ekumanov-forum-widgets.enable_heartbeat', true)
        ->default('ekumanov-forum-widgets.show_online_guests', true)
        ->default('ekumanov-forum-widgets.include_guests_in_total', true)
        ->default('ekumanov-forum-widgets.show_discussions_count', true)
        ->default('ekumanov-forum-widgets.show_posts_count', true)
        ->default('ekumanov-forum-widgets.show_users_count', true)
        ->default('ekumanov-forum-widgets.show_latest_registration', true)
        ->default('ekumanov-forum-widgets.stats_cache_duration', 600)
        ->default('ekumanov-forum-widgets.ignore_private_discussions', false)
        ->default('ekumanov-forum-widgets.widget_position', -10)
        ->default('ekumanov-forum-widgets.show_toggle', true)
        ->default('ekumanov-forum-widgets.expanded_panel_width', 'online-cell'),

    (new Extend\ApiResource(ForumResource::class))
        ->fields(ForumResourceFields::class)
        ->endpoint(Endpoint\Show::class, function (Endpoint\Show $endpoint) {
            return $endpoint->addDefaultInclude(['onlineUsers', 'latestRegisteredUser']);
        }),

    (new Extend\Routes('api'))
        ->post('/forum-widgets/guest-heartbeat', 'forum-widgets.guest-heartbeat', GuestHeartbeatController::class),

    // The guest heartbeat is an unauthenticated, side-effect-free presence ping.
    // CSRF protection buys nothing here (there is no state for a forged request
    // to change) and it actively breaks long-open tabs: once the page's baked-in
    // CSRF token ages past the session lifetime, every ping is rejected with a
    // 400 and the client keeps retrying each interval. Exempting the route keeps
    // presence counting working on stale tabs. Declared HERE, alongside the route
    // it guards, so the behaviour is self-contained and never depends on another
    // extension being installed.
    (new Extend\Csrf())
        ->exemptRoute('forum-widgets.guest-heartbeat'),

    (new Extend\ThrottleApi())
        ->set('ekumanovForumWidgetsGuestHeartbeatBypass', function ($request) {
            return $request->getAttribute('routeName') === 'forum-widgets.guest-heartbeat' ? false : null;
        }),

    (new Extend\Event())
        ->subscribe(Listener\FlushCaches::class),
];
