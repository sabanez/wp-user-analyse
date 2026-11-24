/**
 * WP User Analyse: script de seguimiento.
 *
 * No escribe cookies ni envía nada hasta que hay consentimiento de estadística.
 * API pública:
 *   wua.grantConsent()   concede el consentimiento (modo "custom").
 *   wua.revokeConsent()  lo retira y borra las cookies del plugin.
 *   wua.track(label)     registra un evento personalizado.
 */
(function () {
	'use strict';

	var cfg = window.wuaConfig;
	if (!cfg || !window.JSON) {
		return;
	}
	if (/bot|crawl|spider|slurp|headless|lighthouse|pagespeed/i.test(navigator.userAgent || '')) {
		return;
	}
	if (cfg.respectDnt && (navigator.doNotTrack === '1' || window.doNotTrack === '1')) {
		return;
	}

	var TICK = 5;          // segundos entre comprobaciones de actividad
	var PING_EVERY = 15;   // segundos entre envíos de tiempo activo
	var IDLE_LIMIT = 120;  // segundos sin interacción a partir de los que no cuenta el tiempo
	var FLUSH_EVERY = 10;  // segundos entre envíos de la cola
	var MAX_BATCH = 50;
	var VISITOR_MAX_AGE = 60 * 60 * 24 * 396;

	var started = false;
	var vid = '';
	var sid = '';
	var pvid = '';
	var queue = [];
	var timers = [];
	var lastInteraction = Date.now();
	var activeAccum = 0;
	var sinceLastPing = 0;
	var maxScroll = 0;
	var sentScroll = 0;
	var sampled = false;

	/* ---------- utilidades ---------- */

	function uuid() {
		if (window.crypto && typeof window.crypto.randomUUID === 'function') {
			return window.crypto.randomUUID();
		}
		var b = new Uint8Array(16);
		(window.crypto || window.msCrypto).getRandomValues(b);
		b[6] = (b[6] & 0x0f) | 0x40;
		b[8] = (b[8] & 0x3f) | 0x80;
		var h = Array.prototype.map.call(b, function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
		return h.slice(0, 8) + '-' + h.slice(8, 12) + '-' + h.slice(12, 16) + '-' + h.slice(16, 20) + '-' + h.slice(20);
	}

	function getCookie(name) {
		var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
		return m ? decodeURIComponent(m[1]) : '';
	}

	function setCookie(name, value, maxAge) {
		var c = name + '=' + encodeURIComponent(value) + '; path=' + (cfg.cookiePath || '/') + '; SameSite=Lax';
		if (maxAge !== null) {
			c += '; max-age=' + maxAge;
		}
		if (cfg.cookieDomain) {
			c += '; domain=' + cfg.cookieDomain;
		}
		if (cfg.secure) {
			c += '; secure';
		}
		document.cookie = c;
	}

	function isUuid(v) {
		return /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(v);
	}

	function now() {
		return Date.now();
	}

	/* ---------- consentimiento ---------- */

	function hasConsent() {
		if (cfg.consentMode === 'none') {
			return true;
		}
		if (cfg.consentMode === 'consent_api') {
			return typeof window.wp_has_consent === 'function' && window.wp_has_consent('statistics');
		}
		return window.wuaConsentGranted === true;
	}

	document.addEventListener('wp_listen_for_consent_change', function (e) {
		var detail = e && e.detail;
		if (!detail || !Object.prototype.hasOwnProperty.call(detail, 'statistics')) {
			return;
		}
		if (detail.statistics === 'allow') {
			start();
		} else {
			stop();
		}
	});

	/* ---------- identidad y sesión ---------- */

	function ensureIds() {
		vid = getCookie('wua_vid');
		if (!isUuid(vid)) {
			vid = uuid();
		}
		setCookie('wua_vid', vid, VISITOR_MAX_AGE);
		touchSession();
		var n = parseInt(sid.replace(/-/g, '').slice(0, 8), 16) % 100;
		sampled = n < (parseInt(cfg.heatmapSample, 10) || 0);
	}

	// La sesión vive en una cookie que caduca tras X minutos sin actividad.
	function touchSession() {
		var current = getCookie('wua_sid');
		sid = isUuid(current) ? current : uuid();
		setCookie('wua_sid', sid, (parseInt(cfg.sessionTimeout, 10) || 30) * 60);
	}

	/* ---------- cola y envío ---------- */

	function push(evt) {
		if (!started) {
			return;
		}
		evt.ts = Math.round(now() / 1000);
		queue.push(evt);
		if (queue.length >= MAX_BATCH) {
			flush(false);
		}
	}

	function flush(useBeacon) {
		if (!queue.length) {
			return;
		}
		var body = JSON.stringify({ v: vid, s: sid, e: queue.splice(0, MAX_BATCH) });
		// text/plain evita la petición previa CORS y permite sendBeacon.
		if (useBeacon && navigator.sendBeacon) {
			navigator.sendBeacon(cfg.endpoint, new Blob([body], { type: 'text/plain' }));
		} else if (window.fetch) {
			window.fetch(cfg.endpoint, {
				method: 'POST',
				body: body,
				credentials: 'same-origin',
				keepalive: true,
				headers: { 'Content-Type': 'text/plain' }
			}).catch(function () {});
		}
		if (queue.length) {
			flush(useBeacon);
		}
	}

	/* ---------- página vista ---------- */

	function externalReferrer() {
		var r = document.referrer;
		if (!r) {
			return '';
		}
		try {
			return new URL(r).host === location.host ? '' : r;
		} catch (e) {
			return '';
		}
	}

	function utm() {
		var p;
		try {
			p = new URLSearchParams(location.search);
		} catch (e) {
			return {};
		}
		return {
			s: p.get('utm_source') || '',
			m: p.get('utm_medium') || '',
			c: p.get('utm_campaign') || ''
		};
	}

	function pageview() {
		pvid = uuid();
		maxScroll = 0;
		sentScroll = 0;
		activeAccum = 0;
		sinceLastPing = 0;
		updateScroll();
		push({
			t: 'pv',
			pv: pvid,
			u: location.pathname,
			ti: (document.title || '').slice(0, 250),
			r: externalReferrer(),
			utm: utm(),
			vw: window.innerWidth,
			p: parseInt(cfg.postId, 10) || 0
		});
	}

	/* ---------- tiempo activo y scroll ---------- */

	function markInteraction() {
		lastInteraction = now();
	}

	function updateScroll() {
		var doc = document.documentElement;
		var height = Math.max(doc.scrollHeight, document.body ? document.body.scrollHeight : 0);
		if (!height) {
			return;
		}
		var seen = Math.min(100, Math.round(((window.scrollY || doc.scrollTop) + window.innerHeight) / height * 100));
		if (seen > maxScroll) {
			maxScroll = seen;
		}
	}

	function ping() {
		if (activeAccum > 0 || (cfg.scroll && maxScroll > sentScroll)) {
			push({ t: 'ping', pv: pvid, a: activeAccum, sc: cfg.scroll ? maxScroll : 0 });
			activeAccum = 0;
			sentScroll = maxScroll;
		}
	}

	function tick() {
		if (document.visibilityState === 'visible' && now() - lastInteraction < IDLE_LIMIT * 1000) {
			activeAccum += TICK;
			touchSession();
		}
		sinceLastPing += TICK;
		if (sinceLastPing >= PING_EVERY) {
			sinceLastPing = 0;
			ping();
		}
	}

	/* ---------- clics y mapa de calor ---------- */

	function cssPath(el) {
		var parts = [];
		var depth = 0;
		while (el && el.nodeType === 1 && el !== document.body && el !== document.documentElement && depth < 8) {
			if (el.id && /^[A-Za-z][\w-]*$/.test(el.id)) {
				parts.unshift('#' + el.id);
				break;
			}
			var tag = el.tagName.toLowerCase();
			var index = 1;
			var sib = el;
			while ((sib = sib.previousElementSibling)) {
				if (sib.tagName === el.tagName) {
					index++;
				}
			}
			parts.unshift(tag + ':nth-of-type(' + index + ')');
			el = el.parentElement;
			depth++;
		}
		return parts.join('>').slice(0, 250);
	}

	function label(el) {
		var text = el.getAttribute('data-wua-track') || el.getAttribute('aria-label') || '';
		if (!text) {
			var tag = el.tagName;
			if (tag === 'INPUT') {
				var type = (el.getAttribute('type') || '').toLowerCase();
				text = (type === 'submit' || type === 'button') ? el.value : '';
			} else {
				text = el.innerText || el.textContent || '';
			}
		}
		if (!text) {
			var img = el.querySelector && el.querySelector('img[alt]');
			text = el.getAttribute('title') || (img ? img.getAttribute('alt') : '');
		}
		return String(text).replace(/\s+/g, ' ').trim().slice(0, 100);
	}

	function closestMatch(el) {
		if (!el || !el.closest) {
			return null;
		}
		try {
			return el.closest(cfg.selectors);
		} catch (e) {
			return el.closest('a, button');
		}
	}

	function onClick(e) {
		markInteraction();
		var target = e.target && e.target.nodeType === 1 ? e.target : (e.target && e.target.parentElement);
		if (!target) {
			return;
		}
		if (cfg.clicks) {
			var el = closestMatch(target);
			if (el) {
				push({ t: 'click', pv: pvid, l: label(el), s: cssPath(el), h: el.href ? String(el.href).slice(0, 250) : '' });
			}
		}
		// Clics de teclado (detail 0) no tienen coordenadas reales.
		if (cfg.heatmap && sampled && e.detail > 0) {
			var rect = target.getBoundingClientRect();
			var rx = rect.width ? Math.round((e.clientX - rect.left) / rect.width * 10000) : 0;
			var ry = rect.height ? Math.round((e.clientY - rect.top) / rect.height * 10000) : 0;
			push({
				t: 'hm',
				pv: pvid,
				s: cssPath(target),
				rx: Math.max(0, Math.min(10000, rx)),
				ry: Math.max(0, Math.min(10000, ry)),
				x: Math.round(e.pageX),
				y: Math.round(e.pageY),
				vw: window.innerWidth
			});
		}
	}

	/* ---------- ciclo de vida ---------- */

	function onHide() {
		if (document.visibilityState === 'hidden') {
			ping();
			flush(true);
		}
	}

	function onPageHide() {
		ping();
		flush(true);
	}

	function onPageShow(e) {
		if (e.persisted && started) {
			touchSession();
			pageview();
		}
	}

	function start() {
		if (started || !hasConsent()) {
			return;
		}
		started = true;
		ensureIds();
		pageview();

		['mousemove', 'keydown', 'scroll', 'touchstart', 'wheel'].forEach(function (type) {
			window.addEventListener(type, markInteraction, { passive: true });
		});
		window.addEventListener('scroll', updateScroll, { passive: true });
		document.addEventListener('click', onClick, true);
		document.addEventListener('visibilitychange', onHide);
		window.addEventListener('pagehide', onPageHide);
		window.addEventListener('pageshow', onPageShow);

		timers.push(window.setInterval(tick, TICK * 1000));
		timers.push(window.setInterval(function () { flush(false); }, FLUSH_EVERY * 1000));
		flush(false);
	}

	function stop() {
		if (!started) {
			return;
		}
		started = false;
		queue = [];
		timers.forEach(window.clearInterval);
		timers = [];
		document.removeEventListener('click', onClick, true);
		document.removeEventListener('visibilitychange', onHide);
		window.removeEventListener('pagehide', onPageHide);
		window.removeEventListener('pageshow', onPageShow);
		window.removeEventListener('scroll', updateScroll);
		setCookie('wua_vid', '', 0);
		setCookie('wua_sid', '', 0);
	}

	window.wua = window.wua || {};
	window.wua.grantConsent = function () {
		window.wuaConsentGranted = true;
		start();
	};
	window.wua.revokeConsent = function () {
		window.wuaConsentGranted = false;
		stop();
	};
	window.wua.track = function (text) {
		push({ t: 'custom', pv: pvid, l: String(text || '').slice(0, 100) });
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
	// La WP Consent API puede resolver el consentimiento tras la carga.
	window.setTimeout(start, 1500);
})();
