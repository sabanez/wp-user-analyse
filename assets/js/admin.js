/**
 * WP User Analyse: gráficos del resumen y visor del mapa de calor.
 * Sin dependencias externas.
 */
(function () {
	'use strict';

	var SVGNS = 'http://www.w3.org/2000/svg';

	function el(name, attrs, parent) {
		var node = document.createElementNS(SVGNS, name);
		Object.keys(attrs || {}).forEach(function (k) {
			node.setAttribute(k, attrs[k]);
		});
		if (parent) {
			parent.appendChild(node);
		}
		return node;
	}

	function readJson(id) {
		var node = document.getElementById(id);
		if (!node) {
			return null;
		}
		try {
			return JSON.parse(node.textContent);
		} catch (e) {
			return null;
		}
	}

	// Máximo del eje divisible en 4 tramos enteros "redondos" (1, 2, 5 × 10^n).
	function niceMax(v) {
		var raw = Math.max(1, Math.ceil(v / 4));
		var p = Math.pow(10, Math.floor(Math.log10(raw)));
		var n = raw / p;
		var step = (n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10) * p;
		return step * 4;
	}

	function shortDate(iso) {
		return iso.slice(8, 10) + '/' + iso.slice(5, 7);
	}

	/**
	 * Barras (apiladas o agrupadas) con eje Y, etiquetas de fecha y leyenda.
	 */
	function barChart(container, days, series, stacked) {
		var W = Math.max(container.clientWidth, 320);
		var H = 240;
		var m = { t: 12, r: 12, b: 28, l: 44 };
		var iw = W - m.l - m.r;
		var ih = H - m.t - m.b;
		var n = days.length;

		var max = 0;
		days.forEach(function (d) {
			if (stacked) {
				var sum = series.reduce(function (a, s) { return a + (d[s.key] || 0); }, 0);
				max = Math.max(max, sum);
			} else {
				series.forEach(function (s) { max = Math.max(max, d[s.key] || 0); });
			}
		});
		max = niceMax(max);

		var svg = el('svg', { viewBox: '0 0 ' + W + ' ' + H, width: '100%', height: H, role: 'img' });
		var g = el('g', { transform: 'translate(' + m.l + ',' + m.t + ')' }, svg);

		for (var i = 0; i <= 4; i++) {
			var y = ih - (ih * i) / 4;
			el('line', { x1: 0, x2: iw, y1: y, y2: y, class: 'wua-grid' }, g);
			var label = el('text', { x: -8, y: y + 4, 'text-anchor': 'end', class: 'wua-axis' }, g);
			label.textContent = Math.round((max * i) / 4).toLocaleString();
		}

		var band = iw / n;
		var bw = Math.max(1, band * (stacked ? 0.7 : 0.8) / (stacked ? 1 : series.length));
		var labelEvery = Math.ceil(n / Math.floor(iw / 48));

		days.forEach(function (d, idx) {
			var x0 = idx * band + band * (stacked ? 0.15 : 0.1);
			var base = ih;
			var tip = [d.day];
			series.forEach(function (s, si) {
				var v = d[s.key] || 0;
				tip.push(s.name + ': ' + v.toLocaleString());
				var h = (v / max) * ih;
				var x = stacked ? x0 : x0 + si * bw;
				var y = stacked ? base - h : ih - h;
				if (v > 0) {
					var r = el('rect', { x: x, y: y, width: bw, height: Math.max(h, 1), fill: s.color, rx: Math.min(2, bw / 2) }, g);
					el('title', {}, r).textContent = d.day + ' · ' + s.name + ': ' + v.toLocaleString();
				}
				if (stacked) {
					base -= h;
				}
			});
			if (idx % labelEvery === 0) {
				var t = el('text', { x: idx * band + band / 2, y: ih + 18, 'text-anchor': 'middle', class: 'wua-axis' }, g);
				t.textContent = shortDate(d.day);
			}
		});

		container.innerHTML = '';
		container.appendChild(svg);

		var legend = document.createElement('div');
		legend.className = 'wua-legend';
		series.forEach(function (s) {
			var item = document.createElement('span');
			var dot = document.createElement('i');
			dot.style.background = s.color;
			item.appendChild(dot);
			item.appendChild(document.createTextNode(s.name));
			legend.appendChild(item);
		});
		container.appendChild(legend);
	}

	function initCharts() {
		var days = readJson('wua-daily');
		if (!days) {
			return;
		}
		var defs = {
			sessions: { stacked: true, series: [
				{ key: 'anon', name: 'Anónimos', color: '#9ec5fe' },
				{ key: 'registered', name: 'Registrados', color: '#2271b1' }
			] },
			users: { stacked: false, series: [
				{ key: 'signups', name: 'Altas', color: '#00a32a' },
				{ key: 'logins', name: 'Accesos', color: '#dba617' }
			] },
			minutes: { stacked: false, series: [
				{ key: 'minutes', name: 'Minutos activos', color: '#8c5fc4' }
			] }
		};
		var charts = document.querySelectorAll('.wua-chart[data-chart]');
		function draw() {
			Array.prototype.forEach.call(charts, function (c) {
				var def = defs[c.getAttribute('data-chart')];
				if (def) {
					barChart(c, days, def.series, def.stacked);
				}
			});
		}
		draw();
		var timer;
		window.addEventListener('resize', function () {
			window.clearTimeout(timer);
			timer = window.setTimeout(draw, 200);
		});
	}

	/* ---------- Mapa de calor ---------- */

	function palette() {
		var c = document.createElement('canvas');
		c.width = 256;
		c.height = 1;
		var ctx = c.getContext('2d');
		var grad = ctx.createLinearGradient(0, 0, 256, 0);
		grad.addColorStop(0.0, 'rgba(0,0,255,0)');
		grad.addColorStop(0.25, 'rgb(0,128,255)');
		grad.addColorStop(0.5, 'rgb(0,220,120)');
		grad.addColorStop(0.75, 'rgb(255,220,0)');
		grad.addColorStop(1.0, 'rgb(255,0,0)');
		ctx.fillStyle = grad;
		ctx.fillRect(0, 0, 256, 1);
		return ctx.getImageData(0, 0, 256, 1).data;
	}

	function locate(doc, p, frameWidth) {
		if (p.s) {
			var target = null;
			try {
				target = doc.querySelector(p.s);
			} catch (e) {
				target = null;
			}
			if (target) {
				var rect = target.getBoundingClientRect();
				if (rect.width || rect.height) {
					var win = doc.defaultView;
					return {
						x: rect.left + win.scrollX + (p.rx / 10000) * rect.width,
						y: rect.top + win.scrollY + (p.ry / 10000) * rect.height
					};
				}
			}
		}
		var scale = p.vw ? frameWidth / p.vw : 1;
		return { x: p.x * scale, y: p.y };
	}

	function drawHeatmap(frame, points) {
		var doc = frame.contentDocument;
		if (!doc || !doc.body) {
			return;
		}
		var old = doc.getElementById('wua-heat-layer');
		if (old) {
			old.remove();
		}
		var width = Math.max(doc.documentElement.scrollWidth, frame.clientWidth);
		var height = Math.min(Math.max(doc.documentElement.scrollHeight, doc.body.scrollHeight), 16000);

		var canvas = doc.createElement('canvas');
		canvas.id = 'wua-heat-layer';
		canvas.width = width;
		canvas.height = height;
		canvas.style.cssText = 'position:absolute;left:0;top:0;width:' + width + 'px;height:' + height + 'px;pointer-events:none;z-index:2147483647;opacity:.75';
		doc.body.appendChild(canvas);
		if (getComputedStyle(doc.body).position === 'static') {
			doc.body.style.position = 'relative';
		}

		var ctx = canvas.getContext('2d');
		var radius = 28;
		var alpha = Math.max(0.04, Math.min(0.35, 12 / Math.sqrt(points.length + 1)));
		points.forEach(function (p) {
			var pos = locate(doc, p, frame.clientWidth);
			if (pos.y > height) {
				return;
			}
			var g = ctx.createRadialGradient(pos.x, pos.y, 0, pos.x, pos.y, radius);
			g.addColorStop(0, 'rgba(0,0,0,' + alpha + ')');
			g.addColorStop(1, 'rgba(0,0,0,0)');
			ctx.fillStyle = g;
			ctx.fillRect(pos.x - radius, pos.y - radius, radius * 2, radius * 2);
		});

		// Colorea según la densidad acumulada en el canal alfa.
		var img = ctx.getImageData(0, 0, width, height);
		var data = img.data;
		var pal = palette();
		for (var i = 3; i < data.length; i += 4) {
			var a = data[i];
			if (a) {
				var o = a * 4;
				data[i - 3] = pal[o];
				data[i - 2] = pal[o + 1];
				data[i - 1] = pal[o + 2];
				data[i] = Math.min(255, a * 2);
			}
		}
		ctx.putImageData(img, 0, 0);
	}

	function initHeatmap() {
		var frame = document.getElementById('wua-heatmap-frame');
		var points = readJson('wua-heatmap-points');
		if (!frame || !points) {
			return;
		}
		frame.addEventListener('load', function () {
			// Espera a que carguen imágenes y fuentes que cambian la maquetación.
			window.setTimeout(function () {
				try {
					drawHeatmap(frame, points);
				} catch (e) {
					var msg = document.createElement('p');
					msg.className = 'notice notice-error';
					msg.textContent = 'No se pudo dibujar el mapa de calor: ' + e.message;
					frame.parentNode.insertBefore(msg, frame);
				}
			}, 800);
		});
	}

	function init() {
		initCharts();
		initHeatmap();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
