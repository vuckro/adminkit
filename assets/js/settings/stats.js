/** AdminKit stats screen. Loaded only on its own admin page; no build step. */
(function () {
	'use strict';
	window.AdminKitScreens = window.AdminKitScreens || {};
	window.AdminKitScreens.stats = function (context) {
		var D = context.D;
		var I = context.I;
		var apiFetch = context.apiFetch;
		var el = context.el;
		var intro = context.intro;

		function buildStats() {
			var st = D.stats || {};
			var initial = st.state || {};
			var today = st.today || '';
			var presets =
				st.presets && st.presets.length
					? st.presets
					: [
							{ id: '30d', label: '30 days' },
							{ id: 'custom', label: 'Custom' }
						];
			var current = {
				preset: initial.preset || '30d',
				start: initial.start || today,
				end: initial.end || today
			};
			var pendingDates = null;
			// Live-view auto-refresh state. Cadence comes from PHP (LIVE_REFRESH_MS),
			// clamped to a sane floor. Timer is set up only when preset === 'live'.
			var liveTimer = null;
			var liveInterval = st.liveRefresh && st.liveRefresh >= 2000 ? st.liveRefresh : 10000;

			var p = el('section', { class: 'ak-panel ak-stats', role: 'tabpanel' }, [intro(I.statsIntro)]);

			// Header: active pill + preset chips + (revealed when Custom) date inputs.
			var active = el('span', { class: 'ak-stats__active', hidden: 'hidden' });

			var presetsWrap = el('div', { class: 'ak-stats__presets', role: 'group' });
			var presetBtns = {};
			presets.forEach(function (per) {
				var on = per.id === current.preset;
				var live = per.id === 'live';
				var cls = 'ak-stats__preset' + (on ? ' is-active' : '') + (live ? ' ak-stats__preset--live' : '');
				var attrs = {
					type: 'button',
					class: cls,
					'aria-pressed': on ? 'true' : 'false',
					onclick: function () {
						onPresetClick(per.id);
					}
				};
				// Live carries a pulse dot (calm by default; CSS only animates when active).
				var b = live
					? el('button', attrs, [
							el('span', { class: 'ak-stats__pulse', 'aria-hidden': 'true' }),
							document.createTextNode(per.label)
						])
					: el('button', ((attrs.text = per.label), attrs));
				presetBtns[per.id] = b;
				presetsWrap.appendChild(b);
			});

			var startIn = el('input', {
				type: 'date',
				class: 'ak-stats__date',
				value: current.start,
				max: today,
				'aria-label': I.statsRangeFrom || 'From'
			});
			var endIn = el('input', {
				type: 'date',
				class: 'ak-stats__date',
				value: current.end,
				max: today,
				'aria-label': I.statsRangeTo || 'To'
			});
			startIn.addEventListener('change', scheduleDates);
			endIn.addEventListener('change', scheduleDates);

			var customWrap = el('div', { class: 'ak-stats__custom' }, [
				el('span', { class: 'ak-stats__range-lbl', text: I.statsRangeFrom || 'From' }),
				startIn,
				el('span', { class: 'ak-stats__range-lbl', text: I.statsRangeTo || 'To' }),
				endIn
			]);
			if (current.preset !== 'custom') {
				customWrap.hidden = true;
			}

			var rangeWrap = el(
				'div',
				{
					class: 'ak-stats__range',
					role: 'group',
					'aria-label': I.statsRangeLabel || 'Date range',
					'data-ak-range-mode': current.preset === 'live' ? 'live' : current.preset === 'custom' ? 'custom' : 'preset'
				},
				[presetsWrap, customWrap]
			);
			p.appendChild(el('div', { class: 'ak-stats__head' }, [active, rangeWrap]));

			// Body (swapped on each load).
			var body = el('div', { class: 'ak-stats__body' });
			p.appendChild(body);

			function setPresetActive(id) {
				Object.keys(presetBtns).forEach(function (key) {
					var on = key === id;
					presetBtns[key].classList.toggle('is-active', on);
					presetBtns[key].setAttribute('aria-pressed', on ? 'true' : 'false');
				});
				var isCustom = id === 'custom';
				var isLive = id === 'live';
				customWrap.hidden = !isCustom;
				rangeWrap.setAttribute('data-ak-range-mode', isLive ? 'live' : isCustom ? 'custom' : 'preset');
			}

			function onPresetClick(id) {
				// Leaving Live → kill the timer before doing anything else.
				if (id !== 'live') {
					stopLive();
				}
				if (id === 'custom') {
					// Local reveal only; load() fires when dates actually change.
					setPresetActive('custom');
					if (startIn) {
						startIn.focus();
					}
					return;
				}
				current.preset = id;
				setPresetActive(id);
				load();
			}

			function scheduleDates() {
				if (pendingDates) {
					clearTimeout(pendingDates);
				}
				pendingDates = setTimeout(function () {
					pendingDates = null;
					current.preset = 'custom';
					current.start = startIn.value;
					current.end = endIn.value;
					load();
				}, 200);
			}

			function load(silent) {
				if (!apiFetch || !st.route) {
					body.textContent = I.statsNone || 'No data';
					return;
				}
				if (!silent) {
					p.classList.add('is-loading');
				}
				var base = st.route.charAt(0) === '/' ? st.route : '/' + st.route;
				var path = base + '?preset=' + encodeURIComponent(current.preset);
				if (current.preset === 'custom') {
					path += '&start=' + encodeURIComponent(current.start) + '&end=' + encodeURIComponent(current.end);
				}
				apiFetch({ path: path })
					.then(function (res) {
						if (res && res.start && res.end) {
							// Server may clamp/swap — resync local state and the inputs.
							current.preset = res.preset || current.preset;
							current.start = res.start;
							current.end = res.end;
							startIn.value = res.start;
							endIn.value = res.end;
							setPresetActive(current.preset);
						}
						render(res || {});
					})
					.catch(function () {
						if (!silent) {
							body.textContent = I.statsNone || 'No data';
						}
					})
					.then(function () {
						if (!silent) {
							p.classList.remove('is-loading');
						}
						syncLivePolling();
					});
			}

			// Polling lifecycle for Live mode. Three gates: preset, panel visibility,
			// tab visibility — drop any one and we stop the timer; re-meet them all
			// and a single scheduleLive() rearms us.
			function stopLive() {
				if (liveTimer) {
					clearTimeout(liveTimer);
					liveTimer = null;
				}
			}
			function scheduleLive(delay) {
				stopLive();
				liveTimer = setTimeout(tickLive, delay);
			}
			function syncLivePolling() {
				if (current.preset !== 'live' || p.hidden || document.hidden) {
					stopLive();
					return;
				}
				if (liveTimer) {
					return;
				}
				scheduleLive(liveInterval);
			}
			function tickLive() {
				liveTimer = null;
				if (current.preset !== 'live' || p.hidden || document.hidden) {
					return;
				}
				load(true);
			}
			document.addEventListener('visibilitychange', syncLivePolling);

			function num(n) {
				try {
					return Number(n || 0).toLocaleString();
				} catch (e) {
					return String(n || 0);
				}
			}

			// Last live count rendered, so each refresh can TICK from the old value to the
			// new one instead of snapping (the polling already happens — this is just the
			// in-place tween, no extra requests).
			var lastLiveCount = null;
			function animateCount(elNum, from, to) {
				if (!elNum) {
					return;
				}
				from = from | 0;
				to = to | 0;
				var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
				if (from === to || reduce || !window.requestAnimationFrame) {
					elNum.textContent = num(to);
					return;
				}
				var start = null,
					dur = 600;
				function step(ts) {
					if (null === start) {
						start = ts;
					}
					var p = Math.min(1, (ts - start) / dur);
					var e = 1 - Math.pow(1 - p, 3); // easeOutCubic
					elNum.textContent = num(Math.round(from + (to - from) * e));
					if (p < 1) {
						requestAnimationFrame(step);
					}
				}
				requestAnimationFrame(step);
			}

			function render(res) {
				body.innerHTML = '';

				// Live mode has its own shape (no totals, no series) — the big count IS
				// the active number, so the header pill would be redundant. Hide it here;
				// it shows on the PERIOD views instead, as live context.
				if (res.preset === 'live') {
					active.hidden = true;
					renderLive(res);
					return;
				}

				// Period presets — surface the "active now" pill (live context next to
				// the historical totals).
				var a = res.active | 0;
				if (a > 0) {
					active.hidden = false;
					active.innerHTML = '';
					active.appendChild(el('span', { class: 'ak-stats__active-dot' }));
					active.appendChild(document.createTextNode(num(a) + ' ' + (I.statsActive || 'active now')));
				} else {
					active.hidden = true;
				}

				var totals = res.totals || { uniques: 0, pageviews: 0 };

				// Metrics — ALWAYS shown (a clear "0 / 0" beats a blank panel when a short
				// window like Today has no views yet). Unique visitors / Page views, each
				// with a ▲/▼ trend vs the previous equal-length period, then extension tiles.
				var prev = res.previous || null;
				var tiles = [
					metric(
						num(totals.uniques),
						I.statsUniques || 'Unique visitors',
						prev ? trendFrom(totals.uniques, prev.uniques) : null
					),
					metric(
						num(totals.pageviews),
						I.statsPageviews || 'Page views',
						prev ? trendFrom(totals.pageviews, prev.pageviews) : null
					)
				].concat(extraCards(res.cards || []));
				body.appendChild(el('div', { class: 'ak-stats__metrics' }, tiles));

				if (!(totals.pageviews > 0)) {
					body.appendChild(el('p', { class: 'ak-stats__empty', text: I.statsNoData || 'No traffic data yet.' }));
					return;
				}

				// Line/area chart (page views per day). null for a single day (no line to
				// draw) — append only when present.
				var c = chart(res.series || []);
				if (c) {
					body.appendChild(c);
				}

				// Two full lists.
				body.appendChild(
					el('div', { class: 'ak-stats__cols' }, [pageList(res.pages || []), sourceList(res.sources || [])])
				);
			}

			// Live-mode body: oversized active-now count, then "Currently viewing"
			// (pages) and "Coming from" (sources) lists scoped to the active visitors
			// in the last few minutes. Same list shell as the period view so the
			// visual rhythm is preserved.
			function renderLive(res) {
				var count = res.active | 0;
				var numEl = el('span', { class: 'ak-stats__live-num' });
				body.appendChild(
					el('div', { class: 'ak-stats__live-headline' }, [
						numEl,
						el('span', {
							class: 'ak-stats__live-lbl',
							text:
								count === 1
									? I.statsLiveHeadOne || 'visitor active right now'
									: I.statsLiveHeadMany || 'visitors active right now'
						})
					])
				);
				// Tick from the previous count → the new one (count up from 0 on first load).
				animateCount(numEl, null === lastLiveCount ? 0 : lastLiveCount, count);
				lastLiveCount = count;
				if (0 === count) {
					body.appendChild(
						el('p', { class: 'ak-stats__live-empty', text: I.statsLiveEmpty || 'Nobody is on the site right now.' })
					);
					return;
				}
				body.appendChild(
					el('div', { class: 'ak-stats__cols' }, [
						liveList(I.statsLiveViewing || 'Currently viewing', res.pages || [], true),
						liveList(I.statsLiveFrom || 'Coming from', res.sources || [], false)
					])
				);
			}

			function liveList(heading, rows, isPages) {
				var box = listShell(
					heading,
					isPages ? I.statsColPage || 'Page' : I.statsColSource || 'Source',
					I.statsLiveColViewers || 'Viewers'
				);
				if (!rows.length) {
					box.appendChild(el('p', { class: 'ak-stats__none', text: I.statsNone || 'No data' }));
					return box;
				}
				var ul = el('ul', { class: 'ak-stats__rows' });
				rows.forEach(function (r) {
					var nameEl;
					if (isPages) {
						nameEl = el(
							'a',
							{
								class: 'ak-stats__row-name',
								href: r.url || '#',
								target: '_blank',
								rel: 'noopener',
								title: r.path || ''
							},
							[
								el('span', { class: 'ak-stats__row-title', text: r.title || r.path || '' }),
								el('span', { class: 'ak-stats__row-sub', text: r.path || '' })
							]
						);
					} else {
						var label = r.name === 'direct' ? I.statsDirect || 'Direct' : r.name || '';
						nameEl = el('span', { class: 'ak-stats__row-name', title: label, text: label });
					}
					ul.appendChild(
						el('li', { class: 'ak-stats__row' }, [
							nameEl,
							el('span', { class: 'ak-stats__row-val', text: num(r.viewers) })
						])
					);
				});
				box.appendChild(ul);
				return box;
			}

			function metric(value, label, trend) {
				var top = [el('span', { class: 'ak-stats__metric-num', text: value })];
				if (trend) {
					top.push(trendBadge(trend));
				}
				return el('div', { class: 'ak-stats__metric' }, [
					el('div', { class: 'ak-stats__metric-row' }, top),
					el('span', { class: 'ak-stats__metric-lbl', text: label })
				]);
			}

			// Build a trend descriptor from current vs previous totals. With a baseline,
			// it's a signed percentage. With NO baseline (previous = 0) we can't show a
			// percentage (÷0), so: new traffic → a plain ▲ (it IS up, vs nothing); still
			// nothing → no badge. This way the indicator shows on every range, not just
			// the ones whose prior window happens to have data.
			// Returns { dir: 'up'|'down'|'flat', text } or null.
			function trendFrom(cur, prev) {
				cur = cur | 0;
				prev = prev | 0;
				if (prev <= 0) {
					return cur > 0 ? { dir: 'up', text: '' } : null;
				}
				var pct = Math.round(((cur - prev) / prev) * 100);
				return {
					dir: pct > 0 ? 'up' : pct < 0 ? 'down' : 'flat',
					text: (pct > 0 ? '+' : '') + pct + '%'
				};
			}

			function trendBadge(trend) {
				var arrow = trend.dir === 'up' ? '▲' : trend.dir === 'down' ? '▼' : '→';
				// No baseline (text empty) → "New" instead of a bare arrow, so the badge
				// always reads as a labelled value (a % or "New").
				return el('span', {
					class: 'ak-stats__trend ak-stats__trend--' + trend.dir,
					title: I.statsVsPrev || 'vs previous period',
					text: arrow + ' ' + (trend.text || I.statsTrendNew || 'New')
				});
			}

			// Extension tiles (res.cards) — same visual shell as a native metric, with an
			// optional secondary line and the integration's own trend badge.
			function extraCards(cards) {
				return (cards || []).map(function (c) {
					var tile = metric(c.value || '', c.label || '', c.trend || null);
					if (c.sub) {
						tile.appendChild(el('span', { class: 'ak-stats__metric-sub', text: c.sub }));
					}
					return tile;
				});
			}

			// A lightweight SVG line/area chart — page views over the period, drawn as a
			// single line with a soft accent fill beneath (same idiom as the dashboard
			// sparkline). Linear, not bars, so a long sparse range still reads as a
			// trend instead of empty blocks. DOM-built — values are plain numbers, no
			// markup injection; the line uses non-scaling-stroke (CSS) so it stays crisp
			// when the viewBox stretches to the container width.
			function chart(series) {
				// Need ≥2 points for a line — a single day (Today) shows just the metrics
				// + lists, no chart card at all (null → caller skips it).
				if (series.length < 2) {
					return null;
				}
				var wrap = el('div', { class: 'ak-stats__chart' });
				var max = 1;
				series.forEach(function (d) {
					if ((d.pageviews | 0) > max) {
						max = d.pageviews | 0;
					}
				});

				var NS = 'http://www.w3.org/2000/svg';
				var W = 600,
					H = 120,
					px = 1,
					py = 8;
				var iw = W - 2 * px,
					ih = H - 2 * py,
					base = H - py,
					n = series.length;
				function xAt(i) {
					return n === 1 ? px + iw / 2 : px + (i * iw) / (n - 1);
				}
				function yAt(v) {
					return py + ih * (1 - (v | 0) / max);
				}

				var line = '';
				var area = 'M' + xAt(0).toFixed(2) + ' ' + base.toFixed(2) + ' ';
				series.forEach(function (d, i) {
					var x = xAt(i).toFixed(2),
						y = yAt(d.pageviews).toFixed(2);
					line += (i === 0 ? 'M' : 'L') + x + ' ' + y + ' ';
					area += 'L' + x + ' ' + y + ' ';
				});
				area += 'L' + xAt(n - 1).toFixed(2) + ' ' + base.toFixed(2) + ' Z';

				var svg = document.createElementNS(NS, 'svg');
				svg.setAttribute('class', 'ak-stats__chart-svg');
				svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H);
				svg.setAttribute('preserveAspectRatio', 'none');
				// Hairline y-reference gridlines (top = max, middle, bottom = 0).
				[py, py + ih / 2, base].forEach(function (gy) {
					var g = document.createElementNS(NS, 'line');
					g.setAttribute('class', 'ak-stats__grid');
					g.setAttribute('x1', '0');
					g.setAttribute('x2', String(W));
					g.setAttribute('y1', gy.toFixed(2));
					g.setAttribute('y2', gy.toFixed(2));
					svg.appendChild(g);
				});
				var a = document.createElementNS(NS, 'path');
				a.setAttribute('class', 'ak-stats__area');
				a.setAttribute('d', area.replace(/\s+$/, ''));
				var l = document.createElementNS(NS, 'path');
				l.setAttribute('class', 'ak-stats__line');
				l.setAttribute('d', line.replace(/\s+$/, ''));
				svg.appendChild(a);
				svg.appendChild(l);

				// Y axis (left): max / mid / 0, aligned to the three gridlines.
				var yax = el('div', { class: 'ak-stats__chart-y', 'aria-hidden': 'true' }, [
					el('span', { text: num(max) }),
					el('span', { text: num(Math.round(max / 2)) }),
					el('span', { text: '0' })
				]);

				// X axis (bottom): first / middle / last day. 'Y-m-d' → 'DD/MM' by string
				// split (no Date object — cheap, no timezone surprises).
				function dm(d) {
					var p = (d || '').split('-');
					return p.length === 3 ? p[2] + '/' + p[1] : d || '';
				}
				var xLabels = [el('span', { text: dm(series[0].date) })];
				if (n > 2) {
					xLabels.push(el('span', { text: dm(series[Math.floor((n - 1) / 2)].date) }));
				}
				if (n > 1) {
					xLabels.push(el('span', { text: dm(series[n - 1].date) }));
				}
				var xax = el('div', { class: 'ak-stats__chart-x', 'aria-hidden': 'true' }, xLabels);

				wrap.appendChild(
					el('div', { class: 'ak-stats__chart-axes' }, [yax, el('div', { class: 'ak-stats__chart-plot' }, [svg, xax])])
				);
				return wrap;
			}

			function listShell(heading, colName, colVal) {
				return el('div', { class: 'ak-stats__list' }, [
					el('div', { class: 'ak-stats__list-head' }, [
						el('span', { class: 'ak-stats__list-h', text: heading }),
						el('span', { class: 'ak-stats__list-hv', text: colVal })
					])
				]);
			}

			// A searchable + paginated list — the full Statistics page can hold dozens of
			// top pages / sources, so a search box + a 10-per-row pager keeps it scannable
			// (the dashboard card stays a 5-row glance). All client-side over the rows we
			// already fetched — no extra requests. `rowText(r)` returns the searchable
			// string; `rowEl(r)` builds the <li>. Both pure.
			function searchableList(heading, colName, colVal, rows, rowText, rowEl) {
				var box = listShell(heading, colName, colVal);
				if (!rows.length) {
					box.appendChild(el('p', { class: 'ak-stats__none', text: I.statsNone || 'No data' }));
					return box;
				}
				var PAGE = 10;
				var view = rows.slice(); // current (filtered) view
				var page = 0;
				var ul = el('ul', { class: 'ak-stats__rows' });

				// Search — only worth showing once there's more than a page of rows.
				if (rows.length > PAGE) {
					var search = el('input', {
						type: 'search',
						class: 'ak-stats__search',
						placeholder: I.statsSearch || 'Search…',
						'aria-label': (I.statsSearch || 'Search') + ' — ' + heading
					});
					search.addEventListener('input', function () {
						var q = search.value.trim().toLowerCase();
						view = q
							? rows.filter(function (r) {
									return rowText(r).toLowerCase().indexOf(q) !== -1;
								})
							: rows.slice();
						page = 0;
						paint();
					});
					box.appendChild(el('div', { class: 'ak-stats__list-tools' }, [search]));
				}

				box.appendChild(ul);

				var prev = el('button', {
					type: 'button',
					class: 'ak-stats__pager-btn',
					'aria-label': I.statsPagerPrev || 'Previous',
					text: '‹',
					onclick: function () {
						if (page > 0) {
							page--;
							paint();
						}
					}
				});
				var info = el('span', { class: 'ak-stats__pager-info' });
				var next = el('button', {
					type: 'button',
					class: 'ak-stats__pager-btn',
					'aria-label': I.statsPagerNext || 'Next',
					text: '›',
					onclick: function () {
						if ((page + 1) * PAGE < view.length) {
							page++;
							paint();
						}
					}
				});
				var pager = el('div', { class: 'ak-stats__pager' }, [prev, info, next]);
				box.appendChild(pager);

				function paint() {
					ul.innerHTML = '';
					if (!view.length) {
						ul.appendChild(
							el('li', { class: 'ak-stats__row ak-stats__row--empty' }, [
								el('span', { class: 'ak-stats__row-name', text: I.statsNoMatch || 'No match' })
							])
						);
					} else {
						view.slice(page * PAGE, page * PAGE + PAGE).forEach(function (r) {
							ul.appendChild(rowEl(r));
						});
					}
					var total = view.length;
					var from = total ? page * PAGE + 1 : 0;
					var to = Math.min(total, page * PAGE + PAGE);
					info.textContent = total ? from + '–' + to + ' / ' + total : '';
					prev.disabled = page === 0;
					next.disabled = (page + 1) * PAGE >= total;
					pager.hidden = total <= PAGE; // single page → no pager chrome
				}
				paint();
				return box;
			}

			function pageList(rows) {
				return searchableList(
					I.statsTopPages || 'Top pages',
					I.statsColPage,
					I.statsColViews || 'Views',
					rows,
					function (r) {
						return (r.title || '') + ' ' + (r.path || '');
					},
					function (r) {
						var link = el(
							'a',
							{
								class: 'ak-stats__row-name',
								href: r.url || '#',
								target: '_blank',
								rel: 'noopener',
								title: r.path || ''
							},
							[
								el('span', { class: 'ak-stats__row-title', text: r.title || r.path || '' }),
								el('span', { class: 'ak-stats__row-sub', text: r.path || '' })
							]
						);
						return el('li', { class: 'ak-stats__row' }, [
							link,
							el('span', { class: 'ak-stats__row-val', text: num(r.pageviews) })
						]);
					}
				);
			}

			function sourceList(rows) {
				return searchableList(
					I.statsTopSources || 'Top sources',
					I.statsColSource,
					I.statsColVisits || 'Visits',
					rows,
					function (r) {
						return r.name === 'direct' ? I.statsDirect || 'Direct' : r.name || '';
					},
					function (r) {
						var label = r.name === 'direct' ? I.statsDirect || 'Direct' : r.name || '';
						return el('li', { class: 'ak-stats__row' }, [
							el('span', { class: 'ak-stats__row-name', title: label, text: label }),
							el('span', { class: 'ak-stats__row-val', text: num(r.visits) })
						]);
					}
				);
			}

			load();
			return p;
		}
		return { tabs: [{ id: 'stats', label: I.statsTab, build: buildStats }] };
	};
})();
