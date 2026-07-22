/*
 * session_player.js — MARS APRS Analyzer
 *
 * Shared rendering + playback engine for APRS analyzer sessions. Driven entirely
 * by a plain data object (the same shape produced by build_session_bundle), so it
 * renders identically whether the data comes from the live Flask analyzer
 * (event_map.html) or a saved .marsplay.json.gz bundle (player.html).
 *
 * It owns ONLY presentation: the Leaflet map, igate/course layers, tracker
 * filtering, the time-range playback sliders, the tracker/igate/carrier selects,
 * and the show/hide toggles. Live-only concerns (data collection daemon, flush,
 * auth, auto-refresh) stay in event_map.html and are not part of this module.
 *
 * Usage:
 *   const player = initSessionPlayer(data, { storagePrefix: 'aprs_analyzer' });
 *   player.setBeacons(newBeaconArray);   // live refresh
 *   player.beaconCount();                // current loaded beacon count
 *
 * The host HTML must provide the control DOM ids referenced below (see the
 * controls modal markup shared by event_map.html and player.html).
 */
function initSessionPlayer(data, opts) {
    opts = opts || {};
    const storagePrefix = opts.storagePrefix || 'aprs_analyzer';
    const LS_MAP        = storagePrefix + '_view';

    // ── Helpers ────────────────────────────────────────────────────────────
    function esc(s) {
        return String(s ?? '').replace(/[&<>"']/g, c =>
            ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }
    const Q_LABELS = {
        qAR:'received by iGate', qAC:'bidirectional iGate', qAI:'server generated',
        qAX:'rejected by iGate', qAZ:'server generated',    qAS:'third-party iGate',
        qAo:'received without RF', qAO:'received without RF',
    };
    function formatAprsPath(path) {
        if (!path) return '<div class="aprs-path-empty">No path data for this beacon.</div>';
        return '<div class="aprs-path-hops">' + path.split(',').map(hop => {
            const clean = hop.replace('*','');
            let desc = '';
            if (Q_LABELS[clean])        desc = `<span class="aprs-path-desc">${esc(Q_LABELS[clean])}</span>`;
            else if (hop.includes('*')) desc = `<span class="aprs-path-digi">digipeated</span>`;
            return `<div class="aprs-path-hop"><code>${esc(hop)}</code>${desc ? ' '+desc : ''}</div>`;
        }).join('') + '</div>';
    }
    const fmtTime = ts => new Date(ts * 1000).toLocaleString([],
        {month:'short', day:'numeric', hour:'2-digit', minute:'2-digit', second:'2-digit', timeZoneName:'short'});

    // ── Data ───────────────────────────────────────────────────────────────
    const mapCfg          = data.map || {};
    const igates          = data.igates || {};
    const digipeaters     = data.digipeaters || {};
    const course_data     = data.courses || [];
    const mobileCallsigns = new Set(data.mobile_callsigns || data.mobileCallsigns || []);
    const trackerCarriers = data.carriers || {};
    // Callsign → display name; explicit tracker names win, historical names fill gaps
    const trackerNames = Object.assign({}, data.tracker_names || {});
    (data.trackers || []).forEach(t => {
        trackerNames[t.callsign] = t.name;
        if (t.mobile_pair) trackerNames[t.mobile_pair] = t.name;
    });
    let beacons = data.beacons || [];

    // ── Map setup ──────────────────────────────────────────────────────────
    const map = L.map('map');
    const bgUrl = localStorage.getItem('aprs_bg_url') || mapCfg.bg_url
        || 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
    L.tileLayer(bgUrl, {
        maxZoom: 19,
        attribution: '&copy; <a href="http://www.openstreetmap.org/copyright">OpenStreetMap</a>'
    }).addTo(map);

    let defaultView = { lat: mapCfg.lat ?? 37.9757, lon: mapCfg.lon ?? -122.612, zoom: mapCfg.zoom ?? 12 };
    try {
        const analyzerSaved = localStorage.getItem(LS_MAP);
        if (analyzerSaved) {
            defaultView = JSON.parse(analyzerSaved);
        } else {
            const mainSaved = localStorage.getItem('aprs_map_view');
            if (mainSaved) defaultView = JSON.parse(mainSaved);
        }
    } catch(e) {}
    map.setView([defaultView.lat, defaultView.lon], defaultView.zoom);

    // ── Beacon time-range readout (bottom-center map overlay) ──────────────
    // Surfaces the start→end of the currently displayed beacon window right on
    // the map, updated live as the time-range sliders move. Self-contained
    // (inline-styled, appended to the page) so both host pages get it for free.
    const rangeReadout = document.createElement('div');
    rangeReadout.id = 'beacon-range-readout';
    rangeReadout.style.cssText =
        'position:fixed;bottom:16px;left:50%;transform:translateX(-50%);z-index:900;' +
        'background:rgba(255,255,255,0.92);border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,.25);' +
        'padding:5px 14px;font-size:12px;color:#333;font-weight:600;white-space:nowrap;' +
        'max-width:94vw;overflow:hidden;text-overflow:ellipsis;pointer-events:none;display:none;';
    document.body.appendChild(rangeReadout);

    // ── Top-right: Reset Map ───────────────────────────────────────────────
    const ResetControl = L.Control.extend({
        options: { position: 'topright' },
        onAdd: function() {
            const wrap = L.DomUtil.create('div', 'map-corner-btns');
            const btn  = L.DomUtil.create('button', 'map-corner-btn', wrap);
            btn.title  = 'Reset Map';
            btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/><path d="M3 3v5h5"/></svg>';
            L.DomEvent.on(btn, 'click', L.DomEvent.stopPropagation)
                       .on(btn, 'click', () => map.setView([defaultView.lat, defaultView.lon], defaultView.zoom));
            L.DomEvent.disableClickPropagation(wrap);
            return wrap;
        }
    });
    new ResetControl().addTo(map);

    // ── Bottom-left: Controls button + legend ──────────────────────────────
    const BottomButtons = L.Control.extend({
        options: { position: 'bottomleft' },
        onAdd: function() {
            const wrap = L.DomUtil.create('div');
            wrap.style.cssText = 'display:flex;flex-direction:column;gap:6px;';

            const ctrlBtn = L.DomUtil.create('button', 'map-float-btn', wrap);
            ctrlBtn.title = 'Open controls';
            ctrlBtn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="20" y2="18"/><circle cx="8" cy="6" r="2" fill="white"/><circle cx="16" cy="12" r="2" fill="white"/><circle cx="10" cy="18" r="2" fill="white"/></svg> Controls';
            L.DomEvent.on(ctrlBtn, 'click', L.DomEvent.stopPropagation)
                       .on(ctrlBtn, 'click', () => document.getElementById('controls-modal').showModal());

            const legend = L.DomUtil.create('div', '', wrap);
            legend.style.cssText = 'background:rgba(255,255,255,0.9);border-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,.3);padding:5px 9px;font-size:12px;display:flex;flex-direction:column;gap:3px;pointer-events:none;';
            legend.innerHTML =
                '<div style="display:flex;align-items:center;gap:5px"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#e74c3c;flex-shrink:0"></span><span>Radio</span></div>' +
                '<div style="display:flex;align-items:center;gap:5px"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#27ae60;flex-shrink:0"></span><span>Cellular</span></div>';

            const exitBtn = L.DomUtil.create('a', '', wrap);
            exitBtn.href = 'https://marsaprs.org/';
            exitBtn.textContent = 'Exit';
            exitBtn.style.cssText = 'display:block;text-align:center;background:rgba(255,255,255,0.9);border-radius:6px;box-shadow:0 2px 8px rgba(0,0,0,.3);padding:5px 9px;font-size:12px;color:#333;text-decoration:none;';
            exitBtn.onmouseover = () => exitBtn.style.background = '#fff';
            exitBtn.onmouseout  = () => exitBtn.style.background = 'rgba(255,255,255,0.9)';
            L.DomEvent.on(exitBtn, 'click', L.DomEvent.stopPropagation);

            const copy = L.DomUtil.create('div', '', wrap);
            copy.style.cssText = 'font-size:10px;color:#555;padding:2px 4px;pointer-events:none;';
            copy.textContent = '©2026 Alex Peck & Doug Kaye';

            L.DomEvent.disableClickPropagation(wrap);
            return wrap;
        }
    });
    new BottomButtons().addTo(map);

    // ── IGates + Digipeaters ───────────────────────────────────────────────
    const igateNameMarkers = [];
    Object.keys(igates).forEach(key => {
        const mk = L.circleMarker([igates[key].lat, igates[key].lng], {radius:10, color:'red'})
            .addTo(map)
            .bindTooltip(igates[key].name, {permanent:true, direction:'bottom', offset:[0,10]});
        mk.openTooltip();
        igateNameMarkers.push(mk);
    });
    Object.keys(digipeaters).forEach(key => {
        const mk = L.circleMarker([digipeaters[key].lat, digipeaters[key].lng], {radius:10, color:'black'})
            .addTo(map)
            .bindTooltip(digipeaters[key].name, {permanent:true, direction:'bottom', offset:[0,10]});
        mk.openTooltip();
        igateNameMarkers.push(mk);
    });

    // ── Courses ────────────────────────────────────────────────────────────
    const courseGroup = L.layerGroup().addTo(map);
    course_data.forEach(c => {
        if (c.coords && c.coords.length > 1) {
            const optsC = {color: c.color, weight: 2};
            if (c.dash) optsC.dashArray = c.dash;
            L.polyline(c.coords, optsC).addTo(courseGroup);
        }
    });

    // ── Tracker state ──────────────────────────────────────────────────────
    const trackerGroup = L.layerGroup().addTo(map);
    let time_tooltips        = [];
    let display_range_start  = 0;
    let display_range_end    = 1000000;
    let currentCallsigns     = new Set();
    let showAllTrackers      = true;
    let selectedIgates       = new Set(['all']);
    let current_beacon_count = 0;
    let active_beacon_list   = [];
    let show_full_path       = false;
    let showTracks           = true;
    let showRadio            = true;
    let showCellular         = true;
    let showRadioLinks       = true;   // beacon → receiving igate/digipeater lines
    let showNames            = true;
    let selectedCarriers     = new Set(['all']);

    function normalizeCarrier(raw) {
        if (!raw) return 'Other';
        const r = raw.toLowerCase();
        if (r.includes('at&t')) return 'AT&T';
        if (r.includes('comcast')) return 'Comcast';
        if (r.includes('space exploration')) return 'Starlink';
        if (r.includes('t-mobile')) return 'T-Mobile';
        if (r.includes('verizon')) return 'Verizon';
        return 'Other';
    }

    const igate_selector = document.getElementById('igate-select');

    function applyTrackerSelection() {
        const trackerSel = document.getElementById('tracker-select');
        const selected = Array.from(trackerSel.selectedOptions).map(o => o.value);
        if (selected.length === 0 || selected.includes('all')) {
            showAllTrackers  = true;
            currentCallsigns = new Set();
        } else {
            showAllTrackers  = false;
            currentCallsigns = new Set();
            selected.forEach(cs => {
                currentCallsigns.add(cs);
                const opt  = trackerSel.querySelector(`option[value="${CSS.escape(cs)}"]`);
                const pair = opt ? (opt.dataset.mobilePair || null) : null;
                if (pair) currentCallsigns.add(pair);
            });
        }
        localStorage.setItem(storagePrefix + '_tracker', JSON.stringify(selected));
        update_filtered_beacon_list(false);
    }

    function applyIgateSelection() {
        const selected = Array.from(igate_selector.selectedOptions).map(o => o.value);
        selectedIgates = new Set(selected.length ? selected : ['all']);
        update_filtered_beacon_list(false);
    }

    // ── Filtering / playback range ─────────────────────────────────────────
    function update_filtered_beacon_list(range_update_only) {
        active_beacon_list   = [];
        const candidate_list = [];
        current_beacon_count = 0;
        const igateAll = selectedIgates.has('all') || selectedIgates.size === 0;
        beacons.forEach(b => {
            if (!showAllTrackers && !currentCallsigns.has(b.callsign)) return;
            const mob = mobileCallsigns.has(b.callsign);
            if ( mob && !showCellular) return;
            if (!mob && !showRadio)    return;
            if (mob && !selectedCarriers.has('all') && !selectedCarriers.has(normalizeCarrier(trackerCarriers[b.callsign]))) return;
            if (!igateAll && !Array.from(selectedIgates).some(ig => b.path.includes(ig))) return;
            candidate_list.push(b);
            current_beacon_count++;
        });
        if (!range_update_only) {
            const ss = document.getElementById('set-first-beacon');
            const es = document.getElementById('set-last-beacon');
            display_range_start = 0;
            display_range_end   = current_beacon_count;
            ss.max = String(Math.max(current_beacon_count - 1, 0));
            es.max = String(current_beacon_count);
            if (ss.value !== '0') ss.value = '0';
            es.value = String(current_beacon_count);
        }
        for (let i = display_range_start; i < display_range_end; i++)
            active_beacon_list.push(candidate_list[i]);
        const _bsl = document.getElementById('beacon-start-label');
        const _bel = document.getElementById('beacon-end-label');
        const _fa  = candidate_list[display_range_start];
        const _la  = candidate_list[Math.min(display_range_end, candidate_list.length) - 1];
        if (_bsl) _bsl.textContent = _fa ? fmtTime(_fa.time) : '—';
        if (_bel) _bel.textContent = _la ? fmtTime(_la.time) : '—';
        if (_fa && _la) {
            rangeReadout.innerHTML =
                '<span style="color:#888;font-weight:normal;margin-right:7px">Beacon range</span>'
                + esc(fmtTime(_fa.time)) + ' <span style="color:#aaa">→</span> ' + esc(fmtTime(_la.time));
            rangeReadout.style.display = 'block';
        } else {
            rangeReadout.style.display = 'none';
        }
        draw_tracker();
    }

    // ── Drawing ────────────────────────────────────────────────────────────
    function draw_tracker() {
        trackerGroup.clearLayers();
        time_tooltips = [];
        let last_cs    = '';
        let last_coord = [];
        const lastPosByCallsign = {};
        active_beacon_list.forEach(b => {
            const mob   = mobileCallsigns.has(b.callsign);
            const color = mob ? '#27ae60' : '#e74c3c';
            const t     = fmtTime(b.time);
            const coord = [b.latitude, b.longitude];

            // Radio links: only RF beacons are received by an igate/digipeater,
            // so cellular positions (injected straight into APRS-IS) have no
            // link to draw.
            if (!mob && showRadioLinks) {
                if (Object.hasOwn(b, 'rx_lat')) {
                    L.polyline([coord, [b.rx_lat, b.rx_lng]], {color:'red', weight:1}).addTo(trackerGroup);
                } else if (Object.hasOwn(igates, b.receiver)) {
                    const pts = [coord];
                    const singleIg = (selectedIgates.size === 1 && !selectedIgates.has('all'))
                        ? [...selectedIgates][0] : null;
                    const fDigi = singleIg ? Object.hasOwn(digipeaters, singleIg) : false;
                    if (show_full_path && !fDigi)
                        Object.keys(digipeaters).forEach(k => { if (b.path.includes(k)) pts.push([digipeaters[k].lat, digipeaters[k].lng]); });
                    pts.push(fDigi
                        ? [digipeaters[singleIg].lat, digipeaters[singleIg].lng]
                        : [igates[b.receiver].lat, igates[b.receiver].lng]);
                    L.polyline(pts, {color:'red', weight:1}).addTo(trackerGroup);
                }
            }

            if (b.callsign === last_cs && showTracks)
                L.polyline([last_coord, coord], {color, weight:2, dashArray:'4 6'}).addTo(trackerGroup);
            last_cs    = b.callsign;
            last_coord = coord;
            lastPosByCallsign[b.callsign] = {coord, color};

            const mk = L.circleMarker(coord, {radius:5, color, weight:2})
                .addTo(trackerGroup)
                .bindPopup(`<b>${trackerNames[b.callsign] || b.callsign} ⟶ ${b.receiver}</b><br>${t}<br>${b.path}`);
            const _name = esc(trackerNames[b.callsign] || b.callsign);
            let _tip;
            if (mob) {
                const _carrier = trackerCarriers[b.callsign] || '';
                _tip = `${_name}<br><span style="color:#888;font-size:11px;font-weight:normal">${t}</span>`
                     + (_carrier ? `<div style="color:#888;font-size:11px;font-weight:normal;margin-top:2px">${esc(_carrier)}</div>` : '');
            } else {
                _tip = `<div style="min-width:260px;font-weight:normal">`
                     + `<b>${_name}</b><br><span style="color:#888;font-size:11px">${t}</span>`
                     + `<div style="color:#888;font-size:11px;margin-top:3px">Radio</div>`
                     + formatAprsPath(b.path)
                     + `</div>`;
            }
            time_tooltips.push(mk.bindTooltip(_tip, {direction:'right', offset:[10,0]}));
        });

        if (showNames) {
            Object.entries(lastPosByCallsign).forEach(([cs, {coord, color}]) => {
                const name = trackerNames[cs] || cs;
                L.marker(coord, {
                    icon: L.divIcon({
                        className: '',
                        html: `<span style="font-size:11px;font-weight:600;color:#000;` +
                              `background:rgba(255,255,255,0.85);padding:1px 4px;border-radius:3px;` +
                              `white-space:nowrap;pointer-events:none">${esc(name)}</span>`,
                        iconAnchor: [-9, 5]
                    }),
                    interactive: false
                }).addTo(trackerGroup);
            });
        }
    }

    // ── Populate selects from data ─────────────────────────────────────────
    function populateTrackerSelect() {
        const sel = document.getElementById('tracker-select');
        (data.trackers || []).forEach(item => {
            const opt = new Option(item.label, item.callsign);
            if (item.mobile_pair) opt.dataset.mobilePair = item.mobile_pair;
            sel.add(opt);
        });
    }
    function populateIgateSelect() {
        const sel = igate_selector;
        Object.keys(igates).forEach(cs =>
            sel.add(new Option(`${igates[cs].name} (${cs})`, cs)));
        Object.keys(digipeaters).forEach(cs =>
            sel.add(new Option(`${digipeaters[cs].name} (${cs})`, cs)));
    }

    // ── Wire controls ──────────────────────────────────────────────────────
    function wireControls() {
        const byId = id => document.getElementById(id);

        byId('ctrl-close')?.addEventListener('click', () => byId('controls-modal').close());

        byId('show-tracks')?.addEventListener('change', e => { showTracks = e.target.checked; draw_tracker(); });
        byId('show-radio')?.addEventListener('change', e => { showRadio = e.target.checked; update_filtered_beacon_list(false); });
        byId('show-cellular')?.addEventListener('change', e => { showCellular = e.target.checked; update_filtered_beacon_list(false); });
        byId('show-radio-links')?.addEventListener('change', e => {
            showRadioLinks = e.target.checked;
            // The links terminate on radio beacons, so turning them on without
            // those beacons visible would leave lines pointing at nothing.
            if (showRadioLinks && !showRadio) {
                showRadio = true;
                const rb = byId('show-radio');
                if (rb) rb.checked = true;
                update_filtered_beacon_list(false);   // redraws
                return;
            }
            draw_tracker();
        });
        byId('show-course')?.addEventListener('change', e => { e.target.checked ? map.addLayer(courseGroup) : map.removeLayer(courseGroup); });
        byId('show-names')?.addEventListener('change', e => {
            showNames = e.target.checked;
            igateNameMarkers.forEach(mk => showNames ? mk.openTooltip() : mk.closeTooltip());
            draw_tracker();
        });

        byId('tracker-select')?.addEventListener('change', applyTrackerSelection);
        igate_selector?.addEventListener('change', applyIgateSelection);
        byId('carrier-select')?.addEventListener('change', function() {
            const sel = Array.from(this.selectedOptions).map(o => o.value);
            selectedCarriers = new Set(sel.length ? sel : ['all']);
            update_filtered_beacon_list(false);
        });

        byId('set-first-beacon')?.addEventListener('input', e => {
            display_range_start = parseInt(e.target.value, 10);
            if (display_range_start >= display_range_end) {
                display_range_end = display_range_start + 1;
                byId('set-last-beacon').value = String(display_range_end);
            }
            update_filtered_beacon_list(true);
        });
        byId('set-last-beacon')?.addEventListener('input', e => {
            display_range_end = parseInt(e.target.value, 10);
            if (display_range_end <= display_range_start) {
                display_range_start = display_range_end - 1;
                byId('set-first-beacon').value = String(display_range_start);
            }
            update_filtered_beacon_list(true);
        });

        byId('save-map-btn')?.addEventListener('click', function() {
            const c = map.getCenter();
            defaultView = { lat: parseFloat(c.lat.toFixed(6)), lon: parseFloat(c.lng.toFixed(6)), zoom: map.getZoom() };
            localStorage.setItem(LS_MAP, JSON.stringify(defaultView));
            this.textContent = 'Map Saved ✓';
            this.classList.add('saved');
            setTimeout(() => { this.textContent = 'Save Map Position'; this.classList.remove('saved'); }, 2000);
        });

        // Restore tracker selection from localStorage
        const trackerSel = byId('tracker-select');
        try {
            const saved = JSON.parse(localStorage.getItem(storagePrefix + '_tracker') || '["all"]');
            let anyRestored = false;
            saved.forEach(cs => {
                const opt = trackerSel.querySelector(`option[value="${CSS.escape(cs)}"]`);
                if (opt) { opt.selected = true; anyRestored = true; }
            });
            if (anyRestored && !saved.includes('all'))
                trackerSel.querySelector('option[value="all"]').selected = false;
        } catch(e) {}

        // Draggable controls modal
        (function() {
            const modal  = byId('controls-modal');
            const handle = byId('ctrl-header');
            if (!modal || !handle) return;
            let drag = null;
            handle.style.cursor = 'grab';
            handle.addEventListener('mousedown', e => {
                if (e.target.closest('button')) return;
                const rect = modal.getBoundingClientRect();
                if (!modal.style.left) {
                    modal.style.margin = '0';
                    modal.style.left = rect.left + 'px';
                    modal.style.top  = rect.top  + 'px';
                }
                drag = { sx: e.clientX, sy: e.clientY, l: parseFloat(modal.style.left), t: parseFloat(modal.style.top) };
                handle.style.cursor = 'grabbing';
                e.preventDefault();
            });
            document.addEventListener('mousemove', e => {
                if (!drag) return;
                const maxL = window.innerWidth  - modal.offsetWidth;
                const maxT = window.innerHeight - modal.offsetHeight;
                modal.style.left = Math.max(0, Math.min(drag.l + e.clientX - drag.sx, maxL)) + 'px';
                modal.style.top  = Math.max(0, Math.min(drag.t + e.clientY - drag.sy, maxT)) + 'px';
            });
            document.addEventListener('mouseup', () => { if (drag) { drag = null; handle.style.cursor = 'grab'; } });
        })();
    }

    // ── Boot ───────────────────────────────────────────────────────────────
    populateTrackerSelect();
    populateIgateSelect();
    wireControls();
    applyTrackerSelection();  // also triggers the first draw

    // ── Public controller ──────────────────────────────────────────────────
    return {
        map,
        beaconCount: () => beacons.length,
        redraw: () => update_filtered_beacon_list(false),
        setBeacons: (list) => {
            beacons = list || [];
            // Add any newly seen callsigns (with known names) to the tracker select
            const sel = document.getElementById('tracker-select');
            const ex  = new Set(Array.from(sel.options).map(o => o.value));
            beacons.forEach(b => {
                if (!ex.has(b.callsign) && trackerNames[b.callsign]) {
                    sel.add(new Option(`${trackerNames[b.callsign]} (${b.callsign})`, b.callsign));
                    ex.add(b.callsign);
                }
            });
            update_filtered_beacon_list(false);
        },
    };
}
