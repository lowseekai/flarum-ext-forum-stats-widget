import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import Forum from 'flarum/common/models/Forum';
import Model from 'flarum/common/Model';
import Avatar from 'flarum/common/components/Avatar';
import username from 'flarum/common/helpers/username';
import formatNumber from 'flarum/common/utils/formatNumber';
import extractText from 'flarum/common/utils/extractText';
import Link from 'flarum/common/components/Link';
import Tooltip from 'flarum/common/components/Tooltip';
import IndexPage from 'flarum/forum/components/IndexPage';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import Component from 'flarum/common/Component';

// Shared promise so stacked widgets (desktop + mobile) coalesce into one fetch.
let inflightRefresh = null;

// Cheap check: does the current actor have any visible widget data on the forum resource?
// Permission/visibility gating happens server-side (ForumResourceFields), so if none of these
// attributes were serialized on initial load, the actor has no reason to refetch. Skipping
// saves a round-trip for guests who've been denied all widget fields.
function hasVisibleWidgetData() {
    const f = app.forum;
    if (!f) return false;
    if (f.attribute('canViewOnlineUsers')) return true;
    if (f.attribute('forumStatsDiscussionsCount') != null) return true;
    if (f.attribute('forumStatsPostsCount') != null) return true;
    if (f.attribute('forumStatsUsersCount') != null) return true;
    try { if (f.latestRegisteredUser()) return true; } catch (e) {}
    return false;
}

function refreshForumData() {
    if (!hasVisibleWidgetData()) return Promise.resolve();
    if (inflightRefresh) return inflightRefresh;
    // Flarum 2.0's ForumResource is a singleton keyed at /api/forums (no /:id),
    // so we call the 2-arg form of Store.find — passing an id would hit /api/forums/1 and 404.
    // Sparse fields[users] keeps the included user objects to ~180 bytes each instead of
    // the ~2.9 KB Flarum + extensions would otherwise serialize per user.
    inflightRefresh = app.store
        .find('forums', {
            include: 'onlineUsers,latestRegisteredUser',
            'fields[users]': 'username,displayName,avatarUrl,slug',
        })
        .catch(() => {})
        .then(() => {
            inflightRefresh = null;
            m.redraw();
        });
    return inflightRefresh;
}

// === Presence heartbeat ===
// Periodically pings the forum resource so Flarum's auth middleware refreshes
// last_seen_at, keeping users on long-lived tabs visible in the online widget.
// On the index page (with the widget mounted) the same tick also fetches fresh
// widget data — one round trip covers both jobs. Off-index, it's just a sparse
// ping. Server cost is bounded by core's 180s LAST_SEEN_UPDATE_DIFF write throttle.
//
// For guests, the auth middleware doesn't apply, so we POST to a dedicated
// endpoint that records IP+UA fingerprint into a TTL'd presence map. Same tick
// also refreshes widget data on the index so the guest count updates live.
const HEARTBEAT_INTERVAL_MS = 60000;
// Ceiling for the exponential backoff applied after consecutive failures. A
// heartbeat is a background convenience; if the endpoint is unreachable or
// rate-limiting us we slow to a ping every few minutes rather than hammering
// it once a minute indefinitely — the behaviour that, before the route was
// CSRF-exempt, turned stale-token tabs into a perpetual 400 storm.
const HEARTBEAT_MAX_BACKOFF_MS = 10 * 60 * 1000;

let heartbeatTimer = null;
let heartbeatStopped = false;
let heartbeatFailures = 0;
let heartbeatInFlight = false;

// Schedule the next tick. Baseline cadence is HEARTBEAT_INTERVAL_MS with ±10%
// jitter so a burst of tabs opened together (or reconnecting after an outage)
// don't all ping on the same instant. After consecutive failures the delay
// grows exponentially up to the cap; the first success resets it.
function scheduleNextHeartbeat() {
    if (heartbeatStopped) return;
    if (heartbeatTimer) {
        clearTimeout(heartbeatTimer);
        heartbeatTimer = null;
    }
    const base = heartbeatFailures > 0
        ? Math.min(HEARTBEAT_INTERVAL_MS * Math.pow(2, heartbeatFailures), HEARTBEAT_MAX_BACKOFF_MS)
        : HEARTBEAT_INTERVAL_MS;
    const delay = base * (0.9 + Math.random() * 0.2);
    heartbeatTimer = setTimeout(runHeartbeat, delay);
}

