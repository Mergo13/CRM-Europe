// assets/js/dashboard.js
// Updated: robust dashboard script with Google Analytics (optional) and FullCalendar integration
(function () {
    'use strict';

    // ---------- Configuration (optional) ----------
    // If you want to auto-load GA (gtag), set window.GA_MEASUREMENT_ID to your GA4 Measurement ID (e.g. 'G-XXXXXXX')
    // from your server-side template or inline script before this file loads.
    // Example in PHP template: <script>window.GA_MEASUREMENT_ID = '<?php echo $ga_measurement_id; ?>';</script>

    // Endpoint defaults (can be overridden by setting window.DASHBOARD_ENDPOINTS = { ga: '/...', calendar: '/...'} )
    const endpoints = (window.DASHBOARD_ENDPOINTS || {});
    endpoints.ga = endpoints.ga || '/pages/api/ga_realtime.php';
    endpoints.calendar = endpoints.calendar || '/pages/api/calendar_events.php';

    // ---------- Helpers ----------
    function escapeHtml(s) {
        return String(s || '').replace(/[&<>"]+/g, ch => ({'&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;'}[ch] || ch));
    }

    async function safeFetchJson(url, opts = {}) {
        try {
            if (!url || typeof url !== 'string') throw new Error('Invalid URL');
            const res = await fetch(url, Object.assign({ cache: 'no-store' }, opts));
            if (!res.ok) throw new Error('HTTP ' + res.status);
            const txt = await res.text();
            if (!txt) throw new Error('Empty response');
            try {
                return JSON.parse(txt);
            } catch (e) {
                // Some endpoints may return JSON with leading non-json chars; try forgiving parse
                const m = txt.match(/(\{[\s\S]*\}|\[[\s\S]*\])/);
                if (m) return JSON.parse(m[0]);
                throw e;
            }
        } catch (err) {
            console.warn('safeFetchJson error for', url, err);
            return null;
        }
    }

    // Small utility to load an external script and return Promise that resolves when loaded
    function loadScript(src, attrs = {}) {
        return new Promise((resolve, reject) => {
            const s = document.createElement('script');
            s.src = src;
            s.async = true;
            Object.keys(attrs).forEach(k => s.setAttribute(k, attrs[k]));
            s.onload = () => resolve(s);
            s.onerror = (e) => reject(new Error('Failed to load ' + src));
            document.head.appendChild(s);
        });
    }

    // Safe number check
    function toNumber(v, fallback = 0) {
        const n = Number(v);
        return Number.isFinite(n) ? n : fallback;
    }

    // ---------- Google Analytics (optional) ----------
    // If window.GA_MEASUREMENT_ID is set, we will auto-load gtag and provide a sendEvent helper.
    async function initGtagIfNeeded() {
        const id = (window.GA_MEASUREMENT_ID || '').trim();
        if (!id) return; // nothing to do

        // If gtag already present, do nothing
        if (window.gtag) {
            console.debug('gtag already present');
            return;
        }

        // Inject gtag.js
        try {
            await loadScript('https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(id));
            window.dataLayer = window.dataLayer || [];
            window.gtag = function () { window.dataLayer.push(arguments); };
            window.gtag('js', new Date());
            window.gtag('config', id, { send_page_view: false }); // do not auto-send pageview unless you want to
            console.info('gtag initialized for', id);
        } catch (e) {
            console.warn('Failed to load gtag', e);
        }
    }

    function sendGtagEvent(name, params = {}) {
        if (typeof window.gtag !== 'function') return;
        try { window.gtag('event', name, params); } catch (e) { console.warn('gtag event error', e); }
    }

    // ---------- GA Live Widget ----------
    // This widget fetches a minimal realtime JSON from your server endpoint and renders it.
    // Server should return JSON like: { totalActive: 3, pageviewsPerMinute: 12, pages: [{path:'/',count:3}], ... }
    (function gaLiveWidget() {
        const gaEl = document.getElementById('ga-live-view');
        if (!gaEl) return;

        async function refreshGA() {
            const json = await safeFetchJson(endpoints.ga);
            if (!json) {
                gaEl.innerText = 'GA Live: Keine Daten';
                return;
            }

            try {
                const total = toNumber(json.totalActive, 0);
                const pv = toNumber(json.pageviewsPerMinute, 0);
                let html = `<div><strong>Users right now:</strong> ${escapeHtml(String(total))}</div>`;
                html += `<div class="small-muted">Pageviews / min: ${escapeHtml(String(pv))}</div>`;
                if (Array.isArray(json.pages) && json.pages.length) {
                    html += '<ul class="small-muted" style="margin:6px 0 0;padding-left:18px">';
                    (json.pages || []).slice(0,10).forEach(p => {
                        html += '<li>' + escapeHtml((p.path || p.page || '/') + ' — ' + (p.count || p.views || 0)) + '</li>';
                    });
                    html += '</ul>';
                }
                gaEl.innerHTML = html;
            } catch (e) {
                console.warn('GA widget render error', e);
                gaEl.innerText = 'GA Live: Fehler beim Anzeigen der Daten';
            }
        }

        refreshGA();
        // Refresh every 15s but only if tab is visible
        let intervalId = null;
        function startInterval() {
            if (intervalId) return;
            intervalId = setInterval(refreshGA, 15000);
        }
        function stopInterval() {
            if (!intervalId) return;
            clearInterval(intervalId); intervalId = null;
        }
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) stopInterval(); else startInterval();
        });
        startInterval();

        // Add click-to-open detail (optional): send GA event when user clicks the widget
        gaEl.addEventListener('click', () => sendGtagEvent('dashboard_ga_live_click'));
    })();

    // ---------- FullCalendar integration ----------
    (function initCalendar() {
        const calEl = document.getElementById('dashboard-calendar');
        if (!calEl) return;

        // Guard: ensure FullCalendar is present
        const FC = window.FullCalendar || (typeof FullCalendar !== 'undefined' ? FullCalendar : null);
        if (!FC || !FC.Calendar) {
            calEl.innerHTML = '<div class="small-muted">Kalender konnte nicht geladen werden (FullCalendar fehlt).</div>';
            return;
        }

        // Create calendar instance
        const calendar = new FC.Calendar(calEl, {
            initialView: 'dayGridMonth',
            headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek' },
            height: 380,
            events: async function (info, successCallback, failureCallback) {
                try {
                    // Use safeFetchJson so server can return forgiving JSON
                    const params = new URLSearchParams();
                    params.set('start', info.startStr);
                    params.set('end', info.endStr);

                    const url = endpoints.calendar + '?' + params.toString();
                    const data = await safeFetchJson(url);
                    if (!data) return successCallback([]);

                    // Server may return array of events with various field names. Map to FullCalendar's EventInput.
                    const events = (Array.isArray(data) ? data : (data.events || [])).map(ev => {
                        return {
                            id: ev.id || ev.event_id || ev.uid || undefined,
                            title: ev.title || ev.summary || ev.name || '(no title)',
                            start: ev.start || ev.start_time || ev.begin || null,
                            end: ev.end || ev.end_time || ev.finish || null,
                            url: ev.url || ev.link || ev.htmlLink || undefined,
                            extendedProps: ev.extendedProps || ev.meta || ev, // keep original object for detail view
                        };
                    });

                    successCallback(events);
                } catch (err) {
                    console.warn('calendar fetch error', err);
                    failureCallback(err);
                }
            },
            eventClick: function (info) {
                // When clicking an event, open its URL (if any) or show a simple popup
                info.jsEvent.preventDefault();
                if (info.event.url) {
                    window.open(info.event.url, '_blank', 'noopener');
                    sendGtagEvent('dashboard_calendar_event_click', { event_id: info.event.id });
                    return;
                }
                // otherwise show a lightweight modal / tooltip (simple approach)
                const details = [
                    '<strong>' + escapeHtml(info.event.title) + '</strong>',
                    info.event.start ? ('<div>Start: ' + escapeHtml(info.event.start.toISOString()) + '</div>') : '',
                    info.event.end ? ('<div>End: ' + escapeHtml(info.event.end.toISOString()) + '</div>') : '',
                    info.event.extendedProps && Object.keys(info.event.extendedProps).length ? ('<pre style="white-space:pre-wrap">' + escapeHtml(JSON.stringify(info.event.extendedProps, null, 2)) + '</pre>') : ''
                ].join('\n');

                // create or reuse a transient popup
                let popup = document.getElementById('fc-event-popup');
                if (!popup) {
                    popup = document.createElement('div');
                    popup.id = 'fc-event-popup';
                    popup.style.position = 'fixed';
                    popup.style.left = '50%';
                    popup.style.top = '20%';
                    popup.style.transform = 'translateX(-50%)';
                    popup.style.zIndex = 9999;
                    popup.style.background = '#fff';
                    popup.style.padding = '12px';
                    popup.style.border = '1px solid rgba(0,0,0,0.08)';
                    popup.style.boxShadow = '0 8px 24px rgba(0,0,0,0.12)';
                    popup.style.maxWidth = '90%';
                    popup.style.maxHeight = '70%';
                    popup.style.overflow = 'auto';
                    document.body.appendChild(popup);
                    popup.addEventListener('click', () => popup.remove());
                }
                popup.innerHTML = details;
                sendGtagEvent('dashboard_calendar_event_view', { event_id: info.event.id });
            },
            loading: function (isLoading) {
                // optional: show a small loader inside calendar container
                if (isLoading) calEl.classList.add('loading'); else calEl.classList.remove('loading');
            }
        });

        calendar.render();

        // calendar legend: create if missing
        (function ensureLegend() {
            const legend = document.getElementById('calendar-legend');
            if (legend && legend.innerHTML.trim()) return;
            if (!legend) return;
            legend.innerHTML = '<span style="display:inline-block;width:12px;height:12px;background:#3b82f6;margin-right:6px;border-radius:2px"></span>Rechnungen ' +
                '<span style="display:inline-block;width:12px;height:12px;background:#10b981;margin-left:12px;margin-right:6px;border-radius:2px"></span>Angebote';
        })();

    })();

    // ---------- DOM Ready / bootstrap ----------
    document.addEventListener('DOMContentLoaded', function () {
        // Initialize GA if configured
        initGtagIfNeeded().catch(e => console.warn('initGtagIfNeeded', e));

        // Any other bootstrapping you need can go here.

    });

})();
