/**
 * Knowledge-graph renderer — a small dependency-free force-directed graph on <canvas>.
 * Physics: repulsion (O(n^2), fine for a few hundred posts) + spring attraction on edges +
 * mild gravity, with cooling so it settles. Pan, zoom, drag, hover tooltip, click-to-edit.
 */
(function () {
	'use strict';

	var app = document.getElementById('aiil-graph-app');
	if (!app || typeof AIIL === 'undefined') {
		return;
	}

	var canvas = document.getElementById('aiil-graph-canvas');
	var ctx = canvas.getContext('2d');
	var tooltip = document.getElementById('aiil-graph-tooltip');
	var statusEl = document.getElementById('aiil-graph-status');

	var PALETTE = [
		'#4e79a7', '#f28e2b', '#59a14f', '#e15759', '#b07aa1', '#76b7b2',
		'#edc948', '#ff9da7', '#9c755f', '#bab0ac', '#1f77b4', '#2ca02c',
		'#d62728', '#9467bd', '#8c564b', '#17becf', '#7f7f7f', '#e377c2'
	];

	var nodes = [];
	var edges = [];
	var nodeById = {};
	var view = { x: 0, y: 0, scale: 1 };
	var drag = null;         // node being dragged
	var pan = null;          // canvas panning
	var hover = null;
	var alpha = 0;           // simulation temperature
	var raf = null;
	var W = 0, H = 0, DPR = Math.max(1, window.devicePixelRatio || 1);

	function color(node) {
		return PALETTE[node.community % PALETTE.length];
	}
	function radius(node) {
		return 4 + Math.sqrt(node.incoming || 0) * 3 + Math.min(6, (node.degree || 0) * 0.4);
	}

	function resize() {
		var stage = canvas.parentElement;
		W = stage.clientWidth;
		H = stage.clientHeight;
		canvas.width = W * DPR;
		canvas.height = H * DPR;
		canvas.style.width = W + 'px';
		canvas.style.height = H + 'px';
		ctx.setTransform(DPR, 0, 0, DPR, 0, 0);
	}

	function load() {
		var edgeType = document.getElementById('aiil-graph-edge-type').value;
		var minSim = document.getElementById('aiil-graph-min').value;
		var orphans = document.getElementById('aiil-graph-orphans').checked ? 1 : 0;
		statusEl.textContent = 'Loading…';

		var url = AIIL.ajaxUrl + '?action=aiil_graph_data'
			+ '&nonce=' + encodeURIComponent(app.getAttribute('data-nonce'))
			+ '&edge_type=' + encodeURIComponent(edgeType)
			+ '&min_sim=' + encodeURIComponent(minSim)
			+ '&orphans=' + orphans;

		fetch(url, { credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				if (!res || !res.success) {
					statusEl.textContent = (res && res.data && res.data.message) ? res.data.message : 'Failed to load graph.';
					return;
				}
				build(res.data);
			})
			.catch(function () { statusEl.textContent = 'Request failed — check the console.'; });
	}

	function build(data) {
		nodes = data.nodes || [];
		edges = data.edges || [];
		nodeById = {};
		var cx = W / 2, cy = H / 2;
		nodes.forEach(function (n, i) {
			// Seed on a circle so the layout unfolds instead of exploding from a single point.
			var a = (i / Math.max(1, nodes.length)) * Math.PI * 2;
			n.x = cx + Math.cos(a) * Math.min(W, H) * 0.35 + (Math.random() - 0.5) * 20;
			n.y = cy + Math.sin(a) * Math.min(W, H) * 0.35 + (Math.random() - 0.5) * 20;
			n.vx = 0; n.vy = 0;
			nodeById[n.id] = n;
		});
		edges.forEach(function (e) {
			e.s = nodeById[e.source];
			e.t = nodeById[e.target];
		});
		edges = edges.filter(function (e) { return e.s && e.t; });

		statusEl.textContent = (data.meta.nodes) + ' posts, ' + (data.meta.edges) + ' connections'
			+ (data.meta.nodes === 0 ? ' — index some posts first.' : '');

		alpha = 1;
		fit();
		start();
	}

	function start() {
		if (raf) { cancelAnimationFrame(raf); }
		tick();
	}

	function step() {
		if (alpha < 0.005 && !drag) { return; }
		var repuls = 1600, spring = 0.02, springLen = 60, grav = 0.02, damp = 0.9;
		var cx = W / 2, cy = H / 2;
		var n = nodes.length, i, j, a, b, dx, dy, d2, d, f;

		for (i = 0; i < n; i++) {
			a = nodes[i];
			for (j = i + 1; j < n; j++) {
				b = nodes[j];
				dx = a.x - b.x; dy = a.y - b.y;
				d2 = dx * dx + dy * dy || 0.01;
				if (d2 > 90000) { continue; } // ignore far pairs for speed
				f = repuls / d2;
				d = Math.sqrt(d2);
				var ux = dx / d, uy = dy / d;
				a.vx += ux * f; a.vy += uy * f;
				b.vx -= ux * f; b.vy -= uy * f;
			}
			a.vx += (cx - a.x) * grav;
			a.vy += (cy - a.y) * grav;
		}

		edges.forEach(function (e) {
			dx = e.t.x - e.s.x; dy = e.t.y - e.s.y;
			d = Math.sqrt(dx * dx + dy * dy) || 0.01;
			// Stronger springs for stronger similarity so tight topics cluster.
			var k = spring * (0.5 + (e.weight || 60) / 100);
			f = (d - springLen) * k;
			var ux = dx / d, uy = dy / d;
			e.s.vx += ux * f; e.s.vy += uy * f;
			e.t.vx -= ux * f; e.t.vy -= uy * f;
		});

		for (i = 0; i < n; i++) {
			a = nodes[i];
			if (drag === a) { continue; }
			a.vx *= damp; a.vy *= damp;
			a.x += a.vx * alpha; a.y += a.vy * alpha;
		}
		alpha *= 0.985;
	}

	function draw() {
		ctx.clearRect(0, 0, W, H);
		ctx.save();
		ctx.translate(view.x, view.y);
		ctx.scale(view.scale, view.scale);

		edges.forEach(function (e) {
			ctx.beginPath();
			ctx.moveTo(e.s.x, e.s.y);
			ctx.lineTo(e.t.x, e.t.y);
			if (e.inserted) {
				ctx.strokeStyle = 'rgba(90,90,90,0.9)';
				ctx.lineWidth = 1.6 / view.scale;
			} else {
				var op = 0.06 + ((e.weight || 60) - 50) / 200;
				ctx.strokeStyle = 'rgba(120,140,170,' + Math.max(0.05, Math.min(0.5, op)) + ')';
				ctx.lineWidth = 0.7 / view.scale;
			}
			ctx.stroke();
		});

		nodes.forEach(function (nd) {
			var r = radius(nd);
			ctx.beginPath();
			ctx.arc(nd.x, nd.y, r, 0, Math.PI * 2);
			ctx.fillStyle = color(nd);
			ctx.globalAlpha = nd.degree === 0 ? 0.4 : 1;
			ctx.fill();
			if (hover === nd) {
				ctx.globalAlpha = 1;
				ctx.lineWidth = 2 / view.scale;
				ctx.strokeStyle = '#1d2327';
				ctx.stroke();
			}
			ctx.globalAlpha = 1;
		});

		// Labels appear when zoomed in, or for the hovered node.
		nodes.forEach(function (nd) {
			if (view.scale < 1.4 && hover !== nd) { return; }
			ctx.fillStyle = '#1d2327';
			ctx.font = (12 / view.scale) + 'px sans-serif';
			ctx.fillText(trim(nd.title, 42), nd.x + radius(nd) + 2 / view.scale, nd.y + 3 / view.scale);
		});

		ctx.restore();
	}

	function tick() {
		step();
		draw();
		raf = requestAnimationFrame(tick);
	}

	function trim(s, n) { s = s || ''; return s.length > n ? s.slice(0, n - 1) + '…' : s; }

	// --- Coordinate helpers --------------------------------------------------------
	function toWorld(px, py) {
		return { x: (px - view.x) / view.scale, y: (py - view.y) / view.scale };
	}
	function nodeAt(px, py) {
		var w = toWorld(px, py), i, nd, r;
		for (i = nodes.length - 1; i >= 0; i--) {
			nd = nodes[i];
			r = radius(nd) + 3;
			if ((w.x - nd.x) * (w.x - nd.x) + (w.y - nd.y) * (w.y - nd.y) <= r * r) { return nd; }
		}
		return null;
	}

	function fit() {
		if (!nodes.length) { view = { x: 0, y: 0, scale: 1 }; return; }
		var minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
		nodes.forEach(function (n) {
			minX = Math.min(minX, n.x); maxX = Math.max(maxX, n.x);
			minY = Math.min(minY, n.y); maxY = Math.max(maxY, n.y);
		});
		var pad = 60;
		var gw = (maxX - minX) || 1, gh = (maxY - minY) || 1;
		var scale = Math.min((W - pad) / gw, (H - pad) / gh);
		scale = Math.max(0.1, Math.min(2, scale));
		view.scale = scale;
		view.x = W / 2 - ((minX + maxX) / 2) * scale;
		view.y = H / 2 - ((minY + maxY) / 2) * scale;
	}

	// --- Interaction ---------------------------------------------------------------
	function pos(e) {
		var rect = canvas.getBoundingClientRect();
		return { x: e.clientX - rect.left, y: e.clientY - rect.top };
	}

	canvas.addEventListener('mousedown', function (e) {
		var p = pos(e);
		var nd = nodeAt(p.x, p.y);
		if (nd) { drag = nd; nd.moved = false; }
		else { pan = { x: p.x, y: p.y, vx: view.x, vy: view.y }; }
	});

	window.addEventListener('mousemove', function (e) {
		var p = pos(e);
		if (drag) {
			var w = toWorld(p.x, p.y);
			drag.x = w.x; drag.y = w.y; drag.vx = 0; drag.vy = 0; drag.moved = true;
			alpha = Math.max(alpha, 0.3);
			return;
		}
		if (pan) {
			view.x = pan.vx + (p.x - pan.x);
			view.y = pan.vy + (p.y - pan.y);
			return;
		}
		var nd = nodeAt(p.x, p.y);
		hover = nd;
		if (nd) {
			canvas.style.cursor = 'pointer';
			tooltip.hidden = false;
			tooltip.style.left = (p.x + 14) + 'px';
			tooltip.style.top = (p.y + 12) + 'px';
			tooltip.innerHTML = '<strong>' + escapeHtml(nd.title) + '</strong><br>'
				+ 'in ' + nd.incoming + ' · out ' + nd.outgoing + ' · connections ' + nd.degree
				+ ' · cluster ' + (nd.community + 1);
		} else {
			canvas.style.cursor = 'default';
			tooltip.hidden = true;
		}
	});

	window.addEventListener('mouseup', function (e) {
		if (drag && !drag.moved) {
			// A click (no drag) on a node opens its editor.
			if (drag.edit) { window.open(drag.edit, '_blank'); }
		}
		drag = null; pan = null;
	});

	canvas.addEventListener('wheel', function (e) {
		e.preventDefault();
		var p = pos(e);
		var before = toWorld(p.x, p.y);
		var factor = e.deltaY < 0 ? 1.1 : 0.9;
		view.scale = Math.max(0.1, Math.min(6, view.scale * factor));
		var after = toWorld(p.x, p.y);
		view.x += (after.x - before.x) * view.scale;
		view.y += (after.y - before.y) * view.scale;
	}, { passive: false });

	function escapeHtml(s) {
		return String(s || '').replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	// --- Controls ------------------------------------------------------------------
	var minInput = document.getElementById('aiil-graph-min');
	var minVal = document.getElementById('aiil-graph-min-val');
	minInput.addEventListener('input', function () { minVal.textContent = minInput.value; });
	document.getElementById('aiil-graph-reload').addEventListener('click', load);
	document.getElementById('aiil-graph-edge-type').addEventListener('change', load);
	document.getElementById('aiil-graph-orphans').addEventListener('change', load);
	document.getElementById('aiil-graph-fit').addEventListener('click', function () { fit(); });
	window.addEventListener('resize', function () { resize(); });

	resize();
	load();
})();