// Fire one heartbeat, then reschedule once it settles. A real failure (network
// error, 429, 5xx) grows the backoff; success resets it; a skipped tick (hidden
// tab, feature off, on-index member) leaves it untouched. The in-flight guard
// keeps rapid visibility toggling from stacking overlapping requests.
function runHeartbeat() {
    if (heartbeatInFlight) return;
    heartbeatInFlight = true;

    let outcome;
    try {
        outcome = fireHeartbeat();
    } catch (e) {
        outcome = Promise.resolve('skip');
    }

    Promise.resolve(outcome)
        .then(
            (result) => { if (result !== 'skip') heartbeatFailures = 0; },
            () => { if (heartbeatFailures < 10) heartbeatFailures++; }
        )
        .then(() => {
            heartbeatInFlight = false;
            scheduleNextHeartbeat();
        });
}

// Ping immediately and reset the schedule (used when a tab returns to the
// foreground). Clears any pending timer first so only one is ever live.
function triggerHeartbeatNow() {
    if (heartbeatTimer) {
        clearTimeout(heartbeatTimer);
        heartbeatTimer = null;
    }
    runHeartbeat();
}

function fireHeartbeat() {
    // Returns a promise: resolves with 'skip' when no ping was sent, resolves on
    // success, rejects on a real failure so runHeartbeat can back off. The outer
    // try/catch is a safety net — a heartbeat must never break the forum even if
    // app.session/app.forum are in an unexpected state.
    try {
        if (heartbeatStopped) return Promise.resolve('skip');
        if (document.visibilityState !== 'visible') return Promise.resolve('skip');
        if (!app.forum || !app.forum.attribute('forumStatsEnableHeartbeat')) return Promise.resolve('skip');

        const isAuthenticated = !!(app.session && app.session.user);
        const onIndex = app.current && app.current.get && app.current.get('routeName') === 'index';

        if (isAuthenticated) {
            if (onIndex && hasVisibleWidgetData()) {
                // Combined call: refresh widget data; auth middleware updates last_seen_at as a side effect.
                refreshForumData();
                return Promise.resolve('skip');
            }

            // Off-index or no widget permissions: pure sparse ping just to bump last_seen_at.
            return app.request({
                method: 'GET',
                url: app.forum.attribute('apiUrl') + '/forums',
                params: { 'fields[forums]': 'id' },
                background: true,
                errorHandler: () => {},
            }).catch((err) => {
                // 401 means the session is gone — stop firing rather than spam.
                if (err && err.status === 401) {
                    heartbeatStopped = true;
                    if (heartbeatTimer) {
                        clearTimeout(heartbeatTimer);
                        heartbeatTimer = null;
                    }
                    return 'skip';
                }
                throw err;
            });
        }

        // Guest path. Only fires when the admin has opted into guest counting —
        // `forumStatsShowOnlineGuests` is the master switch and is permission-gated
        // server-side, so this also auto-disables for guests denied online-users.
        if (!app.forum.attribute('forumStatsShowOnlineGuests')) return Promise.resolve('skip');

        const ping = app.request({
            method: 'POST',
            url: app.forum.attribute('apiUrl') + '/forum-widgets/guest-heartbeat',
            background: true,
            errorHandler: () => {},
        });

        // Refresh widget data on the index so the guest count visibly updates
        // without waiting for a full page reload.
        if (onIndex && hasVisibleWidgetData()) {
            refreshForumData();
        }

        return ping;
    } catch (e) {
        return Promise.resolve('skip');
    }
}

class CompactForumWidget extends Component {
    oninit(vnode) {
        super.oninit(vnode);
        this.expanded = false;
        this.boundDocClick = this.onDocumentClick.bind(this);
        this.boundDocKeydown = this.onDocumentKeydown.bind(this);
        this.boundVisChange = () => {
            if (document.visibilityState === 'visible') refreshForumData();
        };
    }

    oncreate(vnode) {
        super.oncreate(vnode);
        document.addEventListener('click', this.boundDocClick);
        document.addEventListener('keydown', this.boundDocKeydown);
        document.addEventListener('visibilitychange', this.boundVisChange);
    }

    onremove(vnode) {
        super.onremove(vnode);
        document.removeEventListener('click', this.boundDocClick);
        document.removeEventListener('keydown', this.boundDocKeydown);
        document.removeEventListener('visibilitychange', this.boundVisChange);
    }

