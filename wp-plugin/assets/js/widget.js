/**
 * ویجت چت سمت سایت — دستیار هوش مصنوعی وردپرس فارسی
 * متن‌ها از window.PAIA_CHAT.i18n (PHP) می‌آیند.
 */
(function () {
	'use strict';

	function start() {
	var cfg = window.PAIA_CHAT;
	var root = document.getElementById('paia-chat-root');
	if (!cfg) { return; }
	if (!root) {
		root = document.createElement('div');
		root.id = 'paia-chat-root';
		root.className = 'paia-chat';
		root.setAttribute('data-position', 'right');
		root.setAttribute('dir', document.documentElement.dir === 'ltr' ? 'ltr' : 'rtl');
		document.body.appendChild(root);
	}
	var t = cfg.i18n;

	/* ---------- تم دارک هوشمند ----------
	 * فقط وقتی در تنظیمات فعال است (cfg.dark). «تیره بودن» از ظاهر واقعی صفحه تشخیص داده می‌شود نه فقط از
	 * تنظیم دستگاه: رنگ پس‌زمینه پشت آیکن چت، سپس نشانه‌های قالب (class/data-theme)، سپس prefers-color-scheme.
	 * با تغییر تم سایت (کلید روشن/تاریکِ خود سایت) یا تغییر تنظیم دستگاه، لحظه‌ای دوباره بررسی می‌شود. */
	function initTheme() {
		if (!cfg.dark) { root.removeAttribute('data-theme'); return; }
		var mq = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

		function parseColor(str) {
			if (!str) { return null; }
			var m = /^rgba?\(([^)]+)\)$/.exec(str);
			if (m) {
				var p = m[1].split(/[\s,\/]+/).filter(Boolean).map(parseFloat);
				if (p.length < 3 || p.some(isNaN)) { return null; }
				return { r: p[0], g: p[1], b: p[2], a: p.length > 3 ? p[3] : 1 };
			}
			m = /^color\(srgb\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)(?:\s*\/\s*([\d.]+%?))?\)$/.exec(str);
			if (m) {
				var a = m[4] === undefined ? 1 : (/%$/.test(m[4]) ? parseFloat(m[4]) / 100 : parseFloat(m[4]));
				return { r: parseFloat(m[1]) * 255, g: parseFloat(m[2]) * 255, b: parseFloat(m[3]) * 255, a: a };
			}
			return null;
		}
		function luminance(c) { return (0.2126 * c.r + 0.7152 * c.g + 0.0722 * c.b) / 255; }

		function solidBg(node) {
			for (var e = node; e && e.nodeType === 1; e = e.parentElement) {
				var c = parseColor(window.getComputedStyle(e).backgroundColor);
				if (c && c.a >= 0.6) { return c; }
			}
			return null;
		}
		function pageBg() {
			var found = null;
			try {
				var r = root.getBoundingClientRect();
				var x = Math.max(0, Math.min(window.innerWidth - 1, r.left + r.width / 2));
				var y = Math.max(0, Math.min(window.innerHeight - 1, r.top + r.height / 2));
				var list = document.elementsFromPoint ? document.elementsFromPoint(x, y) : [];
				for (var i = 0; i < list.length && !found; i++) {
					if (root.contains(list[i])) { continue; }
					found = solidBg(list[i]);
				}
			} catch (e) { /* noop */ }
			return found || solidBg(document.body) || solidBg(document.documentElement);
		}
		function markerSaysDark() {
			var nodes = [document.documentElement, document.body];
			var re = /(^|[\s_-])(dark|night)([\s_-]|$)/i;
			var light = /(^|[\s_-])(light|day)([\s_-]|$)/i;
			for (var i = 0; i < nodes.length; i++) {
				var n = nodes[i];
				if (!n) { continue; }
				var vals = [n.className && n.className.baseVal === undefined ? String(n.className) : '',
					n.getAttribute('data-theme'), n.getAttribute('data-bs-theme'), n.getAttribute('data-color-mode'),
					n.getAttribute('data-mode'), n.getAttribute('data-color-scheme')];
				for (var j = 0; j < vals.length; j++) {
					if (!vals[j]) { continue; }
					if (light.test(vals[j]) && !re.test(vals[j])) { return false; }
					if (re.test(vals[j])) { return true; }
				}
			}
			return null;
		}
		function isDark() {
			var bg = pageBg();
			if (bg) { return luminance(bg) < 0.5; }
			var mark = markerSaysDark();
			if (mark !== null) { return mark; }
			return !!(mq && mq.matches);
		}
		function apply() {
			if (isDark()) { root.setAttribute('data-theme', 'dark'); }
			else { root.removeAttribute('data-theme'); }
		}

		var timer = 0;
		function later() { window.clearTimeout(timer); timer = window.setTimeout(apply, 150); }

		apply();
		window.addEventListener('load', later);
		if (mq) {
			if (mq.addEventListener) { mq.addEventListener('change', later); }
			else if (mq.addListener) { mq.addListener(later); }
		}
		if (window.MutationObserver) {
			var mo = new window.MutationObserver(later);
			var opts = { attributes: true, attributeFilter: ['class', 'style', 'data-theme', 'data-bs-theme', 'data-color-mode', 'data-mode', 'data-color-scheme'] };
			mo.observe(document.documentElement, opts);
			if (document.body) { mo.observe(document.body, opts); }
		}
		// کلیدهای تم سایت گاهی بدون تغییر attribute رنگ را عوض می‌کنند؛ هنگام باز/بسته‌شدن چت هم بررسی می‌کنیم.
		root.addEventListener('click', later);
	}
	initTheme();

	var K_UID = 'paia_chat_visitor_v1';
	var K_SEEN = 'paia_chat_seen_v1';
	var K_TEASER = 'paia_chat_teaser_closed';

	var state = {
		open: false, loaded: false, loading: false, uid: '',
		busy: false, pending: null, rec: null, lastSugg: [], typingEl: null,
		ids: {}, sig: '', pollT: 0, polling: false, adminTypingEl: null, muted: false,
		unread: 0, typingSent: 0, pendingBot: false, lastInput: 0,
		hasMore: false, loadingMore: false, locked: true, stick: true, typeT: 0, initP: null,
		// polling/realtime: engaged = بازدیدکننده پیام دارد (فقط آن‌وقت polling/WebSocket شروع می‌شود)
		engaged: false, activityAt: Date.now(), rlMult: 1, retryAfter: 0, syncAgain: false, started: false
	};

	/* ---------- ابزارها ---------- */
	function lsGet(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } }
	function lsSet(k, v) { try { window.localStorage.setItem(k, v); } catch (e) { /* noop */ } }
	function ssGet(k) { try { return window.sessionStorage.getItem(k); } catch (e) { return null; } }
	function ssSet(k, v) { try { window.sessionStorage.setItem(k, v); } catch (e) { /* noop */ } }

	function el(tag, attrs, kids) {
		var n = document.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach(function (k) {
				if (k === 'class') { n.className = attrs[k]; }
				else if (k === 'html') { n.innerHTML = attrs[k]; }
				else if (k === 'text') { n.textContent = attrs[k]; }
				else { n.setAttribute(k, attrs[k]); }
			});
		}
		(kids || []).forEach(function (c) { if (c) { n.appendChild(c); } });
		return n;
	}

	var ICONS = {
		chat: '<path d="M21 11.5a8.4 8.4 0 0 1-8.9 8.4c-1.1 0-2.2-.2-3.2-.6L3.5 21l1.8-4.6A8.3 8.3 0 0 1 3.6 11.5 8.5 8.5 0 0 1 12.1 3a8.4 8.4 0 0 1 8.9 8.5Z"/><path d="M8.5 11.5h.01M12 11.5h.01M15.5 11.5h.01"/>',
		close: '<path d="M6 6l12 12M18 6 6 18"/>',
		send: '<path d="M21.5 3.5 10.3 14.7"/><path d="M21.5 3.5 14.6 20.5l-4.3-5.8-5.8-4.3 17-6.9Z"/>',
		clip: '<path d="m20.2 11.5-8.1 8.1a5.2 5.2 0 0 1-7.4-7.4l8.4-8.4a3.5 3.5 0 0 1 4.9 4.9l-8.4 8.4a1.7 1.7 0 0 1-2.5-2.5l7.7-7.7"/>',
		mic: '<rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5.5 11.5a6.5 6.5 0 0 0 13 0M12 18v3"/>',
		trash: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 12.2a1.8 1.8 0 0 0 1.8 1.6h6.4a1.8 1.8 0 0 0 1.8-1.6L18 7M9 7V4.5h6V7"/>',
		spark: '<path d="M12 3.5l1.9 5.1a2 2 0 0 0 1.2 1.2l5.1 1.9-5.1 1.9a2 2 0 0 0-1.2 1.2L12 20l-1.9-5.2a2 2 0 0 0-1.2-1.2l-5.1-1.9 5.1-1.9a2 2 0 0 0 1.2-1.2L12 3.5Z"/>',
		retry: '<path d="M20 12a8 8 0 1 1-2.5-5.8M20 4v5h-5"/>',
		bag: '<path d="M6 7h12l1.2 12.2a1.8 1.8 0 0 1-1.8 2H6.6a1.8 1.8 0 0 1-1.8-2L6 7Z"/><path d="M9 10V6.5a3 3 0 0 1 6 0V10"/>',
		doc: '<path d="M14 3H7.5A1.5 1.5 0 0 0 6 4.5v15A1.5 1.5 0 0 0 7.5 21h9a1.5 1.5 0 0 0 1.5-1.5V7l-4-4Z"/><path d="M14 3v4h4M9 12h6M9 16h6"/>',
		news: '<path d="M5 5h11a1 1 0 0 1 1 1v13H6a1 1 0 0 1-1-1V5Z"/><path d="M17 9h2v9a1 1 0 0 1-1 1M8 9h6M8 13h6"/>',
		chev: '<path d="m15 6-6 6 6 6"/>'
	};
	function icon(name, cls) {
		return '<svg class="pc-svg ' + (cls || '') + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + ICONS[name] + '</svg>';
	}

	function fmtTime(iso) {
		var d = iso ? new Date(iso) : new Date();
		try { return d.toLocaleTimeString(cfg.locale || 'fa-IR', { hour: '2-digit', minute: '2-digit' }); }
		catch (e) { return d.toLocaleTimeString(); }
	}

	function linkify(parent, text) {
		var re = /(https?:\/\/[^\s<]+[^\s<.,;:!?)\]'"»،؛])/g, last = 0, m;
		while ((m = re.exec(text))) {
			if (m.index > last) { parent.appendChild(document.createTextNode(text.slice(last, m.index))); }
			var a = el('a', { href: m[0], target: '_blank', rel: 'noopener noreferrer nofollow' });
			a.textContent = m[0];
			parent.appendChild(a);
			last = re.lastIndex;
		}
		if (last < text.length) { parent.appendChild(document.createTextNode(text.slice(last))); }
	}

	/* کارت‌های کوچک لینک (محصول/مقاله/صفحه) زیر پیام هوش مصنوعی: فقط عکس + عنوان + یک خط اطلاعات؛ با کلیک لینک باز می‌شود.
	   سرور نشانگرهای [product-link:…] را از متن برداشته و اطلاعات کارت را از دیتابیس خوانده است (Chat\\MessageLinks). */
	var KIND_ICON = { product: 'bag', blog: 'news', page: 'doc' };
	function cardNode(c) {
		var href = /^https?:\/\//i.test(c.url || '') ? c.url : '#';
		var kind = KIND_ICON[c.kind] ? c.kind : 'page';
		var media = el('span', { class: 'pc-lc-media' + (c.image ? '' : ' is-empty'), html: c.image ? '' : icon(KIND_ICON[kind]) });
		if (c.image) {
			var img = el('img', { src: c.image, alt: '', loading: 'lazy', decoding: 'async' });
			img.addEventListener('error', function () { media.classList.add('is-empty'); media.innerHTML = icon(KIND_ICON[kind]); });
			media.appendChild(img);
		}
		var sub = c.meta ? (c.label ? c.label + ' · ' + c.meta : c.meta) : (c.label || '');
		return el('a', { class: 'pc-lcard pc-lc-' + kind, href: href, target: '_blank', rel: 'noopener noreferrer', title: c.title }, [
			media,
			el('span', { class: 'pc-lc-text' }, [el('strong', { class: 'pc-lc-title', dir: 'auto', text: c.title }), sub ? el('small', { class: 'pc-lc-sub', dir: 'auto', text: sub }) : null]),
			el('span', { class: 'pc-lc-go', html: icon('chev') })
		]);
	}
	function linksNode(list) {
		var box = el('div', { class: 'pc-links' });
		list.forEach(function (c, i) { var n = cardNode(c); n.style.animationDelay = (i * 70) + 'ms'; box.appendChild(n); });
		return box;
	}

	/* ---------- API ---------- */
	function api(path, opts, noNonce) {
		opts = opts || {};
		var headers = {};
		if (!noNonce && !state.noNonce && cfg.nonce) { headers['X-WP-Nonce'] = cfg.nonce; }
		if (state.uid) { headers['X-PAIA-Visitor'] = state.uid; }
		var init = { method: opts.method || 'GET', headers: headers, credentials: 'same-origin' };
		if (opts.body) {
			if (typeof FormData !== 'undefined' && opts.body instanceof FormData) { init.body = opts.body; }
			else { headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.body); }
		}
		var url = cfg.restUrl + path;
		if (cfg.restUrl.indexOf('?') !== -1) { url = cfg.restUrl + path.replace('?', '&'); } // پیوند یکتای ساده: rest_route
		return fetch(url, init).then(function (r) {
			return r.json().then(function (j) { return { r: r, j: j }; }, function () { return { r: r, j: {} }; });
		}).then(function (x) {
			if (x.r.status === 403 && !noNonce && x.j && x.j.code === 'rest_cookie_invalid_nonce') {
				state.noNonce = true; // دفعات بعد دیگر nonce نامعتبر را نفرست (بدون ۴۰۳ اضافه)
				return api(path, opts, true); // nonce قدیمی (کش صفحه): به‌عنوان مهمان ادامه بده
			}
			if (x.r.status === 400 && x.j && x.j.code === 'paia_bad_visitor' && path.indexOf('chat/init') !== 0 && !opts._retried) {
				// uid نامعتبر/ناموجود (S-1): فقط init بازدیدکنندهٔ تازه می‌سازد؛ یک‌بار init و بعد تکرار همین درخواست.
				state.uid = ''; lsSet(K_UID, '');
				if (!state.reinitP) { state.reinitP = api('chat/init', {}, noNonce).then(applyInit).then(function () { state.reinitP = null; }, function (err) { state.reinitP = null; throw err; }); }
				return state.reinitP.then(function () { return api(path, Object.assign({}, opts, { _retried: true }), noNonce); });
			}
			if (!x.r.ok) {
				var e = new Error((x.j && x.j.message) || t.loadError);
				e.status = x.r.status;
				e.code = (x.j && x.j.code) || '';
				var ra = 0;
				try { ra = parseInt(x.r.headers.get('Retry-After'), 10) || 0; } catch (err) { /* noop */ }
				e.retryAfter = ra || ((x.j && parseInt(x.j.retry_after, 10)) || 0);
				throw e;
			}
			return x.j;
		});
	}

	/* ---------- ساخت DOM ---------- */
	var teaserTitle = el('strong', { text: cfg.teaser.title });
	var teaserText = el('span', { text: cfg.teaser.text });
	var teaser = el('div', { class: 'pc-teaser', role: 'status' }, [
		el('button', { class: 'pc-teaser-x', type: 'button', 'aria-label': t.close, html: icon('close') }),
		teaserTitle,
		teaserText
	]);

	var launcher = el('button', { class: 'pc-launcher', type: 'button', 'aria-label': t.open, 'aria-expanded': 'false' }, [
		el('span', { class: 'pc-glow', 'aria-hidden': 'true' }),
		el('span', { class: 'pc-ring', 'aria-hidden': 'true' }),
		el('span', { class: 'pc-face' }, [
			el('span', { class: 'pc-ic pc-ic-chat', html: icon('chat') }),
			el('span', { class: 'pc-ic pc-ic-close', html: icon('close') })
		]),
		el('span', { class: 'pc-badge', text: '1', 'aria-hidden': 'true' })
	]);

	var closeBtn = el('button', { class: 'pc-x', type: 'button', 'aria-label': t.close, html: icon('close') });

	// لوگوی هدر: اگر مدیر در «تنظیمات ← مدیریت چت» لوگو گذاشته باشد همان، وگرنه آیکن پیش‌فرض.
	function avatarNode() {
		var av = el('div', { class: 'pc-avatar', html: (cfg.botLogo ? '' : icon('spark')) + '<i class="pc-online"></i>' });
		if (cfg.botLogo) {
			var img = el('img', { class: 'pc-avatar-img', alt: '', src: cfg.botLogo });
			av.classList.add('has-logo');
			av.insertBefore(img, av.firstChild);
			// لوگو لود نشد: به آیکن پیش‌فرض برگرد.
			img.addEventListener('error', function () {
				av.classList.remove('has-logo');
				img.remove();
				av.insertAdjacentHTML('afterbegin', icon('spark'));
			});
		}
		return av;
	}

	var header = el('header', { class: 'pc-head' }, [
		avatarNode(),
		el('div', { class: 'pc-title' }, [el('strong', { text: cfg.botName }), el('span', { text: cfg.online ? cfg.onlineText : cfg.offlineText })]),
		closeBtn
	]);

	var onlineDot = header.querySelector('.pc-online');
	var subtitleEl = header.querySelector('.pc-title span');
	function applyOnline(on) {
		on = !!on;
		if (onlineDot) { onlineDot.classList.toggle('is-off', !on); }
		if (subtitleEl) { subtitleEl.textContent = on ? cfg.onlineText : cfg.offlineText; }
	}
	applyOnline(cfg.online);

	var screenError = el('div', { class: 'pc-screen pc-error' }, [
		el('p', { text: t.loadError }),
		el('button', { class: 'pc-btn pc-btn-primary', type: 'button', 'data-act': 'retry-init', html: icon('retry') + '<span>' + t.retry + '</span>' })
	]);

	/* فرم نام/تماس */
	var inName = el('input', { type: 'text', class: 'pc-input', placeholder: t.nameLabel, autocomplete: 'name', maxlength: '100', 'aria-label': t.nameLabel });
	var inContact = el('input', { type: 'text', class: 'pc-input', placeholder: t.contactLabel, autocomplete: 'email', inputmode: 'email', dir: 'auto', maxlength: '190', 'aria-label': t.contactLabel });
	var profErr = el('p', { class: 'pc-form-err', role: 'alert' });
	var btnStartLabel = el('span', { class: 'pc-btn-label', text: t.start });
	var btnGuestLabel = el('span', { class: 'pc-btn-label', text: t.guest });
	var btnStart = el('button', { class: 'pc-btn pc-btn-primary', type: 'submit' }, [btnStartLabel, el('span', { class: 'pc-btn-spin', 'aria-hidden': 'true' })]);
	var btnGuest = el('button', { class: 'pc-btn pc-btn-ghost', type: 'button' }, [btnGuestLabel, el('span', { class: 'pc-btn-spin', 'aria-hidden': 'true' })]);
	var profForm = el('form', { class: 'pc-form', novalidate: 'novalidate' }, [inName, inContact, profErr, btnStart, btnGuest]);
	var screenProfile = el('div', { class: 'pc-screen pc-profile' }, [
		el('div', { class: 'pc-intro' }, [el('span', { class: 'pc-intro-ic', html: icon('spark') }), el('p', { text: cfg.intro })]),
		profForm
	]);

	/* صفحهٔ چت */
	var msgs = el('div', { class: 'pc-msgs', role: 'log', 'aria-live': 'polite' });
	var sugg = el('div', { class: 'pc-sugg is-empty', role: 'group', 'aria-label': t.suggestions });
	var pendingBox = el('div', { class: 'pc-pending' });
	var toast = el('div', { class: 'pc-toast', role: 'alert' });

	var ta = el('textarea', { class: 'pc-ta', rows: '1', maxlength: '2000', placeholder: t.placeholder, 'aria-label': t.placeholder });
	var fileIn = el('input', { type: 'file', accept: 'image/png,image/jpeg,image/gif,image/webp', class: 'pc-file', tabindex: '-1', 'aria-hidden': 'true' });
	var btnAttach = el('button', { class: 'pc-icon-btn pc-attach', type: 'button', 'aria-label': t.attach, html: icon('clip') });
	var btnSend = el('button', { class: 'pc-act pc-send', type: 'submit', 'aria-label': t.send, html: icon('send') });
	var micOK = !!(cfg.voice && window.MediaRecorder && navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
	var btnMic = el('button', { class: 'pc-act pc-mic', type: 'button', 'aria-label': t.record, html: icon('mic') });

	var bars = el('div', { class: 'pc-wave', 'aria-hidden': 'true' });
	for (var i = 0; i < 18; i++) { var b = el('i'); b.style.setProperty('--d', ((i * 97) % 700) + 'ms'); b.style.setProperty('--h', (30 + ((i * 53) % 70)) + '%'); bars.appendChild(b); }
	var recTimer = el('span', { class: 'pc-rec-time', text: '0:00' });
	var recCancel = el('button', { class: 'pc-icon-btn pc-rec-cancel', type: 'button', 'aria-label': t.cancel, html: icon('trash') });
	var recSend = el('button', { class: 'pc-act pc-rec-send is-on', type: 'button', 'aria-label': t.send, html: icon('send') });
	var recBar = el('div', { class: 'pc-rec' }, [recCancel, el('span', { class: 'pc-rec-dot' }), recTimer, bars, recSend]);

	var field = el('div', { class: 'pc-field' }, [
		cfg.images ? btnAttach : null,
		ta,
		el('span', { class: 'pc-slot' }, [micOK ? btnMic : null, btnSend])
	]);
	var composer = el('form', { class: 'pc-composer', novalidate: 'novalidate' }, [field, recBar, fileIn]);
	if (!micOK) { composer.classList.add('no-mic'); }

	var screenChat = el('div', { class: 'pc-screen pc-chat' }, [msgs, toast, sugg, pendingBox, composer]);
	var body = el('div', { class: 'pc-body' }, [screenError, screenProfile, screenChat]);
	var win = el('section', { class: 'pc-window', role: 'dialog', 'aria-label': cfg.botName }, [el('div', { class: 'pc-aurora', 'aria-hidden': 'true' }), header, body]);

	root.appendChild(teaser);
	root.appendChild(win);
	root.appendChild(launcher);

	if (lsGet(K_SEEN)) { root.classList.add('is-seen'); }

	/* ---------- صفحه‌ها ---------- */
	function showScreen(name) {
		[['error', screenError], ['profile', screenProfile], ['chat', screenChat]].forEach(function (p) {
			p[1].classList.toggle('is-active', p[0] === name);
		});
		root.setAttribute('data-screen', name);
	}

	function scrollDown(smooth) {
		var go = function () { msgs.scrollTo ? msgs.scrollTo({ top: msgs.scrollHeight, behavior: smooth ? 'smooth' : 'auto' }) : (msgs.scrollTop = msgs.scrollHeight); };
		go();
		window.requestAnimationFrame(go);
	}

	function nearBottom() { return msgs.scrollHeight - msgs.scrollTop - msgs.clientHeight < 90; }

	function showToast(text) {
		toast.textContent = text;
		toast.classList.add('is-on');
		window.clearTimeout(showToast._t);
		showToast._t = window.setTimeout(function () { toast.classList.remove('is-on'); }, 3600);
	}

	/* ---------- پیام‌ها ---------- */
	/* ساخت ردیف پیام (بدون قرار دادن در صفحه). stick = ردیف پایین لیست است (با لود شدن عکس، لیست پایین می‌ماند). */
	function buildRow(m, animate, stick) {
		var isUser = m.role === 'user';
		var isAdmin = m.role === 'admin';
		var bubble = el('div', { class: 'pc-bubble' });
		var hasLinks = !!(m.links && m.links.length);
		var row;

		if (m.attachment && m.attachment.type === 'image') {
			var img = el('img', { src: m.attachment.url, alt: t.image, loading: 'lazy' });
			var link = el('a', { class: 'pc-img', href: m.attachment.url, target: '_blank', rel: 'noopener noreferrer' }, [img]);
			img.addEventListener('load', function () { if (stick && state.stick) { scrollDown(false); } });
			bubble.appendChild(link);
		} else if (m.attachment && m.attachment.type === 'audio') {
			bubble.appendChild(el('audio', { class: 'pc-audio', controls: 'controls', preload: 'metadata', src: m.attachment.url }));
		}
		if (m.text) {
			var p = el('p', { class: 'pc-text' });
			linkify(p, m.text);
			bubble.appendChild(p);
		}
		var hasBubble = !!(m.text || m.attachment || !hasLinks);
		if (hasBubble) { bubble.appendChild(el('time', { class: 'pc-time', text: fmtTime(m.time) })); }

		row = el('div', { class: 'pc-row ' + (isUser ? 'pc-user' : 'pc-bot') + (isAdmin ? ' pc-admin' : '') + (animate === false ? ' no-anim' : '') }, [hasBubble ? bubble : null]);
		// زیرپیام ساختاریافتهٔ لینک‌ها (کارت‌های کوچک محصول/مقاله/صفحه)
		if (hasLinks) {
			var box = linksNode(m.links);
			if (!hasBubble) { box.appendChild(el('time', { class: 'pc-time pc-time-solo', text: fmtTime(m.time) })); }
			row.appendChild(box);
		}
		if (isAdmin && m.admin_name) { row.appendChild(el('span', { class: 'pc-by', text: t.supportBy + ' ' + m.admin_name })); }
		if (m.id) { row.setAttribute('data-id', String(m.id)); state.ids[m.id] = true; }
		return row;
	}

	function addMsg(m, animate) {
		var row = buildRow(m, animate, true);
		var anchor = typingAnchor();
		if (anchor) { msgs.insertBefore(row, anchor); } else { msgs.appendChild(row); }
		scrollDown(animate !== false);
		return row;
	}

	/* اولین نشانگر تایپ در صفحه (پیام‌های جدید باید بالای آن بیایند) */
	function typingAnchor() {
		var a = state.typingEl && state.typingEl.parentNode ? state.typingEl : null;
		var b = state.adminTypingEl && state.adminTypingEl.parentNode ? state.adminTypingEl : null;
		if (a && b) { return (a.compareDocumentPosition(b) & 4) ? a : b; }
		return a || b;
	}

	/* تایپ پشتیبان (از روی وضعیت سرور) */
	function setAdminTyping(name) {
		if (name) {
			if (!state.adminTypingEl || !state.adminTypingEl.parentNode) {
				state.adminTypingEl = el('div', { class: 'pc-row pc-bot pc-typing pc-admin-typing', 'aria-hidden': 'true' }, [el('div', { class: 'pc-bubble' }, [el('i'), el('i'), el('i')])]);
				msgs.appendChild(state.adminTypingEl);
				scrollDown(true);
			}
			state.adminTypingEl.setAttribute('title', name);
		} else if (state.adminTypingEl) {
			if (state.adminTypingEl.parentNode) { state.adminTypingEl.parentNode.removeChild(state.adminTypingEl); }
			state.adminTypingEl = null;
		}
	}

	function showTyping() {
		if (state.typingEl || state.muted) { return; }
		state.typingEl = el('div', { class: 'pc-row pc-bot pc-typing', 'aria-hidden': 'true' }, [el('div', { class: 'pc-bubble' }, [el('i'), el('i'), el('i')])]);
		msgs.appendChild(state.typingEl);
		scrollDown(true);
	}
	function hideTyping() {
		if (state.typingEl && state.typingEl.parentNode) { state.typingEl.parentNode.removeChild(state.typingEl); }
		state.typingEl = null;
	}

	/* ---------- پیشنهاد پیام ---------- */
	function setSuggestions(list) {
		state.lastSugg = list || [];
		sugg.innerHTML = '';
		sugg.classList.remove('is-disabled');
		if (!state.lastSugg.length) { sugg.classList.add('is-empty'); return; }
		sugg.classList.remove('is-empty');
		state.lastSugg.forEach(function (txt, idx) {
			var chip = el('button', { class: 'pc-chip', type: 'button', text: txt });
			chip.style.setProperty('--i', idx);
			chip.addEventListener('click', function () { send(txt); });
			sugg.appendChild(chip);
		});
		sugg.scrollLeft = 0;
	}
	function lockSuggestions(on) {
		sugg.classList.toggle('is-disabled', on);
		Array.prototype.forEach.call(sugg.querySelectorAll('.pc-chip'), function (c) { c.disabled = on; });
	}

	/* ---------- قفل نوار ورودی ----------
	 * تا وقتی پیام‌های گفتگو لود می‌شوند کاربر می‌تواند تایپ کند ولی دکمهٔ ارسال (و ضبط ویس) قفل است. */
	function setLocked(on) {
		state.locked = !!on;
		btnSend.disabled = !!on;
		if (micOK) { btnMic.disabled = !!on; }
		composer.classList.toggle('is-locked', !!on);
	}

	/* اسکلت لودینگ به شکل پیام‌هایی که در لیست پیام‌ها بالا می‌آیند */
	function skeletonNode() {
		var wrap = el('div', { class: 'pc-skels', role: 'status', 'aria-label': t.loadingMsgs });
		[['bot', '74%', 58], ['user', '46%', 40], ['bot', '84%', 86], ['user', '58%', 40], ['bot', '62%', 50]].forEach(function (d, i) {
			var b = el('div', { class: 'pc-sk pc-sk-' + d[0] });
			b.style.width = d[1];
			b.style.height = d[2] + 'px';
			b.style.animationDelay = (i * 110) + 'ms, 0ms';
			wrap.appendChild(b);
		});
		return wrap;
	}

	/* صفحهٔ چت را فوراً باز می‌کند؛ پیام‌ها هنوز در راه‌اند. */
	function showChatLoading() {
		msgs.innerHTML = '';
		msgs.appendChild(skeletonNode());
		state.typingEl = null;
		state.adminTypingEl = null;
		state.ids = {};
		state.hasMore = false;
		setSuggestions([]);
		setLocked(true);
		showScreen('chat');
		window.setTimeout(function () { ta.focus(); }, 380);
	}

	/* ---------- بارگذاری اولیه ---------- */
	function applyInit(d) {
		if (d.uid && d.uid !== state.uid) { state.uid = d.uid; lsSet(K_UID, d.uid); }
		if (d.needs_profile) { showScreen('profile'); window.setTimeout(function () { inName.focus(); }, 380); return; }
		msgs.innerHTML = '';
		state.typingEl = null;
		state.adminTypingEl = null;
		state.ids = {};
		state.sig = d.sig || '';
		state.muted = !!d.ai_muted;
		state.hasMore = !!d.has_more;
		state.loadingMore = false;
		state.stick = true;
		applyOnline(d.online);

		// فقط ۱۰ پیام آخر می‌آید؛ پیام‌ها یکی‌یکی (با تأخیر کم) در لیست ظاهر می‌شوند.
		var frag = document.createDocumentFragment(), n = 0;
		(d.messages || []).forEach(function (m) {
			var row = buildRow(m, true, true);
			row.style.animationDelay = (Math.min(n++, 10) * 45) + 'ms';
			frag.appendChild(row);
		});
		if (d.welcome) {
			var w = buildRow({ role: 'assistant', text: d.welcome, time: new Date().toISOString() }, true, true);
			w.style.animationDelay = (Math.min(n++, 10) * 45) + 'ms';
			frag.appendChild(w);
		}
		msgs.appendChild(frag);
		setSuggestions(d.suggestions || []);
		showScreen('chat');
		state.loaded = true;
		setLocked(false);
		applyRt(d);
		applyThinking(d, true);
		scrollDown(false);
		// فقط بازدیدکنندهٔ دارای پیام polling/WebSocket می‌گیرد (uid خالی = هنوز هیچ پیامی نفرستاده؛ هیچ درخواستی نمی‌رود).
		state.engaged = !!state.uid && (d.messages || []).length > 0;
		bumpActivity();
		if (state.engaged) { startTransport(); }
		window.setTimeout(function () { ta.focus(); }, 380);
		window.requestAnimationFrame(maybeFill);
	}

	function init() {
		if (state.loading) { return state.initP; }
		state.loading = true;
		// بازدیدکنندهٔ قبلی یا کاربر واردشده: بدون صبر برای سرور صفحهٔ چت (با لودینگ پیام‌ها) باز می‌شود.
		// بازدیدکنندهٔ کاملاً تازه: فرم نام فوراً نشان داده می‌شود (ارسال فرم تا پایان این درخواست صبر می‌کند).
		if (state.uid || cfg.loggedIn) { showChatLoading(); } else { showScreen('profile'); }
		state.initP = api('chat/init').then(applyInit).catch(function () { showScreen('error'); }).then(function () { state.loading = false; });
		return state.initP;
	}

	/* ---------- پیام‌های قبلی (اسکرول به بالا) ---------- */
	function oldestRowId() {
		var r = msgs.querySelector('.pc-row[data-id]');
		return r ? parseInt(r.getAttribute('data-id'), 10) || 0 : 0;
	}

	function loadOlder() {
		if (!state.loaded || !state.hasMore || state.loadingMore) { return Promise.resolve(); }
		var before = oldestRowId();
		if (!before) { return Promise.resolve(); }
		state.loadingMore = true;

		var pill = el('div', { class: 'pc-older', role: 'status', 'aria-label': t.loadingOlder }, [
			el('span', { class: 'pc-older-pill' }, [el('i'), el('i'), el('i'), el('em', { text: t.loadingOlder })])
		]);
		msgs.insertBefore(pill, msgs.firstChild);

		return api('chat/history?before=' + before + '&limit=10').then(function (d) {
			var first = msgs.querySelector('.pc-row');
			var offset = first ? first.getBoundingClientRect().top : 0;
			var frag = document.createDocumentFragment();
			(d.messages || []).forEach(function (m) {
				if (state.ids[m.id]) { return; }
				frag.appendChild(buildRow(m, false, false));
			});
			if (pill.parentNode) { pill.parentNode.removeChild(pill); }
			msgs.insertBefore(frag, first || null);
			// جای اسکرول ثابت می‌ماند: پیام‌هایی که کاربر می‌دید همان‌جا می‌مانند و پیام‌های قبلی بالاتر اضافه می‌شوند.
			if (first) { msgs.scrollTop += first.getBoundingClientRect().top - offset; }
			state.hasMore = !!d.has_more;
		}).catch(function (e) {
			if (pill.parentNode) { pill.parentNode.removeChild(pill); }
			showToast((e && e.message) || t.loadError);
		}).then(function () { state.loadingMore = false; });
	}

	/* اگر ۱۰ پیام آخر صفحه را پر نکرد (اسکرولی وجود ندارد)، خودکار پیام‌های قبلی هم می‌آیند. */
	function maybeFill() {
		if (!state.loaded || !state.hasMore || state.loadingMore || !msgs.clientHeight) { return; }
		if (msgs.scrollHeight <= msgs.clientHeight + 40) { loadOlder().then(function () { window.requestAnimationFrame(maybeFill); }); }
	}

	msgs.addEventListener('scroll', function () {
		state.stick = nearBottom();
		if (msgs.scrollTop < 80) { loadOlder(); }
	}, { passive: true });

	/* ---------- ارسال ---------- */
	function clearPending() {
		if (state.pending && state.pending.url) { try { URL.revokeObjectURL(state.pending.url); } catch (e) { /* noop */ } }
		state.pending = null;
		pendingBox.innerHTML = '';
		pendingBox.classList.remove('is-on');
		updateComposer();
	}

	function send(text, file, kind) {
		if (state.busy || state.locked || !state.loaded) { return; } // پیام‌ها هنوز در حال بارگذاری‌اند
		text = (text || '').trim();
		if (!file && state.pending) { file = state.pending.file; kind = 'image'; }
		if (!text && !file) { return; }

		state.busy = true;
		lockSuggestions(true);
		ta.value = '';
		autoGrow();

		var localUrl = file ? URL.createObjectURL(file) : '';
		var row = addMsg({ role: 'user', text: text, time: new Date().toISOString(), attachment: file ? { type: kind, url: localUrl } : null });
		row.classList.add('is-sending');
		clearPending();
		state.stick = true;
		// تأخیر هوش مصنوعی: پیام جمع می‌شود و «در حال تایپِ هوش مصنوعی» فقط وقتی نشان داده می‌شود که تأخیر تمام شود و هوش مصنوعی واقعاً شروع کند.
		// بدون تأخیر (۰ ثانیه) هوش مصنوعی همان لحظه شروع می‌کند، پس نقطه‌ها فوراً می‌آیند.
		window.clearTimeout(state.typeT);
		if (cfg.aiDelay > 0) { hideTyping(); } else { showTyping(); }

		var fd = new FormData();
		fd.append('text', text);
		if (file) { fd.append('file', file, file.name); }
		// صفحهٔ جاری (فقط آدرس و عنوان؛ سرور slug و نوع را خودش می‌سازد)
		fd.append('page_url', location.href);
		fd.append('page_title', document.title || '');

		var minWait = new Promise(function (r) { window.setTimeout(r, 700); });
		Promise.all([api('chat/message', { method: 'POST', body: fd }), minWait]).then(function (res) {
			var d = res[0];
			row.classList.remove('is-sending');
			// بازدیدکنندهٔ تازه: ردیف او همین‌جا (با اولین پیام) ساخته شده و uid در پاسخ است.
			if (d.uid && d.uid !== state.uid) { state.uid = d.uid; lsSet(K_UID, d.uid); }
			// تأخیر هوش مصنوعی: پیام فقط ذخیره شده و پاسخ بعد از چند ثانیه سکوتِ تایپ می‌آید (d.wait = ثانیه‌های باقی‌مانده).
			if (d.user && d.user.id) { row.setAttribute('data-id', String(d.user.id)); state.ids[d.user.id] = true; }
			(d.replies || (d.reply ? [d.reply] : [])).forEach(function (m) { addMsg(m); });
			setSuggestions(d.suggestions || []);
			applyThinking(d, true);
			state.engaged = true;
			bumpActivity();
			startTransport();
			afterServerState(d);
		}).catch(function (e) {
			hideTyping();
			row.classList.remove('is-sending');
			row.classList.add('is-failed');
			var retry = el('button', { class: 'pc-retry', type: 'button', html: icon('retry') + '<span>' + t.sendFailed + ' — ' + t.retry + '</span>' });
			retry.addEventListener('click', function () {
				if (row.parentNode) { row.parentNode.removeChild(row); }
				state.busy = false;
				send(text, file, kind);
			});
			row.appendChild(retry);
			lockSuggestions(false);
			if (e && e.status && e.status !== 0 && e.message) { showToast(e.message); }
		}).then(function () {
			state.busy = false;
			if (state.syncAgain) { state.syncAgain = false; requestSync(); } // در حین ارسال خبری رسیده بود
		});
	}

	/* ---------- به‌روزرسانی دوره‌ای (polling) ---------- */
	function notifyTyping() {
		if (!state.loaded || !ta.value.trim()) { return; }
		var now = Date.now();
		if (now - state.typingSent < (cfg.poll.typing || 3000)) { return; }
		state.typingSent = now;
		api('chat/typing', { method: 'POST', body: {} }).catch(function () { /* noop */ });
	}

	function bumpUnread(n) {
		state.unread += n;
		var badge = launcher.querySelector('.pc-badge');
		if (badge) { badge.textContent = state.unread > 9 ? '9+' : String(state.unread); }
		root.classList.add('has-unread');
	}

	/* پیام جدید پشتیبان/بات وقتی چت بسته است: علاوه بر شمارندهٔ نخوانده، متن پیام بالای آیکن نمایش داده می‌شود */
	function showIncoming(m) {
		if (!m) { return; }
		var body = m.text || (m.attachment && m.attachment.type === 'audio' ? '🎤' : t.image);
		if (body.length > 110) { body = body.slice(0, 110) + '…'; }
		teaserTitle.textContent = t.newMessage + (m.role === 'admin' && m.admin_name ? ' · ' + m.admin_name : '');
		teaserText.textContent = body;
		root.classList.add('show-teaser');
		window.clearTimeout(showIncoming._t);
		showIncoming._t = window.setTimeout(function () { root.classList.remove('show-teaser'); }, 15000);
	}

	function syncMessages(list) {
		var seen = {}, minId = 0, added = 0, hadRows = Object.keys(state.ids).length > 0;
		list.forEach(function (m) { seen[m.id] = true; if (!minId || m.id < minId) { minId = m.id; } });

		// پیام‌های حذف‌شده (توسط پشتیبان/مدیر)
		Array.prototype.slice.call(msgs.querySelectorAll('.pc-row[data-id]')).forEach(function (row) {
			var id = parseInt(row.getAttribute('data-id'), 10);
			if (id >= minId && !seen[id]) { if (row.parentNode) { row.parentNode.removeChild(row); } delete state.ids[id]; }
		});

		// تاریخچه کاملاً پاک شده: دوباره از ابتدا (پیام خوش‌آمد)
		if (!list.length && hadRows) {
			api('chat/init').then(applyInit).catch(function () { /* noop */ });
			return;
		}

		var lastNew = null;
		var oldest = oldestRowId();
		list.forEach(function (m) {
			if (state.ids[m.id]) { return; }
			if (oldest && m.id < oldest) { return; } // پیام قدیمی که هنوز بارگذاری نشده؛ با اسکرول به بالا می‌آید
			addMsg(m);
			if (m.role !== 'user') { added++; lastNew = m; }
		});

		if (added) {
			bumpActivity();
			var last = list[list.length - 1];
			setSuggestions(last && last.role === 'assistant' ? (last.suggestions || []) : []);
			if (!state.open) { bumpUnread(added); showIncoming(lastNew); }
		}
	}

	/* نشانگر «در حال تایپِ هوش مصنوعی»:
	 *  - thinking = سرور واقعاً در حال ساخت پاسخ است ← نقطه‌ها نشان داده می‌شوند (مگر کاربر همان لحظه تایپ می‌کند).
	 *  - pending ولی نه thinking = پیام‌ها هنوز در تأخیر هوش مصنوعی جمع می‌شوند ← نقطه‌ها نیستند؛ فقط با تمام شدن wait ثانیه می‌آیند. */
	function applyThinking(d, force) {
		window.clearTimeout(state.typeT);
		state.pendingBot = !!d.pending;
		// force = پاسخِ خودِ درخواست ارسال/آغاز (busy هنوز true است)؛ در polling وسط ارسال چیزی پنهان نمی‌شود.
		if (!d.pending) { if (force || !state.busy) { hideTyping(); } return; }
		if (d.thinking) {
			if (Date.now() - state.lastInput < 2500) { hideTyping(); } else { showTyping(); }
			return;
		}
		if (cfg.aiDelay > 0) { hideTyping(); }
		var left = Math.max(0, parseInt(d.wait, 10) || 0);
		state.typeT = window.setTimeout(function () {
			if (state.pendingBot && Date.now() - state.lastInput >= 2500) { showTyping(); }
		}, left * 1000 + 150);
	}

	function applyPoll(d) {
		applyOnline(d.online);
		state.muted = !!d.ai_muted;
		setAdminTyping(d.admin_typing);
		if (d.admin_typing) { bumpActivity(); }
		if (d.changed && d.messages) { syncMessages(d.messages); }
		if (d.sig) { state.sig = d.sig; }
		applyRt(d);
		// پیام‌های جمع‌شده منتظر پاسخ‌اند: تا وقتی کاربر دوباره تایپ نکرده نقطه‌های تایپ نشان داده می‌شود.
		applyThinking(d);
		if (d.pending) { bumpActivity(); }
	}

	/* ---------- به‌روزرسانی دوره‌ای (polling) ----------
	 * چت بسته: هر cfg.poll.closed (۶۰ث). چت باز: cfg.poll.fast (۲ث)، بعد از cfg.poll.idle بی‌حرکتی ← mid (۵ث) ← max (۱۰ث)؛
	 * با هر پیام جدید/ارسال/تایپ/تایپِ پشتیبان دوباره fast. تا وقتی پاسخ در راه است (pending) fast می‌ماند.
	 * تب پنهان: هیچ درخواستی نمی‌رود (با برگشتن یک poll فوری). ۴۲۹ ← فاصله دو برابر (و حداقل Retry-After). */
	function bumpActivity() { state.activityAt = Date.now(); }

	function pollDelay() {
		var P = cfg.poll, d;
		if (state.pendingBot) { d = P.fast; }
		else if (!state.open) { d = P.closed; }
		else {
			var idle = Date.now() - state.activityAt;
			d = idle < P.idle ? P.fast : (idle < 2 * P.idle ? P.mid : P.max);
		}
		return Math.max(d * state.rlMult, state.retryAfter);
	}

	/* آیا الان polling دوره‌ای باید کار کند؟ (در فاز ۲/۳ فقط وقتی WebSocket در حال کار نیست) */
	function pollingActive() { return rt.state === 'idle' || rt.state === 'fallback'; }

	function schedulePoll() {
		window.clearTimeout(state.pollT);
		if (!state.loaded || !state.engaged || document.hidden || !pollingActive()) { return; }
		state.pollT = window.setTimeout(poll, pollDelay());
	}

	/* یک درخواست poll (هم برای polling دوره‌ای هم «واکشی بعد از اعلان»). */
	function fetchOnce() {
		if (!state.loaded || !state.engaged) { return Promise.resolve(); }
		if (state.busy || state.polling) { state.syncAgain = true; return Promise.resolve(); }
		state.polling = true;
		return api('chat/poll?sig=' + encodeURIComponent(state.sig) + '&limit=' + Math.min(50, Math.max(20, Object.keys(state.ids).length + 10)))
			.then(function (d) {
				state.rlMult = 1; state.retryAfter = 0;
				applyPoll(d);
				afterServerState(d);
			})
			.catch(function (e) {
				if (e && e.status === 429) {
					state.rlMult = Math.min(8, state.rlMult * 2);
					state.retryAfter = Math.max(0, (e.retryAfter || 0) * 1000);
				}
				/* سایر خطاها: دور بعد دوباره تلاش می‌شود */
			})
			.then(function () {
				state.polling = false;
				if (state.syncAgain && !state.busy) { state.syncAgain = false; return fetchOnce(); }
			});
	}

	function poll() {
		if (document.hidden || !pollingActive()) { return; }
		fetchOnce().then(schedulePoll);
	}

	/* درخواست یک واکشی (بعد از اعلان WebSocket، catch-up، یا نوبتِ پردازش تأخیر هوش مصنوعی) */
	function requestSync() { fetchOnce(); }

	/* بعد از هر پاسخ سرور (ارسال/poll): در حالت polling کاری لازم نیست؛ در حالت WebSocket نوبت بعدی واکشی (در صورت لزوم) تنظیم می‌شود. */
	function afterServerState(d) {
		if (pollingActive()) { window.clearTimeout(state.pollT); schedulePoll(); }
		else if (typeof armWatch === 'function') { armWatch(d); }
	}

	/* kill switch سمت وردپرس (فاز ۲): پاسخ‌های init/poll فیلد rt دارند. */
	/* ---------- WebSocket «فقط اعلان» + fallback خودکار به polling ----------
	 * rt.state: idle (فقط polling؛ realtime خاموش/ناموجود) | connecting (تلاش‌های اتصال؛ polling دوره‌ای متوقف) |
	 *           ws (متصل؛ هیچ polling دوره‌ای نیست) | fallback (polling فاز ۱ + تلاش مجدد پس‌زمینه).
	 * WebSocket هیچ محتوایی نمی‌آورد: new_message ← یک poll (همان endpoint)؛ همیشه با sig و dedup روی id، پس پیامی گم/دوبار نمی‌شود. */
	var rt = { state: 'idle', ws: null, fails: 0, authFails: 0, disabled: !cfg.realtime || typeof window.WebSocket === 'undefined',
		serverOff: false, retryT: 0, connT: 0, hbT: 0, hiddenT: 0, watchT: 0, typingT: 0, lastRx: 0, lastTry: 0, sleep: false, gen: 0 };
	var BACKOFF = [1000, 2000, 5000, 10000, 30000];

	function jitter(ms) { return Math.round(ms * (0.8 + Math.random() * 0.4)); }

	function applyRt(d) {
		if (!d || typeof d.rt === 'undefined' || !cfg.realtime) { return; }
		if (!d.rt) {
			rt.serverOff = true;
			rt.disabled = true;
			if (rt.ws || rt.state === 'connecting') { closeWs(); enterFallback('rt-off', true); }
		} else if (rt.serverOff) { rt.serverOff = false; rt.disabled = typeof window.WebSocket === 'undefined'; }
	}

	function startTransport() {
		if (state.started) { return; }
		state.started = true;
		if (wsWanted()) { connect(); } else { schedulePoll(); }
	}
	function wsWanted() { return !rt.disabled && !document.hidden && state.engaged && state.loaded; }

	function onOpenChange() {
		window.clearTimeout(state.pollT);
		if (state.engaged && pollingActive()) { schedulePoll(); }
	}

	function closeWs() {
		rt.gen++;
		window.clearTimeout(rt.connT); window.clearInterval(rt.hbT);
		var w = rt.ws; rt.ws = null;
		if (w) { w.onopen = w.onmessage = w.onclose = w.onerror = null; try { w.close(1000); } catch (e) { /* noop */ } }
	}

	function connect() {
		if (rt.disabled || rt.state === 'ws' || rt.ws || !state.engaged) { return; }
		var prev = rt.state;
		rt.state = prev === 'fallback' ? 'fallback' : 'connecting'; // در fallback polling ادامه دارد تا ready
		window.clearTimeout(state.pollT);
		if (rt.state === 'fallback') { schedulePoll(); }
		window.clearTimeout(rt.retryT);
		rt.lastTry = Date.now();
		var gen = ++rt.gen;
		api('chat/ws-token', { method: 'POST', body: {} }).then(function (d) {
			if (gen !== rt.gen) { return; }
			openWs(d, gen);
		}).catch(function (e) {
			if (gen !== rt.gen) { return; }
			if (e && (e.status === 403 || e.status === 404)) { rt.serverOff = e.status === 403; rt.disabled = rt.serverOff; if (rt.disabled) { enterFallback('token-' + e.status, true); return; } }
			onFail(e && e.status === 429 ? Math.max(1000, (e.retryAfter || 0) * 1000) : 0);
		});
	}

	function openWs(d, gen) {
		var url = d && d.ws_url, ws;
		if (!url || !/^wss?:\/\//i.test(url) || (location.protocol === 'https:' && /^ws:/i.test(url))) { onFail(0); return; }
		try { ws = new window.WebSocket(url); } catch (e) { rt.disabled = true; enterFallback('nows', true); return; }
		rt.ws = ws;
		var ready = false;
		rt.connT = window.setTimeout(function () { if (!ready && rt.ws === ws) { try { ws.close(); } catch (e) { /* noop */ } } }, 6000);
		ws.onopen = function () { try { ws.send(JSON.stringify({ type: 'auth', token: d.token })); } catch (e) { /* noop */ } };
		ws.onmessage = function (ev) {
			var m; try { m = JSON.parse(ev.data); } catch (e) { return; }
			if (!m || typeof m.type !== 'string') { return; }
			rt.lastRx = Date.now();
			if (m.type === 'ready') {
				ready = true; window.clearTimeout(rt.connT);
				rt.state = 'ws'; rt.fails = 0; rt.authFails = 0;
				window.clearTimeout(state.pollT); window.clearTimeout(rt.retryT);
				window.clearInterval(rt.hbT);
				rt.hbT = window.setInterval(function () { if (Date.now() - rt.lastRx > 55000) { try { ws.close(); } catch (e) { /* noop */ } } }, 10000);
				requestSync(); // catch-up بعد از هر اتصال
			} else if (m.type === 'new_message') { requestSync(); }
			else if (m.type === 'typing') {
				window.clearTimeout(rt.typingT);
				setAdminTyping(m.on ? 'support' : '');
				if (m.on) { bumpActivity(); rt.typingT = window.setTimeout(function () { setAdminTyping(''); }, 8000); }
			} else if (m.type === 'ping') { try { ws.send('{"type":"pong"}'); } catch (e) { /* noop */ } }
		};
		ws.onclose = function (ev) {
			if (rt.ws !== ws) { return; }
			rt.ws = null; window.clearTimeout(rt.connT); window.clearInterval(rt.hbT);
			if (rt.sleep || !state.engaged) { return; }
			var code = ev && ev.code;
			if (code === 4403 || code === 4429 || code === 4503) { enterFallback('code-' + code); }
			else if (code === 4401) { if (++rt.authFails >= 2) { enterFallback('auth'); } else { rt.state = 'connecting'; connect(); } }
			else if (code === 1000 && ready) { rt.state = 'connecting'; connect(); } // سقف عمر اتصال: با توکن تازه
			else { if (ready) { rt.fails = 0; } onFail(0); }
		};
	}

	/* شکست اتصال: تلاش مجدد با backoff؛ بعد از ۳ شکست پشت‌سرهم fallback. */
	function onFail(minDelay) {
		rt.ws = null;
		rt.fails++;
		if (rt.fails >= 3) { enterFallback('fails'); return; }
		rt.state = rt.state === 'fallback' ? 'fallback' : 'connecting';
		window.clearTimeout(rt.retryT);
		rt.retryT = window.setTimeout(connect, Math.max(minDelay || 0, jitter(BACKOFF[Math.min(rt.fails - 1, BACKOFF.length - 1)])));
	}

	function enterFallback(reason, noRetry) {
		window.clearTimeout(rt.retryT); window.clearTimeout(rt.watchT);
		rt.state = 'fallback'; rt.fails = 0;
		if (window.console && cfg.debug) { window.console.debug('paia: fallback to polling (' + reason + ')'); }
		if (state.engaged && !document.hidden) { window.clearTimeout(state.pollT); poll(); } // catch-up فوری + polling فاز ۱
		if (!noRetry) { rt.retryT = window.setTimeout(retryWs, jitter(cfg.poll.retry)); }
	}

	function retryWs() {
		if (rt.state !== 'fallback' || rt.disabled || rt.ws) { return; }
		if (document.hidden) { return; } // با برگشت به تب امتحان می‌شود
		connect();
		rt.retryT = window.setTimeout(retryWs, jitter(cfg.poll.retry));
	}

	/* نوبت بعدی واکشی در حالت WebSocket: تأخیر هوش مصنوعی (پردازش فقط با poll شروع می‌شود) و شبکهٔ ایمنی اگر اعلان گم شد. */
	function armWatch(d) {
		window.clearTimeout(rt.watchT);
		if (!d || !d.pending || d.ai_muted) { return; }
		var delay = d.thinking ? 45000 : (Math.max(0, parseInt(d.wait, 10) || 0) * 1000 + 1000);
		rt.watchT = window.setTimeout(requestSync, delay);
	}

	document.addEventListener('visibilitychange', function () {
		if (document.hidden) {
			window.clearTimeout(state.pollT);
			if (rt.state === 'ws') { window.clearTimeout(rt.hiddenT); rt.hiddenT = window.setTimeout(function () { rt.sleep = true; closeWs(); rt.state = 'idle'; }, 60000); }
			return;
		}
		window.clearTimeout(rt.hiddenT);
		if (!state.loaded || !state.engaged) { return; }
		bumpActivity();
		if (rt.sleep) { rt.sleep = false; rt.state = 'idle'; rt.fails = 0; if (!rt.disabled) { connect(); } else { poll(); } return; }
		if (rt.state === 'fallback') { poll(); if (Date.now() - rt.lastTry > 5000) { connect(); } }
		else if (rt.state === 'idle') { poll(); }
	});
	window.addEventListener('online', function () {
		if (rt.state === 'fallback' && Date.now() - rt.lastTry > 5000) { connect(); }
		else if (state.engaged && pollingActive()) { poll(); }
	});

	/* ---------- composer ---------- */
	function autoGrow() {
		ta.style.height = 'auto';
		ta.style.height = Math.min(ta.scrollHeight, 110) + 'px';
		updateComposer();
	}
	function updateComposer() {
		composer.classList.toggle('has-content', !!(ta.value.trim() || state.pending));
	}

	ta.addEventListener('input', function () { state.lastInput = Date.now(); bumpActivity(); window.clearTimeout(state.typeT); if (state.pendingBot) { hideTyping(); } autoGrow(); notifyTyping(); });
	ta.addEventListener('keydown', function (e) {
		if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); send(ta.value); }
	});
	composer.addEventListener('submit', function (e) { e.preventDefault(); send(ta.value); });

	btnAttach.addEventListener('click', function () { fileIn.click(); });
	fileIn.addEventListener('change', function () {
		var f = fileIn.files && fileIn.files[0];
		fileIn.value = '';
		if (!f) { return; }
		if (!/^image\/(png|jpe?g|gif|webp)$/i.test(f.type)) { showToast(t.badFile); return; }
		if (f.size > cfg.maxMb * 1048576) { showToast(t.tooBig); return; }
		clearPending();
		var url = URL.createObjectURL(f);
		state.pending = { file: f, url: url };
		var rm = el('button', { class: 'pc-pending-x', type: 'button', 'aria-label': t.remove, html: icon('close') });
		rm.addEventListener('click', clearPending);
		pendingBox.appendChild(el('div', { class: 'pc-thumb' }, [el('img', { src: url, alt: '' }), rm]));
		pendingBox.classList.add('is-on');
		updateComposer();
		ta.focus();
	});

	/* ---------- ویس ---------- */
	function pickMime() {
		var c = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'];
		for (var i = 0; i < c.length; i++) {
			try { if (window.MediaRecorder.isTypeSupported(c[i])) { return c[i]; } } catch (e) { /* noop */ }
		}
		return '';
	}
	function fmtDur(ms) {
		var s = Math.floor(ms / 1000);
		return Math.floor(s / 60) + ':' + ('0' + (s % 60)).slice(-2);
	}
	function stopStream(r) { if (r && r.stream) { r.stream.getTracks().forEach(function (tr) { tr.stop(); }); } }

	function startRec() {
		if (state.rec || state.busy) { return; }
		if (!micOK) { showToast(t.micUnsupported); return; }
		navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
			var mime = pickMime(), rec;
			try { rec = mime ? new window.MediaRecorder(stream, { mimeType: mime }) : new window.MediaRecorder(stream); }
			catch (e) { stream.getTracks().forEach(function (tr) { tr.stop(); }); showToast(t.micUnsupported); return; }
			var r = { rec: rec, stream: stream, chunks: [], start: Date.now(), send: false, timer: 0 };
			rec.ondataavailable = function (ev) { if (ev.data && ev.data.size) { r.chunks.push(ev.data); } };
			rec.onstop = function () {
				window.clearInterval(r.timer);
				stopStream(r);
				composer.classList.remove('is-recording');
				state.rec = null;
				var dur = Date.now() - r.start;
				if (!r.send || dur < 900 || !r.chunks.length) { return; }
				var type = rec.mimeType || mime || 'audio/webm';
				var ext = /ogg/.test(type) ? 'ogg' : (/mp4/.test(type) ? 'm4a' : 'webm');
				var blob = new Blob(r.chunks, { type: type });
				if (blob.size > cfg.maxMb * 1048576) { showToast(t.tooBig); return; }
				var f;
				try { f = new File([blob], 'voice.' + ext, { type: type }); } catch (e) { f = blob; f.name = 'voice.' + ext; }
				send('', f, 'audio');
			};
			state.rec = r;
			recTimer.textContent = '0:00';
			composer.classList.add('is-recording');
			r.timer = window.setInterval(function () {
				var d = Date.now() - r.start;
				recTimer.textContent = fmtDur(d);
				if (d >= 120000) { stopRec(true); }
			}, 250);
			rec.start();
		}).catch(function () { showToast(t.micDenied); });
	}
	function stopRec(doSend) {
		var r = state.rec;
		if (!r) { return; }
		r.send = !!doSend;
		try { if (r.rec.state !== 'inactive') { r.rec.stop(); } } catch (e) { stopStream(r); composer.classList.remove('is-recording'); state.rec = null; }
	}
	btnMic.addEventListener('click', startRec);
	recCancel.addEventListener('click', function () { stopRec(false); });
	recSend.addEventListener('click', function () { stopRec(true); });

	/* ---------- فرم پروفایل ---------- */
	function latinDigits(str) {
		return str.replace(/[۰-۹]/g, function (c) { return String(c.charCodeAt(0) - 1776); }).replace(/[٠-٩]/g, function (c) { return String(c.charCodeAt(0) - 1632); });
	}
	function profileBusy(on, guest) {
		btnStart.disabled = btnGuest.disabled = inName.disabled = inContact.disabled = !!on;
		btnStart.classList.toggle('is-loading', !!on && !guest);
		btnGuest.classList.toggle('is-loading', !!on && !!guest);
		btnStartLabel.textContent = (on && !guest) ? t.starting : t.start;
		btnGuestLabel.textContent = (on && guest) ? t.starting : t.guest;
	}
	function profileFail(message) {
		profErr.textContent = message || t.loadError;
		profForm.classList.remove('is-shake');
		void profForm.offsetWidth;
		profForm.classList.add('is-shake');
	}
	function submitProfile(guest) {
		profErr.textContent = '';
		var name = inName.value.trim(), contact = latinDigits(inContact.value.trim());
		// اعتبارسنجی سمت مرورگر (همان قاعدهٔ سرور) تا خطا بدون رفت‌وبرگشت شبکه نشان داده شود.
		if (!guest) {
			var phone = contact.replace(/[\s\-()]/g, '');
			if (!name) { profileFail(t.nameRequired); inName.focus(); return; }
			if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(contact) && !/^\+?\d{8,15}$/.test(phone)) { profileFail(t.contactInvalid); inContact.focus(); return; }
		}
		var body = guest ? { guest: true } : { name: name, contact: contact };
		profileBusy(true, guest); // دکمه فوراً لودینگ می‌گیرد؛ فرم تا پایان ثبت قفل است
		// اگر درخواست اولیهٔ چت هنوز تمام نشده، اول صبر می‌کنیم تا هویت (uid) ساخته شود.
		Promise.resolve(state.initP).then(function () {
			return api('chat/profile', { method: 'POST', body: body });
		}).then(applyInit).catch(function (e) {
			profileFail(e && e.message);
		}).then(function () { profileBusy(false); });
	}
	profForm.addEventListener('submit', function (e) { e.preventDefault(); submitProfile(false); });
	btnGuest.addEventListener('click', function () { submitProfile(true); });
	screenError.querySelector('[data-act="retry-init"]').addEventListener('click', init);

	/* ---------- باز/بسته ---------- */
	function setOpen(on) {
		state.open = on;
		root.classList.toggle('is-open', on);
		launcher.setAttribute('aria-expanded', on ? 'true' : 'false');
		launcher.setAttribute('aria-label', on ? t.close : t.open);
		if (on) { bumpActivity(); }
		if (state.loaded) { onOpenChange(); }
		if (on) {
			root.classList.remove('show-teaser');
			root.classList.add('is-seen');
			state.unread = 0;
			root.classList.remove('has-unread');
			lsSet(K_SEEN, '1');
			if (!state.loaded && !state.loading) { init(); }
			else if (state.loaded) { scrollDown(false); window.setTimeout(function () { ta.focus(); }, 380); }
		} else {
			launcher.focus();
		}
	}
	launcher.addEventListener('click', function () { setOpen(!state.open); });
	closeBtn.addEventListener('click', function () { setOpen(false); });
	teaser.addEventListener('click', function (e) {
		if (e.target.closest('.pc-teaser-x')) { ssSet(K_TEASER, '1'); root.classList.remove('show-teaser'); return; }
		setOpen(true);
	});
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && state.open) { if (state.rec) { stopRec(false); } else { setOpen(false); } }
	});

	/* پیام شناور بالای آیکن: یک‌بار در هر نشست */
	state.uid = lsGet(K_UID) || '';
	if (!ssGet(K_TEASER)) {
		window.setTimeout(function () { if (!state.open) { root.classList.add('show-teaser'); } }, 2600);
		window.setTimeout(function () { root.classList.remove('show-teaser'); }, 22000);
	}
	}

	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})();
