/**
 * تب «مدیریت چت» — چت بات هوشمند
 * فهرست گفتگوها (سمت راست در RTL) + پنجرهٔ چت با همان ظاهر ویجت سایت.
 * به‌روزرسانی لحظه‌ای با polling ساده (دقیقاً در تب مدیریت چت: هر ۱ ثانیه، وگرنه ۳ ثانیه؛ در حالت «بهینه‌شده» ۴ برابر کندتر)؛
 * تایپینگ از طریق جدول وضعیت سرور. کش گفتگوها و فهرست در localStorage و با sig به‌روز نگه داشته می‌شود.
 * پشتیبان مثل کاربر می‌تواند عکس و ویس بفرستد.
 * متن‌ها از window.PWC_CHATADMIN.i18n (PHP) می‌آیند.
 */
(function () {
	'use strict';

	var cfg = window.PWC_CHATADMIN;
	var root = document.getElementById('pwc-cm');
	if (!cfg || !root) { return; }
	var t = cfg.i18n;

	/* ---------- ابزارها ---------- */
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
		bag: '<path d="M6 7h12l1.2 12.2a1.8 1.8 0 0 1-1.8 2H6.6a1.8 1.8 0 0 1-1.8-2L6 7Z"/><path d="M9 10V6.5a3 3 0 0 1 6 0V10"/>',
		doc: '<path d="M14 3H7.5A1.5 1.5 0 0 0 6 4.5v15A1.5 1.5 0 0 0 7.5 21h9a1.5 1.5 0 0 0 1.5-1.5V7l-4-4Z"/><path d="M14 3v4h4M9 12h6M9 16h6"/>',
		news: '<path d="M5 5h11a1 1 0 0 1 1 1v13H6a1 1 0 0 1-1-1V5Z"/><path d="M17 9h2v9a1 1 0 0 1-1 1M8 9h6M8 13h6"/>',
		chev: '<path d="m15 6-6 6 6 6"/>',
		star: '<path d="m12 3.2 2.7 5.5 6 .9-4.4 4.2 1 6-5.3-2.8-5.4 2.8 1-6L3.3 9.6l6-.9L12 3.2Z"/>',
		spark: '<path d="M12 3.5l1.9 5.1a2 2 0 0 0 1.2 1.2l5.1 1.9-5.1 1.9a2 2 0 0 0-1.2 1.2L12 20l-1.9-5.2a2 2 0 0 0-1.2-1.2l-5.1-1.9 5.1-1.9a2 2 0 0 0 1.2-1.2L12 3.5Z"/>',
		user: '<circle cx="12" cy="8" r="3.6"/><path d="M4.5 20a7.5 7.5 0 0 1 15 0"/>',
		trash: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 12.2a1.8 1.8 0 0 0 1.8 1.6h6.4a1.8 1.8 0 0 0 1.8-1.6L18 7M9 7V4.5h6V7"/>',
		send: '<path d="M21.5 3.5 10.3 14.7"/><path d="M21.5 3.5 14.6 20.5l-4.3-5.8-5.8-4.3 17-6.9Z"/>',
		back: '<path d="M9 6l6 6-6 6"/>',
		close: '<path d="M6 6l12 12M18 6 6 18"/>',
		clip: '<path d="m20.2 11.5-8.1 8.1a5.2 5.2 0 0 1-7.4-7.4l8.4-8.4a3.5 3.5 0 0 1 4.9 4.9l-8.4 8.4a1.7 1.7 0 0 1-2.5-2.5l7.7-7.7"/>',
		mic: '<rect x="9" y="3" width="6" height="11" rx="3"/><path d="M5.5 11.5a6.5 6.5 0 0 0 13 0M12 18v3"/>'
	};
	function icon(name) {
		return '<svg class="pc-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + ICONS[name] + '</svg>';
	}

	function api(path, opts) {
		opts = opts || {};
		var headers = { 'X-WP-Nonce': cfg.nonce };
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
			if (!x.r.ok) {
				var e = new Error((x.j && x.j.message) || t.error);
				e.status = x.r.status;
				throw e;
			}
			return x.j;
		});
	}

	function qs(params) {
		return Object.keys(params).filter(function (k) { return params[k] !== '' && params[k] != null; }).map(function (k) {
			return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
		}).join('&');
	}

	function fmtTime(iso) {
		var d = iso ? new Date(iso) : new Date();
		try { return d.toLocaleTimeString(cfg.locale || 'fa-IR', { hour: '2-digit', minute: '2-digit' }); }
		catch (e) { return d.toLocaleTimeString(); }
	}
	function fmtShort(iso) {
		if (!iso) { return ''; }
		var d = new Date(iso), now = new Date();
		try {
			if (d.toDateString() === now.toDateString()) { return fmtTime(iso); }
			return d.toLocaleDateString(cfg.locale || 'fa-IR', { month: 'short', day: 'numeric' });
		} catch (e) { return d.toLocaleDateString(); }
	}
	function fmtDate(iso) {
		if (!iso) { return '—'; }
		try { return new Date(iso).toLocaleString(cfg.locale || 'fa-IR', { dateStyle: 'medium', timeStyle: 'short' }); }
		catch (e) { return new Date(iso).toLocaleString(); }
	}
	function fmtNum(n) {
		try { return Number(n).toLocaleString(cfg.locale || 'fa-IR'); } catch (e) { return String(n); }
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

	function initial(name) {
		name = (name || '').trim();
		return name ? Array.from(name)[0].toUpperCase() : '؟';
	}

	function isVisible() {
		return !document.hidden && root.offsetParent !== null;
	}

	/* ---------- وضعیت ---------- */
	var st = {
		filters: { unread: true, marked: false },
		q: '', items: [], counts: { all: 0, unread: 0, marked: 0 }, listSig: '',
		uid: '', sig: '', ids: {}, info: null, marked: false, aiMuted: false,
		userTypingEl: null, typingSent: 0, sending: false,
		listT: 0, convT: 0, listBusy: false, convBusy: false, queue: Promise.resolve(), flagBusy: 0, cache: {}, pending: 0, tmp: 0, all: [],
		srvSig: '', total: 0, listCache: null, pendingFile: null, rec: null
	};

	/* ---------- ساخت رابط ---------- */
	// پنل فهرست
	var tagUnread = el('button', { class: 'pcm-tag is-on', type: 'button', 'data-tag': 'unread', 'aria-pressed': 'true' }, [el('span', { text: t.unread }), el('b', { text: '0' })]);
	var tagMarked = el('button', { class: 'pcm-tag', type: 'button', 'data-tag': 'marked', 'aria-pressed': 'false' }, [el('span', { text: t.marked }), el('b', { text: '0' })]);
	var searchIn = el('input', { type: 'search', class: 'pcm-search', placeholder: t.search, 'aria-label': t.search });
	var listEl = el('ul', { class: 'pcm-list', role: 'list' });
	var listEmpty = el('p', { class: 'pcm-list-empty', text: t.emptyList });
	var side = el('aside', { class: 'pcm-side' }, [
		el('header', { class: 'pcm-side__head' }, [el('h3', { text: t.listTitle })]),
		el('div', { class: 'pcm-tags', role: 'group', 'aria-label': t.listTitle }, [tagUnread, tagMarked]),
		el('div', { class: 'pcm-searchbox' }, [searchIn]),
		listEl,
		listEmpty
	]);

	// پنجرهٔ چت
	var backBtn = el('button', { class: 'pcm-back', type: 'button', 'aria-label': t.back, html: icon('back') });
	var avatar = el('div', { class: 'pc-avatar pcm-avatar', text: '' });
	var titleName = el('strong', { text: '' });
	var titleSub = el('span', { text: '' });
	var btnMark = el('button', { class: 'pcm-tool', type: 'button' });
	var btnAi = el('button', { class: 'pcm-tool', type: 'button' });
	var btnInfo = el('button', { class: 'pcm-tool', type: 'button', html: icon('user') + '<span>' + t.userInfo + '</span>' });
	var btnClear = el('button', { class: 'pcm-tool pcm-tool--danger', type: 'button', html: icon('trash') + '<span>' + t.clear + '</span>' });
	var tools = el('div', { class: 'pcm-tools' }, [btnMark, btnAi, btnInfo, btnClear]);
	var head = el('header', { class: 'pc-head pcm-head' }, [
		backBtn, avatar, el('div', { class: 'pc-title' }, [titleName, titleSub])
	]);

	var msgs = el('div', { class: 'pc-msgs', role: 'log', 'aria-live': 'polite' });
	var emptyMsgs = el('p', { class: 'pcm-nomsg', text: t.noMessages });
	var aiNote = el('p', { class: 'pcm-ainote', text: t.aiMutedNote });
	var toast = el('div', { class: 'pc-toast', role: 'alert' });
	var ta = el('textarea', { class: 'pc-ta', rows: '1', maxlength: '2000', placeholder: t.placeholder, 'aria-label': t.placeholder });
	var fileIn = el('input', { type: 'file', accept: 'image/png,image/jpeg,image/gif,image/webp', class: 'pc-file', tabindex: '-1', 'aria-hidden': 'true' });
	var btnAttach = el('button', { class: 'pc-icon-btn pc-attach', type: 'button', 'aria-label': t.attach, title: t.attach, html: icon('clip') });
	var btnSend = el('button', { class: 'pc-act pc-send', type: 'submit', 'aria-label': t.send, html: icon('send') });
	var micOK = !!(cfg.mic && window.MediaRecorder && navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
	var btnMic = el('button', { class: 'pc-act pc-mic', type: 'button', 'aria-label': t.record, title: t.record, html: icon('mic') });
	var pendingBox = el('div', { class: 'pc-pending' });

	var bars = el('div', { class: 'pc-wave', 'aria-hidden': 'true' });
	for (var bi = 0; bi < 18; bi++) { var bar = el('i'); bar.style.setProperty('--d', ((bi * 97) % 700) + 'ms'); bar.style.setProperty('--h', (30 + ((bi * 53) % 70)) + '%'); bars.appendChild(bar); }
	var recTimer = el('span', { class: 'pc-rec-time', text: '0:00' });
	var recCancel = el('button', { class: 'pc-icon-btn pc-rec-cancel', type: 'button', 'aria-label': t.cancel, html: icon('trash') });
	var recSend = el('button', { class: 'pc-act pc-rec-send is-on', type: 'button', 'aria-label': t.send, html: icon('send') });
	var recBar = el('div', { class: 'pc-rec' }, [recCancel, el('span', { class: 'pc-rec-dot' }), recTimer, bars, recSend]);

	var field = el('div', { class: 'pc-field' }, [btnAttach, ta, el('span', { class: 'pc-slot' }, [micOK ? btnMic : null, btnSend])]);
	var composer = el('form', { class: 'pc-composer' + (micOK ? '' : ' no-mic'), novalidate: 'novalidate' }, [field, recBar, fileIn]);
	var chatScreen = el('div', { class: 'pc-screen is-active pcm-screen' }, [msgs, emptyMsgs, toast, aiNote, pendingBox, composer]);

	var infoBody = el('div', { class: 'pcm-info__body' });
	var infoClose = el('button', { class: 'pc-x', type: 'button', 'aria-label': t.close, html: icon('close') });
	var infoPanel = el('aside', { class: 'pcm-info', hidden: 'hidden' }, [
		el('header', { class: 'pcm-info__head' }, [el('strong', { text: t.userInfo }), infoClose]),
		infoBody
	]);

	var body = el('div', { class: 'pc-body' }, [chatScreen, infoPanel]);
	var win = el('section', { class: 'pc-window pcm-window', hidden: 'hidden' }, [el('div', { class: 'pc-aurora', 'aria-hidden': 'true' }), head, tools, body]);
	var placeholderBox = el('div', { class: 'pcm-empty' }, [el('p', { text: t.pickChat })]);
	var chatCol = el('section', { class: 'pcm-chat' }, [placeholderBox, win]);

	var layout = el('div', { class: 'pcm-layout', 'data-view': 'list' }, [side, chatCol]);
	root.appendChild(layout);

	/* ---------- کش (localStorage) ----------
	   گفتگوهای دیده‌شده و فهرست گفتگوها بلافاصله از کش نمایش داده می‌شوند و فقط با «امضا» (sig) از سرور اعتبارسنجی می‌شوند؛
	   اگر تغییری نباشد سرور چیزی نمی‌فرستد و خواندن از اول انجام نمی‌شود. کش بین بازدیدها و تب‌ها می‌ماند (قبلاً sessionStorage بود). */
	var CK = 'pwc_cm_cache_v3_' + cfg.me.id;
	var CACHE_TTL = 30 * 24 * 3600 * 1000; // گفتگوهای قدیمی‌تر از ۳۰ روز از کش پاک می‌شوند
	var CACHE_MAX = 15;
	function loadCache() {
		var out = { convs: {}, list: null };
		try {
			var raw = JSON.parse(window.localStorage.getItem(CK)) || {};
			var now = Date.now(), convs = raw.convs || {};
			Object.keys(convs).forEach(function (k) { if (now - (convs[k].at || 0) < CACHE_TTL) { out.convs[k] = convs[k]; } });
			out.list = raw.list && raw.list.items ? raw.list : null;
		} catch (e) { /* کش خراب یا غیرقابل‌دسترس: از اول */ }
		return out;
	}
	var saveT = 0;
	function writeCache() {
		window.localStorage.setItem(CK, JSON.stringify({ convs: st.cache, list: st.listCache }));
	}
	function saveCache() {
		window.clearTimeout(saveT);
		saveT = window.setTimeout(function () {
			var keys = Object.keys(st.cache);
			if (keys.length > CACHE_MAX) {
				keys.sort(function (a, b) { return (st.cache[a].at || 0) - (st.cache[b].at || 0); });
				keys.slice(0, keys.length - CACHE_MAX).forEach(function (k) { delete st.cache[k]; });
			}
			try { writeCache(); }
			catch (e) {
				// فضا پر شد: قدیمی‌ترین گفتگوها را کم کن و دوباره تلاش کن
				try {
					Object.keys(st.cache).sort(function (a, b) { return (st.cache[a].at || 0) - (st.cache[b].at || 0); }).slice(0, 8).forEach(function (k) { delete st.cache[k]; });
					writeCache();
				} catch (e2) { /* بی‌اهمیت */ }
			}
		}, 600);
	}
	function cacheSet(uid, patch) {
		var c = st.cache[uid] || (st.cache[uid] = {});
		Object.keys(patch).forEach(function (k) { c[k] = patch[k]; });
		c.at = Date.now();
		saveCache();
	}
	var loaded = loadCache();
	st.cache = loaded.convs;
	st.listCache = loaded.list;

	var loadingEl = el('div', { class: 'pcm-loading', 'aria-live': 'polite', hidden: 'hidden' }, [
		el('div', { class: 'pc-skel pc-skel-a' }), el('div', { class: 'pc-skel pc-skel-b' }), el('div', { class: 'pc-skel pc-skel-c' }), el('div', { class: 'pc-skel pc-skel-b' })
	]);
	chatScreen.appendChild(loadingEl);
	function setLoading(on) { loadingEl.hidden = !on; }

	/* ---------- نشان تب ---------- */
	var tabLink = document.getElementById('pwc-tab-chatmanage');
	var tabBadge = null;        // دایرهٔ قرمز: تعداد گفتگوهای خوانده‌نشده
	var tabBadgeMarked = null;  // دایرهٔ زرد: تعداد گفتگوهای مارک‌شده (مثلاً توسط هوش مصنوعی برای پاسخ پشتیبانان)
	function updateTabBadge() {
		if (!tabLink) { return; }
		var n = st.counts.unread || 0, k = st.counts.marked || 0;
		if (!n) { if (tabBadge && tabBadge.parentNode) { tabBadge.parentNode.removeChild(tabBadge); } tabBadge = null; }
		else {
			if (!tabBadge) { tabBadge = el('span', { class: 'pwc-tab__badge', title: t.unread, 'aria-label': t.unread }); tabLink.insertBefore(tabBadge, tabBadgeMarked); }
			tabBadge.textContent = fmtNum(n);
		}
		if (!k) { if (tabBadgeMarked && tabBadgeMarked.parentNode) { tabBadgeMarked.parentNode.removeChild(tabBadgeMarked); } tabBadgeMarked = null; }
		else {
			if (!tabBadgeMarked) { tabBadgeMarked = el('span', { class: 'pwc-tab__badge pwc-tab__badge--marked', title: t.marked, 'aria-label': t.marked }); tabLink.appendChild(tabBadgeMarked); }
			tabBadgeMarked.textContent = fmtNum(k);
		}
	}

	/* ---------- نوتیف منوی وردپرس (تعداد پیام‌های نخوانده؛ زنده با هر به‌روزرسانی فهرست) ---------- */
	function setBubble(host, n) {
		if (!host) { return; }
		var b = host.querySelector('.pwc-bubble');
		if (!n) { if (b && b.parentNode) { b.parentNode.removeChild(b); } return; }
		if (!b) {
			b = el('span', { class: 'update-plugins pwc-bubble' }, [el('span', { class: 'plugin-count' })]);
			host.appendChild(document.createTextNode(' '));
			host.appendChild(b);
		}
		b.className = 'update-plugins pwc-bubble count-' + n;
		b.firstChild.textContent = fmtNum(n);
	}
	function updateMenuBadges() {
		var n = st.total || 0;
		setBubble(document.querySelector('#toplevel_page_pwc > a .wp-menu-name'), n);
		setBubble(document.querySelector('#toplevel_page_pwc .wp-submenu a[href*="page=pwc-chatbot"]'), n);
	}

	/* ---------- فهرست گفتگوها ---------- */
	// فیلتر تگ‌ها آنی و سمت کلاینت است. اگر هر دو روشن باشند هر دو شرط با هم برقرار باشد (و).
	function visibleItems() {
		var q = st.q.toLowerCase();
		return st.all.filter(function (c) {
			if (st.filters.unread && !(c.unread > 0)) { return false; }
			if (st.filters.marked && !c.marked) { return false; }
			if (q && (c.name + ' ' + c.contact).toLowerCase().indexOf(q) === -1) { return false; }
			return true;
		});
	}

	function previewText(c) {
		var last = c.last;
		if (!last) { return ''; }
		var base = last.text || (last.attachment === 'image' ? t.image : (last.attachment === 'audio' ? t.voice : ''));
		if (last.role === 'admin') { return (last.admin_name ? last.admin_name : t.supportBy.replace(/:$/, '')) + ': ' + base; }
		if (last.role === 'assistant') { return t.aiLabel + ': ' + base; }
		return base;
	}

	function renderList() {
		st.items = visibleItems();
		var json = JSON.stringify([st.items, st.uid]);
		tagUnread.querySelector('b').textContent = fmtNum(st.counts.unread || 0);
		tagMarked.querySelector('b').textContent = fmtNum(st.counts.marked || 0);
		updateTabBadge();
		if (json === st.listSig) { return; }
		st.listSig = json;

		var top = listEl.scrollTop;
		listEl.innerHTML = '';
		listEmpty.hidden = st.items.length > 0;

		st.items.forEach(function (c) {
			var badges = el('span', { class: 'pcm-badges' });
			if (c.marked) { badges.appendChild(el('span', { class: 'pcm-mini pcm-mini--star', html: icon('star'), title: t.marked })); }
			if (c.ai_muted) { badges.appendChild(el('span', { class: 'pcm-mini pcm-mini--ai', html: icon('spark'), title: t.aiOff })); }
			if (c.unread > 0) { badges.appendChild(el('span', { class: 'pcm-unread', text: fmtNum(c.unread) })); }

			var btn = el('button', { class: 'pcm-item' + (c.uid === st.uid ? ' is-active' : '') + (c.unread > 0 ? ' is-unread' : '') + (c.marked ? ' is-marked' : ''), type: 'button', 'data-uid': c.uid }, [
				el('span', { class: 'pcm-av', text: initial(c.name) }),
				el('span', { class: 'pcm-ibody' }, [
					el('span', { class: 'pcm-irow' }, [el('strong', { class: 'pcm-name', text: c.name }), el('time', { text: c.last ? fmtShort(c.last.time) : '' })]),
					el('span', { class: 'pcm-irow' }, [el('span', { class: 'pcm-prev', text: previewText(c) }), badges])
				])
			]);
			listEl.appendChild(el('li', null, [btn]));
		});
		listEl.scrollTop = top;
	}

	/* فاصلهٔ polling: دقیقاً در تب «مدیریت چت» (و تب مرورگر فعال) fast = ۱ ثانیه، در غیر این صورت slow = ۳ ثانیه.
	   در حالت «بهینه‌شده» سرور هر دو را ۴ برابر کرده است. */
	function pollDelay() { return isVisible() ? cfg.poll.fast : cfg.poll.slow; }

	function loadList(force) {
		if (document.hidden || st.listBusy) { return Promise.resolve(); }
		st.listBusy = true;
		// sig: اگر فهرست در سرور تغییری نکرده باشد فقط {changed:false} برمی‌گردد (بدون ساخت و ارسال فهرست)
		var path = 'conversations' + (force || !st.srvSig ? '' : '?' + qs({ sig: st.srvSig }));
		return api(path).then(function (d) {
			if (st.flagBusy) { return; } // تغییر مارک/AI در جریان است؛ نتیجهٔ قدیمی نباید ظاهر را بپراند
			if (d.changed === false) { st.srvSig = d.sig || st.srvSig; return; }
			st.all = d.items || [];
			st.counts = d.counts || st.counts;
			st.total = d.total_unread || 0;
			st.srvSig = d.sig || '';
			st.listCache = { sig: st.srvSig, items: st.all, counts: st.counts, total: st.total, at: Date.now() };
			saveCache();
			renderList();
			updateMenuBadges();
		}).catch(function () { /* noop */ }).then(function () { st.listBusy = false; });
	}

	function listLoop() {
		window.clearTimeout(st.listT);
		st.listT = window.setTimeout(function () { loadList().then(listLoop); }, pollDelay());
	}

	/* فیلترهای بالای فهرست (خوانده‌نشده / مارک‌شده) به‌صورت محلی ذخیره می‌شوند و بعد از رفرش همان حالت می‌مانند. */
	var FK = 'pwc_cm_filters_v1_' + cfg.me.id;
	function saveFilters() {
		try { window.localStorage.setItem(FK, JSON.stringify(st.filters)); } catch (e) { /* noop */ }
	}
	function paintTag(b) {
		var on = !!st.filters[b.getAttribute('data-tag')];
		b.classList.toggle('is-on', on);
		b.setAttribute('aria-pressed', on ? 'true' : 'false');
	}
	try {
		var savedF = JSON.parse(window.localStorage.getItem(FK));
		if (savedF && typeof savedF === 'object') {
			st.filters.unread = !!savedF.unread;
			st.filters.marked = !!savedF.marked;
		}
	} catch (e) { /* noop */ }
	[tagUnread, tagMarked].forEach(paintTag);

	[tagUnread, tagMarked].forEach(function (b) {
		b.addEventListener('click', function () {
			var key = b.getAttribute('data-tag');
			st.filters[key] = !st.filters[key];
			saveFilters();
			b.classList.toggle('is-on', st.filters[key]);
			b.setAttribute('aria-pressed', st.filters[key] ? 'true' : 'false');
			st.listSig = '';
			renderList();
		});
	});

	searchIn.addEventListener('input', function () {
		st.q = searchIn.value.trim();
		st.listSig = '';
		renderList();
	});

	listEl.addEventListener('click', function (e) {
		var b = e.target.closest('.pcm-item');
		if (b) { openConversation(b.getAttribute('data-uid')); }
	});

	/* ---------- پیام‌ها ---------- */
	function scrollDown(smooth) {
		var go = function () { msgs.scrollTo ? msgs.scrollTo({ top: msgs.scrollHeight, behavior: smooth ? 'smooth' : 'auto' }) : (msgs.scrollTop = msgs.scrollHeight); };
		go();
		window.requestAnimationFrame(go);
	}
	function nearBottom() {
		return msgs.scrollHeight - msgs.scrollTop - msgs.clientHeight < 120;
	}
	function showToast(text) {
		toast.textContent = text;
		toast.classList.add('is-on');
		window.clearTimeout(showToast._t);
		showToast._t = window.setTimeout(function () { toast.classList.remove('is-on'); }, 3600);
	}
	function updateEmpty() {
		emptyMsgs.hidden = !!msgs.querySelector('.pc-row[data-id]');
	}

	var SPIN = '<span class="pcm-spin" role="status"></span>';

	/* کارت‌های لینک (محصول/مقاله/صفحه) زیر پیام هوش مصنوعی؛ همان ظاهر ویجت (استایل در widget.css) */
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
		list.forEach(function (c) { box.appendChild(cardNode(c)); });
		return box;
	}

	function addMsg(m, animate, replaceRow) {
		// از دید پشتیبان: کاربر = pc-user (همان ظاهر ویجت)، هوش مصنوعی و پشتیبان = pc-bot
		var isUser = m.role === 'user';
		var isAdmin = m.role === 'admin';
		var bubble = el('div', { class: 'pc-bubble' });

		if (m.attachment && m.attachment.type === 'image') {
			var img = el('img', { src: m.attachment.url, alt: t.image, loading: 'lazy' });
			img.addEventListener('load', function () { if (nearBottom()) { scrollDown(false); } });
			bubble.appendChild(el('a', { class: 'pc-img', href: m.attachment.url, target: '_blank', rel: 'noopener noreferrer' }, [img]));
		} else if (m.attachment && m.attachment.type === 'audio') {
			bubble.appendChild(el('audio', { class: 'pc-audio', controls: 'controls', preload: 'metadata', src: m.attachment.url }));
		}
		if (m.text) {
			var p = el('p', { class: 'pc-text' });
			linkify(p, m.text);
			bubble.appendChild(p);
		}
		var hasLinks = !!(m.links && m.links.length);
		var hasBubble = !!(m.text || m.attachment || !hasLinks);
		if (hasBubble) { bubble.appendChild(el('time', { class: 'pc-time', text: fmtTime(m.time) })); }

		var row = el('div', { class: 'pc-row ' + (isUser ? 'pc-user' : 'pc-bot') + (isAdmin ? ' pc-admin' : '') + (m.pending ? ' is-sending' : '') + (animate === false ? ' no-anim' : ''), 'data-id': String(m.id) }, [hasBubble ? bubble : null]);
		if (hasLinks) {
			var lbox = linksNode(m.links);
			if (!hasBubble) { lbox.appendChild(el('time', { class: 'pc-time pc-time-solo', text: fmtTime(m.time) })); }
			row.appendChild(lbox);
		}
		if (isAdmin && m.pending) {
			// هنوز ذخیره نشده: پیام همان لحظه روی چت می‌آید، با آیکن کوچک «در حال ارسال» زیرش
			row.appendChild(el('div', { class: 'pcm-meta' }, [el('span', { class: 'pcm-sending', title: t.sending, 'aria-label': t.sending, html: SPIN })]));
		} else if (isAdmin) {
			var meta = el('div', { class: 'pcm-meta' }, [el('span', { class: 'pc-by', text: t.supportBy + ' ' + (m.admin_name || '') })]);
			if (m.admin_id && m.admin_id === cfg.me.id) {
				// فقط پیام‌های ارسالیِ خودِ همین پشتیبان قابل حذف است
				var del = el('button', { class: 'pcm-del', type: 'button', title: t.deleteMsg, 'aria-label': t.deleteMsg, html: icon('trash') });
				del.addEventListener('click', function () { deleteMessage(m.id, row); });
				meta.appendChild(del);
			}
			row.appendChild(meta);
		} else if (!isUser) {
			row.appendChild(el('span', { class: 'pc-by', text: t.aiLabel }));
		}
		if (!m.pending) { st.ids[m.id] = true; }

		if (replaceRow && replaceRow.parentNode) { replaceRow.parentNode.replaceChild(row, replaceRow); }
		else if (st.userTypingEl && st.userTypingEl.parentNode) { msgs.insertBefore(row, st.userTypingEl); }
		else { msgs.appendChild(row); }
		updateEmpty();
		return row;
	}

	function setUserTyping(on) {
		if (on) {
			if (!st.userTypingEl || !st.userTypingEl.parentNode) {
				st.userTypingEl = el('div', { class: 'pc-row pc-user pc-typing', 'aria-hidden': 'true' }, [el('div', { class: 'pc-bubble' }, [el('i'), el('i'), el('i')])]);
				var stick = nearBottom();
				msgs.appendChild(st.userTypingEl);
				if (stick) { scrollDown(true); }
			}
		} else if (st.userTypingEl) {
			if (st.userTypingEl.parentNode) { st.userTypingEl.parentNode.removeChild(st.userTypingEl); }
			st.userTypingEl = null;
		}
	}

	function syncMessages(list) {
		var stick = nearBottom();
		var seen = {}, minId = 0;
		list.forEach(function (m) { seen[m.id] = true; if (!minId || m.id < minId) { minId = m.id; } });

		Array.prototype.slice.call(msgs.querySelectorAll('.pc-row[data-id]')).forEach(function (row) {
			var id = parseInt(row.getAttribute('data-id'), 10);
			if (id >= minId && !seen[id]) { row.parentNode.removeChild(row); delete st.ids[id]; }
		});

		var fresh = list.filter(function (m) { return !st.ids[m.id]; });
		var added = fresh.length;
		fresh.forEach(function (m) { addMsg(m, added <= 3); }); // انیمیشن فقط برای چند پیام تازه، نه بارگذاری کل تاریخچه
		updateEmpty();
		if (added && stick) { scrollDown(true); }
	}

	/* ---------- باز کردن گفتگو ---------- */
	function applyFlags() {
		// با polling ۱ ثانیه‌ای نباید هر بار دکمه‌ها از نو ساخته شوند (کلیک از دست می‌رود)
		var key = (st.marked ? 1 : 0) + '|' + (st.aiMuted ? 1 : 0);
		if (applyFlags._k === key) { return; }
		applyFlags._k = key;
		btnMark.innerHTML = icon('star') + '<span>' + (st.marked ? t.unmark : t.mark) + '</span>';
		btnMark.classList.toggle('is-on', st.marked);
		btnMark.setAttribute('aria-pressed', st.marked ? 'true' : 'false');
		btnAi.innerHTML = icon('spark') + '<span>' + (st.aiMuted ? t.aiOff : t.aiOn) + '</span>';
		btnAi.classList.toggle('is-on', !st.aiMuted);
		btnAi.classList.toggle('is-off', st.aiMuted);
		btnAi.setAttribute('aria-pressed', st.aiMuted ? 'false' : 'true');
		aiNote.hidden = !st.aiMuted;
	}

	function renderInfo() {
		var i = st.info;
		infoBody.innerHTML = '';
		if (!i) { return; }
		var rows = [
			[t.name, i.name],
			[t.contact, i.contact || '—'],
			[i.registered ? t.registered : t.guest, '']
		];
		if (i.user) {
			rows.push([t.username, i.user.login]);
			rows.push([t.email, i.user.email]);
			rows.push([t.roles, (i.user.roles || []).join('، ') || '—']);
			rows.push([t.joined, fmtDate(i.user.registered)]);
		}
		rows.push([t.firstSeen, fmtDate(i.first_seen)]);
		rows.push([t.lastSeen, fmtDate(i.last_seen)]);

		var dl = el('dl', { class: 'pcm-dl' });
		rows.forEach(function (r) {
			if (r[1] === '') { dl.appendChild(el('div', { class: 'pcm-dl__tag' }, [el('span', { class: 'pcm-chipstat' + (i.registered ? ' is-reg' : ''), text: r[0] })])); return; }
			var d = el('dd'); d.textContent = r[1];
			dl.appendChild(el('div', null, [el('dt', { text: r[0] }), d]));
		});
		infoBody.appendChild(dl);
		if (i.user && i.user.edit_url) {
			infoBody.appendChild(el('a', { class: 'pcm-btnlink', href: i.user.edit_url, target: '_blank', rel: 'noopener', text: t.openProfile }));
		} else if (!i.registered) {
			infoBody.appendChild(el('p', { class: 'pcm-note', text: t.noProfile }));
		}
	}

	function applyHead() {
		var i = st.info || {};
		avatar.textContent = initial(i.name);
		titleName.textContent = i.name || '';
		titleSub.textContent = (i.registered ? t.registered : t.guest) + (i.contact ? ' · ' + i.contact : '');
	}

	function applyConversation(d, first) {
		if (d.uid !== st.uid) { return; } // کاربر در همین فاصله گفتگوی دیگری را باز کرده
		if (d.info) { st.info = d.info; applyHead(); renderInfo(); } // در polling سبک info نمی‌آید
		if (!st.flagBusy) { st.marked = !!d.marked; st.aiMuted = !!d.ai_muted; }
		applyFlags();
		setUserTyping(!!d.typing);
		if (d.changed && d.messages) {
			syncMessages(d.messages);
			if (first) { scrollDown(false); }
			cacheSet(d.uid, { sig: d.sig, messages: d.messages.slice(-100) });
		}
		var cc = st.cache[d.uid];
		if (d.info || !cc || cc.marked !== st.marked || cc.aiMuted !== st.aiMuted) {
			cacheSet(d.uid, { info: st.info, marked: st.marked, aiMuted: st.aiMuted });
		}
		if (d.sig) { st.sig = d.sig; }
	}

	function openConversation(uid) {
		if (!uid) { return; }
		window.clearTimeout(st.convT);
		st.uid = uid;
		st.sig = '';
		st.ids = {};
		st.userTypingEl = null;
		msgs.innerHTML = '';
		ta.value = '';
		stopRec(false);
		clearPending();
		updateComposer();
		infoPanel.hidden = true;
		placeholderBox.hidden = true;
		win.hidden = false;
		layout.setAttribute('data-view', 'chat');
		st.listSig = '';
		renderList();

		// عنوان و وضعیت بلافاصله (از فهرست یا کش) نمایش داده می‌شود
		var cached = st.cache[uid];
		var item = st.all.filter(function (c) { return c.uid === uid; })[0];
		st.info = cached && cached.info ? cached.info : (item ? { name: item.name, contact: item.contact, registered: item.registered } : null);
		st.marked = cached ? !!cached.marked : !!(item && item.marked);
		st.aiMuted = cached ? !!cached.aiMuted : !!(item && item.ai_muted);
		applyHead();
		applyFlags();
		renderInfo();

		if (cached && cached.messages) {
			// دیده‌شده: از کش فوراً نمایش بده، بعد بی‌صدا با sig اعتبارسنجی کن
			cached.messages.forEach(function (m) { addMsg(m, false); });
			updateEmpty();
			scrollDown(false);
			st.sig = cached.sig || '';
			setLoading(false);
		} else {
			updateEmpty();
			setLoading(true);
		}

		api('conversation?' + qs({ uid: uid, sig: st.sig, read: isVisible() ? 1 : 0, full: 1 })).then(function (d) {
			if (uid !== st.uid) { return; }
			applyConversation(d, true);
			setLoading(false);
			convLoop();
			loadList();
			window.setTimeout(function () { ta.focus(); }, 60);
		}).catch(function (e) {
			if (uid === st.uid) { setLoading(false); showToast(e.message || t.error); }
		});
	}

	function pollConversation() {
		if (!st.uid || !isVisible() || st.convBusy || st.pending > 0) { return Promise.resolve(); }
		st.convBusy = true;
		var uid = st.uid;
		return api('conversation?' + qs({ uid: uid, sig: st.sig, read: 1 })).then(function (d) {
			applyConversation(d, false);
		}).catch(function () { /* noop */ }).then(function () { st.convBusy = false; });
	}
	function convLoop() {
		window.clearTimeout(st.convT);
		if (!st.uid) { return; }
		st.convT = window.setTimeout(function () { pollConversation().then(convLoop); }, pollDelay());
	}

	/* ---------- ارسال / حذف ---------- */
	function updateComposer() {
		composer.classList.toggle('has-content', !!(ta.value.trim() || st.pendingFile));
	}
	function autoGrow() {
		ta.style.height = 'auto';
		ta.style.height = Math.min(ta.scrollHeight, 110) + 'px';
		updateComposer();
	}

	/* ارسال خوش‌بینانه: پیام (متن، عکس یا ویس) فوراً با آیکن «در حال ارسال» می‌آید؛ بعد از ذخیره آیکن برداشته می‌شود. */
	function sendMessage() {
		var text = ta.value.trim(), file = null, kind = '';
		if (st.pendingFile) { file = st.pendingFile.file; kind = 'image'; }
		if ((!text && !file) || !st.uid) { return; }
		ta.value = '';
		autoGrow();
		clearPending();
		post(text, file, kind);
	}

	function post(text, file, kind) {
		st.typingSent = 0;
		var uid = st.uid;
		var localUrl = file ? URL.createObjectURL(file) : '';
		var tmp = { id: 'tmp' + (++st.tmp), role: 'admin', text: text, time: new Date().toISOString(), admin_name: cfg.me.name, admin_id: cfg.me.id, attachment: file ? { type: kind, url: localUrl } : null, pending: true };
		var row = addMsg(tmp, true);
		scrollDown(true);
		dispatch(uid, text, row, file, kind, localUrl);
	}

	function revoke(url) { if (url) { try { URL.revokeObjectURL(url); } catch (e) { /* noop */ } } }

	function dispatch(uid, text, row, file, kind, localUrl) {
		st.pending++;
		// پیام‌ها به ترتیب ارسال شوند
		st.queue = st.queue.then(function () {
			var body;
			if (file) {
				body = new FormData();
				body.append('uid', uid);
				body.append('text', text);
				body.append('file', file, file.name || (kind === 'audio' ? 'voice.webm' : 'image'));
			} else {
				body = { uid: uid, text: text };
			}
			return api('send', { method: 'POST', body: body }).then(function (d) {
				st.pending--;
				if (!d.message) { return; }
				if (uid === st.uid) {
					if (st.ids[d.message.id]) { if (row.parentNode) { row.parentNode.removeChild(row); } }
					else { addMsg(d.message, false, row); }
					// sig عمداً عوض نمی‌شود: پیامِ کاربر که هم‌زمان رسیده باشد در polling بعدی گرفته شود.
					var c = st.cache[uid];
					if (c && c.messages) { c.messages.push(d.message); saveCache(); }
					updateEmpty();
				}
				revoke(localUrl);
				loadList();
			}).catch(function (e) {
				st.pending--;
				row.classList.remove('is-sending');
				row.classList.add('is-failed');
				var meta = row.querySelector('.pcm-meta');
				if (meta) {
					meta.innerHTML = '';
					var retry = el('button', { class: 'pcm-retry', type: 'button', text: t.retry });
					retry.addEventListener('click', function () {
						if (row.parentNode) { row.parentNode.removeChild(row); }
						revoke(localUrl);
						post(text, file, kind);
					});
					meta.appendChild(retry);
				}
				showToast((e && e.message) || t.sendFailed);
			});
		});
	}

	/* ---------- عکس (پیش‌نمایش قبل از ارسال) ---------- */
	function clearPending() {
		if (st.pendingFile && st.pendingFile.url) { revoke(st.pendingFile.url); }
		st.pendingFile = null;
		pendingBox.innerHTML = '';
		pendingBox.classList.remove('is-on');
		updateComposer();
	}
	btnAttach.addEventListener('click', function () { fileIn.click(); });
	fileIn.addEventListener('change', function () {
		var f = fileIn.files && fileIn.files[0];
		fileIn.value = '';
		if (!f) { return; }
		if (!/^image\/(png|jpe?g|gif|webp)$/i.test(f.type)) { showToast(t.badFile); return; }
		if (f.size > cfg.maxMb * 1048576) { showToast(t.tooBig); return; }
		clearPending();
		var url = URL.createObjectURL(f);
		st.pendingFile = { file: f, url: url };
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
		var sec = Math.floor(ms / 1000);
		return Math.floor(sec / 60) + ':' + ('0' + (sec % 60)).slice(-2);
	}
	function stopStream(r) { if (r && r.stream) { r.stream.getTracks().forEach(function (tr) { tr.stop(); }); } }

	function startRec() {
		if (st.rec || !st.uid) { return; }
		if (!micOK) { showToast(t.micUnsupported); return; }
		navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
			var mime = pickMime(), rec;
			try { rec = mime ? new window.MediaRecorder(stream, { mimeType: mime }) : new window.MediaRecorder(stream); }
			catch (e) { stream.getTracks().forEach(function (tr) { tr.stop(); }); showToast(t.micUnsupported); return; }
			var r = { rec: rec, stream: stream, chunks: [], start: Date.now(), send: false, timer: 0, uid: st.uid };
			rec.ondataavailable = function (ev) { if (ev.data && ev.data.size) { r.chunks.push(ev.data); } };
			rec.onstop = function () {
				window.clearInterval(r.timer);
				stopStream(r);
				composer.classList.remove('is-recording');
				st.rec = null;
				var dur = Date.now() - r.start;
				if (!r.send || dur < 900 || !r.chunks.length || r.uid !== st.uid) { return; }
				var type = rec.mimeType || mime || 'audio/webm';
				var ext = /ogg/.test(type) ? 'ogg' : (/mp4/.test(type) ? 'm4a' : 'webm');
				var blob = new Blob(r.chunks, { type: type });
				if (blob.size > cfg.maxMb * 1048576) { showToast(t.tooBig); return; }
				var f;
				try { f = new File([blob], 'voice.' + ext, { type: type }); } catch (e) { f = blob; f.name = 'voice.' + ext; }
				post('', f, 'audio');
			};
			st.rec = r;
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
		var r = st.rec;
		if (!r) { return; }
		r.send = !!doSend;
		try { if (r.rec.state !== 'inactive') { r.rec.stop(); } } catch (e) { stopStream(r); composer.classList.remove('is-recording'); st.rec = null; }
	}
	btnMic.addEventListener('click', startRec);
	recCancel.addEventListener('click', function () { stopRec(false); });
	recSend.addEventListener('click', function () { stopRec(true); });

	function notifyTyping() {
		if (!st.uid || !ta.value.trim()) { return; }
		var now = Date.now();
		if (now - st.typingSent < cfg.poll.typing) { return; }
		st.typingSent = now;
		api('typing', { method: 'POST', body: { uid: st.uid } }).catch(function () { /* noop */ });
	}

	ta.addEventListener('input', function () { autoGrow(); notifyTyping(); });
	ta.addEventListener('keydown', function (e) {
		if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); sendMessage(); }
	});
	composer.addEventListener('submit', function (e) { e.preventDefault(); sendMessage(); });

	function deleteMessage(id, row) {
		if (!window.confirm(t.deleteConfirm)) { return; }
		// فوراً از چت برمی‌دارد؛ اگر سرور نپذیرفت سر جایش برمی‌گردد.
		var parent = row.parentNode, next = row.nextSibling;
		if (parent) { parent.removeChild(row); }
		delete st.ids[id];
		updateEmpty();
		var dc = st.cache[st.uid];
		if (dc && dc.messages) { dc.messages = dc.messages.filter(function (m) { return m.id !== id; }); saveCache(); }
		api('delete-message', { method: 'POST', body: { id: id } }).then(function () {
			loadList();
		}).catch(function (e) {
			if (parent) { parent.insertBefore(row, next && next.parentNode === parent ? next : null); }
			st.ids[id] = true;
			updateEmpty();
			showToast(e.message || t.error);
		});
	}

	/* ---------- دکمه‌های بالای چت ---------- */
	// تغییر آنی + نشانگر loading؛ فقط اگر ذخیره نشد به حالت قبل برمی‌گردد.
	function setFlags(data, btn) {
		var prev = { marked: st.marked, aiMuted: st.aiMuted }, uid = st.uid;
		if ('marked' in data) { st.marked = data.marked; }
		if ('ai_muted' in data) { st.aiMuted = data.ai_muted; }
		applyFlags();
		patchListItem(uid);
		btn.classList.add('is-loading');
		btn.disabled = true;
		st.flagBusy++;
		data.uid = uid;
		api('flags', { method: 'POST', body: data }).then(function (d) {
			if (uid === st.uid) { st.marked = !!d.marked; st.aiMuted = !!d.ai_muted; applyFlags(); }
			patchListItem(uid, d);
		}).catch(function (e) {
			if (uid === st.uid) { st.marked = prev.marked; st.aiMuted = prev.aiMuted; applyFlags(); }
			patchListItem(uid, { marked: prev.marked, ai_muted: prev.aiMuted });
			showToast(e.message || t.error);
		}).then(function () {
			st.flagBusy--;
			btn.classList.remove('is-loading');
			btn.disabled = false;
		});
	}

	// فهرست را بدون انتظار برای سرور با وضعیت جدید به‌روز می‌کند.
	function patchListItem(uid, flags) {
		st.all.forEach(function (c) {
			if (c.uid !== uid) { return; }
			c.marked = flags ? !!flags.marked : st.marked;
			c.ai_muted = flags ? !!flags.ai_muted : st.aiMuted;
		});
		st.listSig = '';
		renderList();
	}
	btnMark.addEventListener('click', function () { setFlags({ marked: !st.marked }, btnMark); });
	btnAi.addEventListener('click', function () { setFlags({ ai_muted: !st.aiMuted }, btnAi); });
	btnInfo.addEventListener('click', function () { infoPanel.hidden = !infoPanel.hidden; });
	infoClose.addEventListener('click', function () { infoPanel.hidden = true; });

	btnClear.addEventListener('click', function () {
		if (!st.uid || !window.confirm(t.clearConfirm)) { return; }
		// فوراً خالی می‌شود؛ اگر سرور نپذیرفت پیام‌ها برمی‌گردند.
		var uid = st.uid, saved = document.createDocumentFragment(), oldIds = st.ids;
		while (msgs.firstChild) { saved.appendChild(msgs.firstChild); }
		st.ids = {};
		st.userTypingEl = null;
		updateEmpty();
		btnClear.classList.add('is-loading');
		btnClear.disabled = true;
		cacheSet(uid, { sig: '', messages: [] });
		api('clear', { method: 'POST', body: { uid: uid } }).then(function () {
			st.sig = '';
			loadList();
		}).catch(function (e) {
			if (uid === st.uid) { msgs.appendChild(saved); st.ids = oldIds; updateEmpty(); scrollDown(false); }
			showToast(e.message || t.error);
		}).then(function () {
			btnClear.classList.remove('is-loading');
			btnClear.disabled = false;
		});
	});

	backBtn.addEventListener('click', function () {
		layout.setAttribute('data-view', 'list');
	});

	// با عوض‌شدن تب (مدیریت چت ↔ تب‌های دیگر) یا تب مرورگر، فوراً به‌روز می‌شود و فاصلهٔ polling (۱ ثانیه / ۳ ثانیه) از نو تنظیم می‌شود.
	function kick() {
		loadList();
		pollConversation();
		window.clearTimeout(st.listT);
		listLoop();
		if (st.uid) { window.clearTimeout(st.convT); convLoop(); }
	}
	document.addEventListener('visibilitychange', function () { if (!document.hidden) { kick(); } });
	// تب‌ها فقط hidden می‌شوند و hash عوض می‌شود
	window.addEventListener('hashchange', function () { window.setTimeout(kick, 50); });

	/* ---------- شروع ---------- */
	applyFlags();
	// فهرستِ کش‌شده فوراً نمایش داده می‌شود (بدون انتظار برای سرور)، بعد با sig اعتبارسنجی می‌شود.
	if (st.listCache) {
		st.all = st.listCache.items || [];
		st.counts = st.listCache.counts || st.counts;
		st.total = st.listCache.total || 0;
		st.srvSig = st.listCache.sig || '';
		renderList();
		updateMenuBadges();
	}
	loadList().then(listLoop);
})();