    onDocumentClick(e) {
        if (!this.expanded) return;
        if (this.element && this.element.contains(e.target)) return;
        this.expanded = false;
        m.redraw();
    }

    onDocumentKeydown(e) {
        if (!this.expanded || e.key !== 'Escape') return;
        this.expanded = false;
        m.redraw();
        // Return focus to the toggle button so SR + keyboard users land back
        // where they were before opening the panel.
        const toggle = this.element && this.element.querySelector('.CompactWidget-toggle');
        if (toggle) toggle.focus();
    }

    view() {
        const isFullWidth = this.attrs.layout === 'full-width';
        const viewport = this.attrs.viewport;
        const isMobile = viewport === 'mobile';
        const isDesktop = viewport === 'desktop';
        const isDesktopFullWidth = isFullWidth && isDesktop;

        const showToggle = app.forum.attribute('forumStatsShowToggle') !== false;
        const expandedPanelWidth = isDesktopFullWidth
            ? (app.forum.attribute('forumStatsExpandedPanelWidth') || 'online-cell')
            : 'full-bar';
        const isOnlineCellMode = expandedPanelWidth === 'online-cell';

        const canViewOnline = app.forum.attribute('canViewOnlineUsers');
        const users = canViewOnline ? (app.forum.onlineUsers() || []) : [];
        const totalOnline = canViewOnline ? (app.forum.attribute('totalOnlineUsers') || 0) : 0;
        const hiddenOnline = canViewOnline ? (app.forum.attribute('hiddenOnlineUsers') || 0) : 0;

        // Guest counter: opt-in. `forumStatsShowOnlineGuests` is server-gated by the
        // viewOnlineUsers permission, so it auto-vanishes for actors denied online
        // visibility. `displayedOnline` is what the bar shows — depending on the
        // include-in-total toggle, that's either members-only or members+guests.
        const showOnlineGuests = canViewOnline && !!app.forum.attribute('forumStatsShowOnlineGuests');
        const includeGuestsInTotal = showOnlineGuests && !!app.forum.attribute('forumStatsIncludeGuestsInTotal');
        const onlineGuestsCount = showOnlineGuests ? (app.forum.attribute('onlineGuestsCount') || 0) : 0;
        const displayedOnline = includeGuestsInTotal ? (totalOnline + onlineGuestsCount) : totalOnline;

        const discussionsCount = app.forum.attribute('forumStatsDiscussionsCount');
        const postsCount = app.forum.attribute('forumStatsPostsCount');
        const usersCount = app.forum.attribute('forumStatsUsersCount');

        let latestUser = null;
        try {
            latestUser = app.forum.latestRegisteredUser();
        } catch (e) {}

        const hasAnyStat = discussionsCount != null || postsCount != null || usersCount != null;
        const hasOnline = canViewOnline && displayedOnline > 0;
        const hasAnything = hasOnline || hasAnyStat;

        if (!hasAnything) return m('div');

        const overflowCount = Math.max(0, totalOnline - users.length - hiddenOnline);
        const hasExpandableContent = canViewOnline || latestUser;

        // Toggle is placed inline (inside online cell wrapper) on:
        // - Mobile (always, when online users are shown)
        // - Desktop online-cell mode (toggle anchors the dropdown to the online cell)
        // Falls back to bar-end if no online users are shown.
        const inlineToggle = hasOnline && (isMobile || (isDesktopFullWidth && isOnlineCellMode));

        const isInToolbar = this.attrs.position === 'inside-toolbar';
        const isAboveFooter = this.attrs.position === 'above-footer';

        const classNames = [
            this.expanded ? 'CompactWidget--expanded' : '',
            isFullWidth ? 'CompactWidget--fullWidth' : '',
            isInToolbar ? 'CompactWidget--inToolbar' : '',
            isAboveFooter ? 'CompactWidget--aboveFooter' : '',
            isDesktop ? 'CompactWidget--desktop' : '',
            isMobile ? 'CompactWidget--mobile' : '',
            isDesktopFullWidth && !isOnlineCellMode ? 'CompactWidget--fullBar' : '',
            isDesktopFullWidth && isOnlineCellMode ? 'CompactWidget--onlineCell' : '',
        ].filter(Boolean).join(' ');

        const pre = 'ekumanov-forum-widgets.forum.stats.';

        // buildStat: creates a stat element with optional tooltip.
        // Tooltips are shown on classic-sidebar desktop and mobile; never in full-width desktop
        // (which has inline labels or tappable cells instead).
        // noTooltip: suppress the tooltip explicitly (e.g. online stat on mobile, where tapping
        // the cell expands the panel and a tooltip would flicker on touch).
        const buildStat = (icon, value, tooltipKey, labelKey, extraClass, noTooltip) => {
            const valueText = formatNumber(value);
            const translatedLabel = extractText(app.translator.trans(labelKey, { count: value })).trim();
            // Some language packs include the count in the label (for example,
            // "{count} 用户"), while the widget already renders the count in its
            // own element. Remove that duplicated leading count for display and
            // accessibility text without changing labels that only contain text.
            const inlineLabel = translatedLabel.startsWith(valueText)
                ? translatedLabel.slice(valueText.length).trim()
                : translatedLabel;
            const tooltipText = app.translator.trans(tooltipKey);
            const accessibleLabel = valueText + ' ' + inlineLabel;
            const useTooltip = !noTooltip && !isDesktopFullWidth;
            const statEl = m('span.CompactWidget-stat' + (extraClass || ''), {
                'aria-label': accessibleLabel,
                role: 'text',
            }, [
                m('i.fa-solid.' + icon, { 'aria-hidden': 'true' }),
                m('span', formatNumber(value)),
                isFullWidth ? m('span.CompactWidget-statLabel', inlineLabel) : null,
            ]);
            return useTooltip ? m(Tooltip, { text: tooltipText }, statEl) : statEl;
        };

        // Toggle button (shared between inline and bar-end placements)
        const toggleButton = hasExpandableContent && showToggle
            ? m('button.CompactWidget-toggle.Button.Button--icon.Button--link', {
                onclick: (e) => { e.stopPropagation(); this.expanded = !this.expanded; m.redraw.sync(); },
                'aria-label': this.expanded
                    ? app.translator.trans('ekumanov-forum-widgets.forum.aria.collapse_details')
                    : app.translator.trans('ekumanov-forum-widgets.forum.aria.expand_details'),
                'aria-expanded': String(this.expanded),
                tabIndex: (!inlineToggle && isDesktopFullWidth) ? '-1' : '0',
            }, m('i.fas', { className: this.expanded ? 'fa-chevron-up' : 'fa-chevron-down', 'aria-hidden': 'true' }))
            : null;

        // Expanded panel content (positioning differs by mode — rendered in different places below)
        const expandedPanel = this.expanded
            ? m('.CompactWidget-expanded', {
                onclick: (e) => e.stopPropagation(),
                role: 'region',
                'aria-label': app.translator.trans('ekumanov-forum-widgets.forum.aria.details_panel'),
            }, [
                canViewOnline && (users.length > 0 || hiddenOnline > 0 || overflowCount > 0 || onlineGuestsCount > 0)
                    ? m('.CompactWidget-expandedSection', [
                        // role/aria-level instead of <h3> avoids inheriting the global <h3>
                        // typography (large bold text); CSS for .CompactWidget-expandedLabel
                        // still drives the visual style.
                        m('.CompactWidget-expandedLabel', {
                            id: 'compact-widget-online',
                            role: 'heading',
                            'aria-level': '3',
                        }, [
                            m('.CompactWidget-greenDot', { 'aria-hidden': 'true' }),
                            ' ',
                            app.translator.trans('ekumanov-forum-widgets.forum.online_users.title'),
                            ' (' + displayedOnline + ')',
                        ]),
                        m('.CompactWidget-expandedUsersList', {
                            role: 'list',
                            'aria-labelledby': 'compact-widget-online',
                        }, [
                            users.map(user =>
                                m(Link, {
                                    href: app.route('user', { username: user.slug() }),
                                    className: 'CompactWidget-expandedUser',
                                    key: user.id(),
                                    role: 'listitem',
                                }, [
                                    m(Avatar, { user: user }),
                                    m('span.CompactWidget-expandedUsername', user.displayName()),
                                ])
                            ),
                            overflowCount > 0
                                ? m('.CompactWidget-expandedUser.CompactWidget-expandedUser--overflow', {
                                    role: 'listitem',
                                    'aria-label': app.translator.trans('ekumanov-forum-widgets.forum.aria.overflow_users', { count: overflowCount }),
                                }, [
                                    m('span.CompactWidget-overflowAvatar', { 'aria-hidden': 'true' }, '+' + formatNumber(overflowCount)),
                                    m('span.CompactWidget-expandedUsername.CompactWidget-expandedUsername--muted',
                                        app.translator.trans('ekumanov-forum-widgets.forum.online_users.overflow_more')
                                    ),
                                ])
                                : null,
                            hiddenOnline > 0
                                ? m('.CompactWidget-expandedUser.CompactWidget-expandedUser--hidden.CompactWidget-expandedUser--summary', {
                                    role: 'listitem',
                                    'aria-label': hiddenOnline + ' ' + app.translator.trans('ekumanov-forum-widgets.forum.online_users.hidden_users', { count: hiddenOnline }),
                                }, [
                                    m('span.CompactWidget-hiddenAvatar', { 'aria-hidden': 'true' }, formatNumber(hiddenOnline)),
                                    m('span.CompactWidget-expandedUsername.CompactWidget-expandedUsername--muted',
                                        app.translator.trans('ekumanov-forum-widgets.forum.online_users.hidden_users', { count: hiddenOnline })
                                    ),
                                ])
                                : null,
                            onlineGuestsCount > 0
                                ? m('.CompactWidget-expandedUser.CompactWidget-expandedUser--guest.CompactWidget-expandedUser--summary', {
                                    role: 'listitem',
                                    'aria-label': onlineGuestsCount + ' ' + app.translator.trans('ekumanov-forum-widgets.forum.online_users.guests', { count: onlineGuestsCount }),
                                }, [
                                    m('span.CompactWidget-guestAvatar', { 'aria-hidden': 'true' }, formatNumber(onlineGuestsCount)),
                                    m('span.CompactWidget-expandedUsername.CompactWidget-expandedUsername--muted',
                                        app.translator.trans('ekumanov-forum-widgets.forum.online_users.guests', { count: onlineGuestsCount })
                                    ),
                                ])
                                : null,
                        ]),
                    ])
                    : null,

                latestUser
                    ? m('.CompactWidget-expandedSection', [
                        m('.CompactWidget-expandedLabel', { id: 'compact-widget-latest' },
                            app.translator.trans('ekumanov-forum-widgets.forum.stats.latest_registration')
                        ),
                        m(Link, {
                            href: app.route('user', { username: latestUser.slug() }),
                            className: 'CompactWidget-expandedUser',
                            'aria-labelledby': 'compact-widget-latest',
                        }, [
                            m(Avatar, { user: latestUser }),
                            m('span.CompactWidget-expandedUsername', username(latestUser)),
                        ]),
                    ])
                    : null,
            ])
            : null;

        // Build stats in order: online (1), users (2), discussions (3), posts (4)
        const stats = [];

        // Merge online + users into a single "N/M" cell on mobile inside-toolbar mode,
        // where horizontal space is tight (Latest dropdown + Mark-all-read button share the row).
        // Shows online icon + "online/total", click opens the expanded panel.
        const mergeOnlineUsers = isMobile && isInToolbar && hasOnline && usersCount != null;

        // Online users — first. Whenever clicking the online cell should open the panel
        // (everywhere except desktop full-bar mode, where the whole bar is the click target),
        // we wrap the stat in an interactive `.CompactWidget-onlineWrapper` div.
        if (mergeOnlineUsers) {
            const mergedValue = formatNumber(displayedOnline) + '/' + formatNumber(usersCount);
            const accessibleLabel = extractText(app.translator.trans('ekumanov-forum-widgets.forum.stats.label_online_over_total', {
                online: displayedOnline,
                total: usersCount,
            }));
            const mergedStat = m('span.CompactWidget-stat.CompactWidget-stat--online.CompactWidget-stat--merged', {
                'aria-label': accessibleLabel,
                role: 'text',
            }, [
                m('i.fa-solid.fa-user', { 'aria-hidden': 'true' }),
                m('span', mergedValue),
            ]);
            // Skip the chevron entirely when merged — the "N/M" number pair needs every
            // pixel on this already-cramped mobile toolbar row. Tapping the cell still
            // opens the panel, so losing the chevron has no functional impact.
            stats.push(m('.CompactWidget-onlineWrapper', {
                onclick: (e) => { e.stopPropagation(); this.expanded = !this.expanded; m.redraw.sync(); },
                onkeydown: (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.expanded = !this.expanded; m.redraw.sync(); } },
                role: 'button',
                tabIndex: '0',
                'aria-expanded': String(this.expanded),
            }, [
                mergedStat,
            ]));
        } else if (hasOnline) {
            // Online cell is clickable in classic sidebar, mobile, and desktop online-cell modes —
            // anywhere except desktop full-bar, which handles clicks on the entire bar.
            const onlineCellClickable = hasExpandableContent && !(isDesktopFullWidth && !isOnlineCellMode);
            // Skip the tooltip when the cell IS the primary click target in a layout without
            // inline labels or a hovered bar (mobile + desktop online-cell). Classic keeps the
            // tooltip because icons there have no visible label.
            const noOnlineTooltip = inlineToggle;
            const onlineStat = buildStat('fa-user', displayedOnline, pre + 'tooltip_online', pre + 'label_online', '.CompactWidget-stat--online', noOnlineTooltip);

            if (onlineCellClickable) {
                stats.push(m('.CompactWidget-onlineWrapper', {
                    onclick: (e) => { e.stopPropagation(); this.expanded = !this.expanded; m.redraw.sync(); },
                    onkeydown: (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.expanded = !this.expanded; m.redraw.sync(); } },
                    role: 'button',
                    tabIndex: '0',
                    'aria-expanded': String(this.expanded),
                }, [
                    onlineStat,
                    // Chevron goes inside the wrapper only when `inlineToggle` (mobile or
                    // desktop online-cell). Classic keeps the chevron at bar-end.
                    inlineToggle ? toggleButton : null,
                    // Panel inside wrapper only in desktop online-cell mode
                    isDesktopFullWidth && isOnlineCellMode ? expandedPanel : null,
                ]));
            } else {
                stats.push(onlineStat);
            }
        }

        // Users count — second. Skipped when merged with online above.
        if (usersCount != null && !mergeOnlineUsers) {
            stats.push(buildStat('fa-user', usersCount, pre + 'tooltip_users', pre + 'label_users', ''));
        }
        // Discussions count — third
        if (discussionsCount != null) {
            stats.push(buildStat('fa-comments', discussionsCount, pre + 'tooltip_discussions', pre + 'label_discussions', ''));
        }
        // Posts count — fourth
        if (postsCount != null) {
            stats.push(buildStat('fa-comment', postsCount, pre + 'tooltip_posts', pre + 'label_posts', ''));
        }

        // Bar is clickable in full-bar desktop mode only
        const barClickable = isDesktopFullWidth && !isOnlineCellMode && hasExpandableContent;

        return m('section.CompactWidget', {
            className: classNames,
            'aria-label': app.translator.trans('ekumanov-forum-widgets.forum.stats.title'),
        }, [
            m('.CompactWidget-bar', {
                onclick: barClickable
                    ? (e) => { e.stopPropagation(); this.expanded = !this.expanded; m.redraw.sync(); }
                    : undefined,
                onkeydown: barClickable
                    ? (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.expanded = !this.expanded; m.redraw.sync(); } }
                    : undefined,
                role: barClickable ? 'button' : undefined,
                tabIndex: barClickable ? '0' : undefined,
                'aria-expanded': barClickable ? String(this.expanded) : undefined,
            }, [
                m('.CompactWidget-stats', stats),
                // End-of-bar toggle: full-bar desktop mode or sidebar/mobile when no online users
                !inlineToggle ? toggleButton : null,
            ]),

            // Expanded panel at bar level for full-bar desktop and mobile
            // (online-cell desktop renders panel inside the online wrapper above)
            !isOnlineCellMode ? expandedPanel : null,
        ]);
    }
}

app.initializers.add('ekumanov/forum-widgets', () => {
    Forum.prototype.onlineUsers = Model.hasMany('onlineUsers');
    Forum.prototype.totalOnlineUsers = Model.attribute('totalOnlineUsers');
    Forum.prototype.hiddenOnlineUsers = Model.attribute('hiddenOnlineUsers');
    Forum.prototype.canViewOnlineUsers = Model.attribute('canViewOnlineUsers');
    Forum.prototype.forumStatsEnableHeartbeat = Model.attribute('forumStatsEnableHeartbeat');
    Forum.prototype.forumStatsShowOnlineGuests = Model.attribute('forumStatsShowOnlineGuests');
    Forum.prototype.forumStatsIncludeGuestsInTotal = Model.attribute('forumStatsIncludeGuestsInTotal');
    Forum.prototype.onlineGuestsCount = Model.attribute('onlineGuestsCount');
    Forum.prototype.latestRegisteredUser = Model.hasOne('latestRegisteredUser');

    // Helper: read settings lazily (app.forum is not available at initializer time)
    const getLayout = () => app.forum.attribute('forumStatsWidgetLayout') || 'full-width';
    const getDesktopPos = () => app.forum.attribute('forumStatsBarPositionDesktop') || 'inside-toolbar';
    const getMobilePos = () => app.forum.attribute('forumStatsBarPositionMobile') || 'inside-toolbar';
    // contentItems priorities: Flarum renders higher priority earlier. Toolbar is ~100 and the
    // discussion list is below that. -1000 drops the widget to the end of IndexPage content,
    // rendering it directly above the site footer.
    const contentPriority = (pos) => {
        if (pos === 'above-toolbar') return 101;
        if (pos === 'above-footer') return -1000;
        return 95; // below-toolbar
    };

    // Desktop: classic sidebar layout
    extend(IndexSidebar.prototype, 'items', function (items) {
        if (getLayout() !== 'classic') return;
        const routeName = app.current && app.current.get('routeName');
        if (routeName !== 'index') return;
        const position = app.forum.attribute('forumStatsWidgetPosition') || -10;
        items.add('compactForumWidget', m(CompactForumWidget, { layout: 'classic', viewport: 'desktop' }), position);
    });

    // Inside-toolbar placements (desktop full-width + mobile)
    extend(IndexPage.prototype, 'toolbarItems', function (items) {
        const routeName = app.current && app.current.get('routeName');
        if (routeName !== 'index') return;
        if (getLayout() !== 'classic' && getDesktopPos() === 'inside-toolbar') {
            items.add('compactForumWidget', m(CompactForumWidget, { layout: 'full-width', position: 'inside-toolbar', viewport: 'desktop' }), 95);
        }
        if (getMobilePos() === 'inside-toolbar') {
            items.add('compactForumWidgetMobile', m(CompactForumWidget, { layout: 'full-width', position: 'inside-toolbar', viewport: 'mobile' }), 95);
        }
    });

    // Desktop full-width above/below/footer + Mobile above/below/footer (not inside-toolbar).
    extend(IndexPage.prototype, 'contentItems', function (items) {
        const routeName = app.current && app.current.get('routeName');
        if (routeName !== 'index') return;

        if (getLayout() !== 'classic' && getDesktopPos() !== 'inside-toolbar') {
            items.add('compactForumWidget', m(CompactForumWidget, { layout: 'full-width', viewport: 'desktop', position: getDesktopPos() }), contentPriority(getDesktopPos()));
        }
        if (getMobilePos() !== 'inside-toolbar') {
            items.add('compactForumWidgetMobile', m(CompactForumWidget, { layout: 'full-width', viewport: 'mobile', position: getMobilePos() }), contentPriority(getMobilePos()));
        }
    });

    // Refresh widget data when returning to the index via SPA navigation.
    // `app.previous?.type` is falsy on the very first page load (initial bootstrap
    // already hydrated the forum resource), so this only fires on re-entry.
    extend(IndexPage.prototype, 'oninit', function () {
        if (app.previous?.type) refreshForumData();
    });

    // Start the presence heartbeat. `fireHeartbeat()` does the per-tick gating
    // (session, visibility, setting); `scheduleNextHeartbeat()` owns the cadence,
    // jitter, and failure backoff. `app.forum`/`app.session` aren't reliably
    // populated at initializer top-level, so attribute reads happen inside the tick.
    document.addEventListener('visibilitychange', () => {
        // Returning to a foreground tab: ping now (and reset the schedule) so
        // presence updates promptly instead of waiting out the timer.
        if (document.visibilityState === 'visible') triggerHeartbeatNow();
    });

    // Authenticated users have last_seen_at refreshed when the page itself was
    // served, so they're already counted on first paint and the first tick can
    // wait one interval. Guests don't get that free hit — ping once right away
    // (deferred a tick so app.forum is populated) so they show up immediately
    // rather than after the first interval.
    if (!app.session || !app.session.user) {
        setTimeout(triggerHeartbeatNow, 0);
    } else {
        scheduleNextHeartbeat();
    }
});
