/**
 * دستیار هوش مصنوعی وردپرس فارسی — صفحهٔ «پنل مانیتورینگ»
 *
 * هر بار صفحه باز شود (یا بازه/تقویم عوض شود) گزارش از GET paia/v1/monitoring گرفته می‌شود
 * (افزونه آن را به POST /v1/get_monitoring سرور هوش مصنوعی می‌فرستد) و کل صفحه با آن ساخته می‌شود.
 * بدون وابستگی؛ نمودارها SVG ساده‌اند. ساختار داده: MONITORING.md (بخش summary / series).
 */
( function () {
	'use strict';

	var cfg = window.PAIA_MONITOR || {};
	var root = document.getElementById( 'paia-monitoring' );
	if ( ! root ) {
		return;
	}

	/* ------------------------------------------------------------------ */
	/* ابزارهای قالب‌بندی                                                  */
	/* ------------------------------------------------------------------ */

	var FA_DIGITS = '۰۱۲۳۴۵۶۷۸۹';
	var NF = new Intl.NumberFormat( 'fa-IR', { maximumFractionDigits: 2 } );
	var NF1 = new Intl.NumberFormat( 'fa-IR', { maximumFractionDigits: 1 } );

	var JALALI_MONTHS = [ 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند' ];
	var GREG_MONTHS = [ 'ژانویه', 'فوریه', 'مارس', 'آوریل', 'مه', 'ژوئن', 'ژوئیه', 'اوت', 'سپتامبر', 'اکتبر', 'نوامبر', 'دسامبر' ];

	var COLORS = { violet: '#8b5cf6', cyan: '#06b6d4', green: '#34d399', amber: '#fbbf24', red: '#f87171', slate: '#94a3b8', pink: '#f472b6', blue: '#60a5fa' };

	function fa( text ) {
		return String( text ).replace( /[0-9]/g, function ( d ) {
			return FA_DIGITS.charAt( +d );
		} );
	}

	function esc( value ) {
		return String( value === null || value === undefined ? '' : value ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	}

	/** نام لاتین (مدل، مسیر، IP) داخل متن راست‌به‌چپ. */
	function ltr( value ) {
		return '<bdi class="paia-mon__ltr">' + esc( value ) + '</bdi>';
	}

	function has( v ) {
		return v !== null && v !== undefined && ! isNaN( v );
	}

	function num( n ) {
		return has( n ) ? NF.format( n ) : '—';
	}

	function compact( n ) {
		if ( ! has( n ) ) {
			return '—';
		}
		var a = Math.abs( n );
		if ( a >= 1e9 ) {
			return NF1.format( n / 1e9 ) + ' میلیارد';
		}
		if ( a >= 1e6 ) {
			return NF1.format( n / 1e6 ) + ' میلیون';
		}
		if ( a >= 1e4 ) {
			return NF1.format( n / 1e3 ) + ' هزار';
		}
		return NF.format( n );
	}

	function pct( x ) {
		return has( x ) ? NF1.format( x * 100 ) + '٪' : '—';
	}

	function ms( v ) {
		if ( ! has( v ) ) {
			return '—';
		}
		if ( v < 1000 ) {
			return num( Math.round( v ) ) + ' م‌ثانیه';
		}
		if ( v < 60000 ) {
			return NF1.format( v / 1000 ) + ' ثانیه';
		}
		return NF1.format( v / 60000 ) + ' دقیقه';
	}

	function secs( v ) {
		if ( ! has( v ) ) {
			return '—';
		}
		if ( v < 90 ) {
			return num( Math.round( v ) ) + ' ثانیه';
		}
		if ( v < 5400 ) {
			return NF1.format( v / 60 ) + ' دقیقه';
		}
		return NF1.format( v / 3600 ) + ' ساعت';
	}

	function bytes( v ) {
		if ( ! has( v ) ) {
			return '—';
		}
		if ( v < 1024 ) {
			return num( v ) + ' بایت';
		}
		if ( v < 1048576 ) {
			return NF1.format( v / 1024 ) + ' کیلوبایت';
		}
		if ( v < 1073741824 ) {
			return NF1.format( v / 1048576 ) + ' مگابایت';
		}
		return NF1.format( v / 1073741824 ) + ' گیگابایت';
	}

	function mb( v ) {
		return has( v ) ? bytes( v * 1048576 ) : '—';
	}

	/** خواندن امن مسیر «a.b.c» از شیء. */
	function g( obj, path, def ) {
		var cur = obj;
		var parts = path.split( '.' );
		for ( var i = 0; i < parts.length; i++ ) {
			if ( cur === null || typeof cur !== 'object' || ! ( parts[ i ] in cur ) ) {
				return def === undefined ? 0 : def;
			}
			cur = cur[ parts[ i ] ];
		}
		return cur;
	}

	function obj( o, path ) {
		var v = g( o, path, {} );
		return v && typeof v === 'object' ? v : {};
	}

	function ratio( a, b ) {
		return b > 0 ? a / b : null;
	}

	/* برچسب‌های فارسی برای مقدارهای شناخته‌شدهٔ سرور؛ هر چیز دیگر همان متن اصلی نمایش داده می‌شود. */
	var T = {
		status: { '2xx': 'موفق (2xx)', '3xx': 'تغییر مسیر (3xx)', '4xx': 'خطای درخواست (4xx)', '5xx': 'خطای سرور (5xx)', other: 'نامشخص' },
		role: { user: 'کاربر', assistant: 'هوش مصنوعی', admin: 'پشتیبان (مدیر)', support: 'پشتیبان', system: 'سیستم' },
		visitor: { guest: 'مهمان', logged_in: 'کاربر واردشده' },
		answer: { generated: 'تولید شد', failed: 'ناموفق' },
		mode: { sync: 'همزمان', async: 'غیرهمزمان' },
		qtype: { text: 'متن', voice: 'صدا', image: 'تصویر' },
		phase: { done: 'انجام شد', retry: 'تلاش دوباره', failed: 'ناموفق', queued: 'در صف', expired: 'منقضی شد', enqueued: 'وارد صف شد', skipped: 'رد شد' },
		outcome: { ok: 'موفق', transient: 'خطای موقت', bad_request: 'درخواست نامعتبر', auth: 'خطای احراز هویت', suspended: 'سایت تعلیق است', too_large: 'حجم زیاد', stale_ts: 'زمان قدیمی', unreachable: 'در دسترس نبود', http_error: 'خطای HTTP', bad_json: 'JSON نامعتبر', bad_shape: 'ساختار نامعتبر', site_error: 'خطای سایت', saved: 'ذخیره شد', trained: 'آموزش داده شد', untrained: 'حذف از آموزش' },
		siteState: { unhealthy: 'ناسالم', auth_failed: 'احراز هویت ناموفق', suspended: 'تعلیق', recovered: 'بازیابی شد' },
		finish: { stop: 'پایان عادی', length: 'قطع به‌دلیل طول', tool_calls: 'درخواست ابزار', content_filter: 'فیلتر محتوا' },
		purpose: { answer: 'پاسخ‌گویی', summary: 'خلاصه‌سازی' },
		kind: { product: 'محصولات', dynamic: 'اطلاعات پویا', products: 'محصولات', results: 'نتایج' },
		op: { ingest: 'آموزش (ingest)', query: 'جست‌وجو (query)' },
		att: { image: 'تصویر', voice: 'صدا', stored: 'ذخیره شد', failed: 'ناموفق' },
		svc: { api: 'API', ingestion: 'ingestion', delivery: 'تحویل callback', summarizer: 'خلاصه‌ساز' },
		ingestStatus: { created: 'ایجاد شد', updated: 'به‌روز شد', unchanged: 'بدون تغییر', deleted: 'حذف شد', trained: 'آموزش داده شد', untrained: 'حذف از آموزش' },
		events: {
			'http.request': 'درخواست HTTP', 'auth.rejected': 'احراز هویت ردشده', 'log.problem': 'هشدار/خطای لاگ', 'llm.chat': 'فراخوانی مدل چت', 'embedding.request': 'درخواست embedding',
			'rag.retrieval': 'جست‌وجوی دانش (RAG)', 'stt.transcribe': 'تبدیل گفتار به متن', 'tool.call': 'اجرای ابزار', 'tool.site_request': 'تماس با API سایت', 'chat.message': 'پیام گفتگو',
			'chat.answer': 'تولید پاسخ', 'chat.delete': 'حذف گفتگو/پیام', 'answer.job': 'کار پاسخ غیرهمزمان', 'summary.job': 'کار خلاصه‌سازی', 'ingest.enqueued': 'ورود به صف آموزش',
			'ingest.job': 'کار آموزش (ingest)', 'callback.send': 'ارسال callback', 'callback.site_state': 'تغییر وضعیت سایت', 'system.snapshot': 'نمونهٔ منابع سیستم', 'queue.snapshot': 'نمونهٔ صف‌ها',
			'storage.snapshot': 'نمونهٔ فضای ذخیره‌سازی', 'app.start': 'شروع پروسه', 'app.stop': 'توقف پروسه', 'monitor.cap_reached': 'پر شدن سقف روزانهٔ فایل', 'monitor.maintenance': 'نگهداری فایل‌ها',
		},
	};

	function tr( group, key ) {
		var m = T[ group ] || {};
		return Object.prototype.hasOwnProperty.call( m, key ) ? m[ key ] : String( key );
	}

	/* ------------------------------------------------------------------ */
	/* اجزای HTML                                                          */
	/* ------------------------------------------------------------------ */

	function kpi( name, value, hint, tone ) {
		return '<div class="paia-stat' + ( tone ? ' paia-stat--' + tone : '' ) + '"><p class="paia-stat__name">' + esc( name ) + '</p><div class="paia-stat__value">' + value + '</div>' +
			( hint ? '<div class="paia-stat__hint">' + hint + '</div>' : '' ) + '</div>';
	}

	function kpis( list ) {
		var html = list.filter( Boolean ).join( '' );
		return html ? '<div class="paia-summary paia-mon__kpis">' + html + '</div>' : '';
	}

	function note( text ) {
		return '<p class="paia-note">' + text + '</p>';
	}

	function sub( title, html ) {
		return html ? '<div class="paia-mon__sub"><h3 class="paia-mon__subtitle">' + esc( title ) + '</h3>' + html + '</div>' : '';
	}

	function cols( blocks ) {
		var html = blocks.filter( Boolean ).join( '' );
		return html ? '<div class="paia-mon__cols">' + html + '</div>' : '';
	}

	function section( id, title, html, hint ) {
		if ( ! html || ! html.replace( /\s/g, '' ) ) {
			return '';
		}
		return '<section class="paia-card paia-mon__sec" id="mon-' + id + '" data-title="' + esc( title ) + '"><div class="paia-card__head"><h2 class="paia-card__title">' + esc( title ) + '</h2>' +
			( hint ? '<span class="paia-count">' + hint + '</span>' : '' ) + '</div><div class="paia-mon__secbody">' + html + '</div></section>';
	}

	function table( head, rows, empty ) {
		if ( ! rows.length ) {
			return empty === false ? '' : note( empty || 'موردی ثبت نشده است.' );
		}
		var h = '<div class="paia-mon__tablewrap"><table class="paia-mon__table"><thead><tr>';
		head.forEach( function ( t, i ) {
			h += '<th' + ( i ? ' class="is-num"' : '' ) + '>' + esc( t ) + '</th>';
		} );
		h += '</tr></thead><tbody>';
		rows.forEach( function ( r ) {
			h += '<tr>';
			r.forEach( function ( c, i ) {
				h += '<td' + ( i ? ' class="is-num"' : '' ) + '>' + c + '</td>';
			} );
			h += '</tr>';
		} );
		return h + '</tbody></table></div>';
	}

	/** نوار افقی: items = [{label (HTML), value, text?, color?}] */
	function hbars( items, share ) {
		items = items.filter( function ( i ) {
			return i.value > 0;
		} );
		if ( ! items.length ) {
			return '';
		}
		var max = 0;
		var total = 0;
		items.forEach( function ( i ) {
			max = Math.max( max, i.value );
			total += i.value;
		} );
		var html = '<div class="paia-mon__hbars">';
		items.forEach( function ( i ) {
			var text = i.text || num( i.value ) + ( share ? ' <small>(' + pct( i.value / total ) + ')</small>' : '' );
			html += '<div class="paia-mon__hrow"><span class="paia-mon__hlabel">' + i.label + '</span><span class="paia-mon__htrack"><i style="width:' + Math.max( 2, ( i.value / max ) * 100 ).toFixed( 1 ) + '%;background:' + ( i.color || COLORS.violet ) + '"></i></span><span class="paia-mon__hval">' + text + '</span></div>';
		} );
		return html + '</div>';
	}

	/** {key: n} → آیتم‌های نوار، به ترتیب بیشترین. */
	function items( map, group, colorOf, limit ) {
		var list = Object.keys( map || {} ).map( function ( k ) {
			var v = map[ k ];
			return { key: k, value: typeof v === 'number' ? v : 0 };
		} );
		list.sort( function ( a, b ) {
			return b.value - a.value;
		} );
		return list.slice( 0, limit || 12 ).map( function ( i ) {
			return { label: group ? esc( tr( group, i.key ) ) : ltr( i.key ), value: i.value, color: colorOf ? colorOf( i.key ) : undefined };
		} );
	}

	function okBad( key ) {
		return /^(ok|done|generated|saved|stored|recovered|created|updated|unchanged|2xx|trained)$/.test( key ) ? COLORS.green : /^(retry|transient|warning|3xx|4xx|skipped|queued|untrained)$/.test( key ) ? COLORS.amber : /fail|error|dead|5xx|unhealthy|expired|suspended|auth|bad_|too_large|stale|unreachable/.test( key ) ? COLORS.red : COLORS.violet;
	}

	/** جدول تأخیر برای چند هیستوگرام: rows = [[نام, hist, 'ms'|'num']] */
	function latTable( rows ) {
		var out = [];
		rows.forEach( function ( r ) {
			var h = r[ 1 ];
			if ( ! h || ! h.n ) {
				return;
			}
			var f = r[ 2 ] === 'num' ? num : ms;
			out.push( [ esc( r[ 0 ] ), num( h.n ), f( h.avg ), f( h.p50 ), f( h.p90 ), f( h.p95 ), f( h.p99 ), f( h.max ) ] );
		} );
		return table( [ 'شاخص', 'تعداد', 'میانگین', 'میانه', 'p90', 'p95', 'p99', 'بیشینه' ], out, false );
	}

	/* ------------------------------------------------------------------ */
	/* نمودار SVG                                                          */
	/* ------------------------------------------------------------------ */

	function niceMax( v ) {
		if ( ! ( v > 0 ) ) {
			return 1;
		}
		var p = Math.pow( 10, Math.floor( Math.log( v ) / Math.LN10 ) );
		var m = v / p;
		return ( m <= 1 ? 1 : m <= 2 ? 2 : m <= 2.5 ? 2.5 : m <= 5 ? 5 : 10 ) * p;
	}

	var charts = {};

	/**
	 * spec: { labels:[کوتاه], tips:[کامل], series:[{name,color,type:'bar'|'line',values:[]}], stacked, fmt(v) متن tooltip, axis(v) متن محور }
	 */
	function drawChart( box, spec ) {
		var n = spec.labels.length;
		var any = spec.series.some( function ( s ) {
			return s.values.some( function ( v ) {
				return v > 0;
			} );
		} );
		if ( ! n || ! any ) {
			box.innerHTML = note( 'در این بازه داده‌ای برای این نمودار ثبت نشده است.' );
			return;
		}

		var W = 640;
		var H = spec.height || 230;
		var pl = 66;
		var pr = 10;
		var pt = 12;
		var pb = 28;
		var iw = W - pl - pr;
		var ih = H - pt - pb;
		var step = iw / n;
		var bars = spec.series.filter( function ( s ) {
			return s.type === 'bar';
		} );
		var lines = spec.series.filter( function ( s ) {
			return s.type !== 'bar';
		} );
		var axis = spec.axis || function ( v ) {
			return v >= 1e6 ? NF.format( v / 1e6 ) + 'M' : v >= 1e3 ? NF.format( v / 1e3 ) + 'k' : NF.format( v );
		};
		var fmt = spec.fmt || num;

		var max = 0;
		for ( var i = 0; i < n; i++ ) {
			var stack = 0;
			spec.series.forEach( function ( s ) {
				var v = s.values[ i ] || 0;
				if ( s.type === 'bar' && spec.stacked ) {
					stack += v;
				} else {
					max = Math.max( max, v );
				}
			} );
			max = Math.max( max, stack );
		}
		max = niceMax( max );

		function y( v ) {
			return pt + ih - ( v / max ) * ih;
		}

		var svg = '<svg viewBox="0 0 ' + W + ' ' + H + '" class="paia-mon__svg" role="img" aria-label="' + esc( spec.title || '' ) + '"">';
		for ( var t = 0; t <= 4; t++ ) {
			var gv = ( max / 4 ) * t;
			var gy = y( gv );
			svg += '<line x1="' + pl + '" x2="' + ( W - pr ) + '" y1="' + gy + '" y2="' + gy + '" class="paia-mon__grid"/>';
			svg += '<text x="' + ( pl - 8 ) + '" y="' + ( gy + 4 ) + '" text-anchor="end" class="paia-mon__axis">' + esc( axis( gv ) ) + '</text>';
		}

		var every = Math.ceil( n / 9 );
		for ( var li = 0; li < n; li += every ) {
			svg += '<text x="' + ( pl + step * ( li + 0.5 ) ) + '" y="' + ( H - 8 ) + '" text-anchor="middle" class="paia-mon__axis">' + esc( spec.labels[ li ] ) + '</text>';
		}

		// ستون‌ها (کنار هم یا روی هم)
		var group = spec.stacked ? 1 : Math.max( 1, bars.length );
		var bw = Math.max( 2, Math.min( 26, ( step * 0.7 ) / group ) );
		bars.forEach( function ( s, si ) {
			for ( var b = 0; b < n; b++ ) {
				var v = s.values[ b ] || 0;
				if ( v <= 0 ) {
					continue;
				}
				var base = 0;
				if ( spec.stacked ) {
					for ( var k = 0; k < si; k++ ) {
						base += bars[ k ].values[ b ] || 0;
					}
				}
				var cx = pl + step * ( b + 0.5 ) + ( spec.stacked ? 0 : ( si - ( group - 1 ) / 2 ) * bw );
				var top = y( base + v );
				var h = Math.max( 1, y( base ) - top );
				svg += '<rect x="' + ( cx - bw / 2 ).toFixed( 1 ) + '" y="' + top.toFixed( 1 ) + '" width="' + bw.toFixed( 1 ) + '" height="' + h.toFixed( 1 ) + '" rx="2" fill="' + s.color + '" data-b="' + b + '"/>';
			}
		} );

		// خطوط (null = بدون داده؛ خط قطع می‌شود)
		lines.forEach( function ( s ) {
			var d = '';
			var open = false;
			var dots = '';
			for ( var p = 0; p < n; p++ ) {
				var v = s.values[ p ];
				if ( ! has( v ) ) {
					open = false;
					continue;
				}
				var px = pl + step * ( p + 0.5 );
				d += ( open ? 'L' : 'M' ) + px.toFixed( 1 ) + ' ' + y( v ).toFixed( 1 ) + ' ';
				open = true;
				if ( n <= 40 ) {
					dots += '<circle cx="' + px.toFixed( 1 ) + '" cy="' + y( v ).toFixed( 1 ) + '" r="2.6" fill="' + s.color + '"/>';
				}
			}
			svg += '<path d="' + d + '" fill="none" stroke="' + s.color + '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>' + dots;
		} );

		// ناحیهٔ حرکت موس
		for ( var o = 0; o < n; o++ ) {
			svg += '<rect x="' + ( pl + step * o ).toFixed( 1 ) + '" y="' + pt + '" width="' + step.toFixed( 1 ) + '" height="' + ih + '" fill="transparent" data-i="' + o + '" class="paia-mon__hit"/>';
		}
		svg += '</svg>';

		var legend = '<div class="paia-mon__legend">' + spec.series.map( function ( s ) {
			return '<span><i style="background:' + s.color + '"></i>' + esc( s.name ) + '</span>';
		} ).join( '' ) + '</div>';

		box.innerHTML = legend + '<div class="paia-mon__plot">' + svg + '<div class="paia-mon__tip" hidden></div></div>';

		var plot = box.querySelector( '.paia-mon__plot' );
		var tip = box.querySelector( '.paia-mon__tip' );
		function show( ev ) {
			var idx = ev.target && ev.target.getAttribute ? ev.target.getAttribute( 'data-i' ) : null;
			if ( idx === null ) {
				return;
			}
			idx = +idx;
			var html = '<strong>' + esc( spec.tips[ idx ] ) + '</strong>';
			spec.series.forEach( function ( s ) {
				var v = s.values[ idx ];
				html += '<div><i style="background:' + s.color + '"></i>' + esc( s.name ) + ': <b>' + ( has( v ) ? esc( fmt( v ) ) : '—' ) + '</b></div>';
			} );
			tip.innerHTML = html;
			tip.hidden = false;
			var xPct = ( ( pl + step * ( idx + 0.5 ) ) / W ) * 100;
			tip.style.left = xPct + '%';
			tip.classList.toggle( 'is-right', xPct > 60 );
		}
		plot.addEventListener( 'pointermove', show );
		plot.addEventListener( 'pointerdown', show );
		plot.addEventListener( 'pointerleave', function () {
			tip.hidden = true;
		} );
	}

	/** جای‌نگهدار نمودار؛ بعد از ساخت صفحه کشیده می‌شود. */
	function chartBox( id, title, spec ) {
		charts[ id ] = spec;
		spec.title = title;
		return '<div class="paia-mon__chartcard"><h3 class="paia-mon__subtitle">' + esc( title ) + '</h3><div class="paia-mon__chart" data-chart="' + id + '"></div></div>';
	}

	/* ------------------------------------------------------------------ */
	/* وضعیت صفحه و درخواست                                                */
	/* ------------------------------------------------------------------ */

	var els = {};
	[ 'mon-error', 'mon-loading', 'mon-body', 'mon-label', 'mon-prev', 'mon-next', 'mon-today', 'mon-refresh', 'mon-jump' ].forEach( function ( r ) {
		els[ r ] = root.querySelector( '[data-role="' + r + '"]' );
	} );
	var periodBtns = [].slice.call( root.querySelectorAll( '[data-period]' ) );
	var calBtns = [].slice.call( root.querySelectorAll( '[data-calendar]' ) );

	var state = { period: 'day', date: '', calendar: 'jalali', seq: 0, data: null };

	function savedCalendar() {
		try {
			var c = window.localStorage.getItem( 'paia_mon_calendar' );
			return c === 'gregorian' ? 'gregorian' : 'jalali';
		} catch ( e ) {
			return 'jalali';
		}
	}

	function readHash() {
		var m = String( window.location.hash || '' ).replace( /^#/, '' ).split( '&' );
		var out = {};
		m.forEach( function ( p ) {
			var kv = p.split( '=' );
			if ( kv.length === 2 ) {
				out[ kv[ 0 ] ] = decodeURIComponent( kv[ 1 ] );
			}
		} );
		return out;
	}

	function writeHash() {
		var h = '#period=' + state.period + ( state.date ? '&date=' + encodeURIComponent( state.date ) : '' ) + '&calendar=' + state.calendar;
		if ( window.history && window.history.replaceState ) {
			window.history.replaceState( null, '', window.location.pathname + window.location.search + h );
		}
	}

	function setBusy( busy ) {
		els[ 'mon-loading' ].hidden = ! busy;
		els[ 'mon-refresh' ].classList.toggle( 'is-loading', busy );
		els[ 'mon-refresh' ].disabled = busy;
		[ 'mon-prev', 'mon-next', 'mon-today' ].forEach( function ( r ) {
			els[ r ].disabled = busy || els[ r ].getAttribute( 'data-off' ) === '1';
		} );
		periodBtns.concat( calBtns ).forEach( function ( b ) {
			b.disabled = busy;
		} );
		if ( busy ) {
			els[ 'mon-body' ].hidden = true;
			els[ 'mon-error' ].hidden = true;
			els[ 'mon-jump' ].hidden = true;
		}
	}

	function paintControls() {
		periodBtns.forEach( function ( b ) {
			b.classList.toggle( 'is-active', b.getAttribute( 'data-period' ) === state.period );
		} );
		calBtns.forEach( function ( b ) {
			b.classList.toggle( 'is-active', b.getAttribute( 'data-calendar' ) === state.calendar );
		} );
	}

	function prettyLabel( data ) {
		var p = data.period || {};
		var label = String( data.label || '' );
		var kind = data.kind || state.period;
		var parts = label.split( '-' ).map( function ( x ) {
			return parseInt( x, 10 );
		} );
		var names = ( p.calendar || state.calendar ) === 'jalali' ? JALALI_MONTHS : GREG_MONTHS;
		if ( kind === 'day' ) {
			return parts.length === 3 && ( p.calendar || state.calendar ) === 'jalali' ? fa( parts[ 2 ] ) + ' ' + names[ parts[ 1 ] - 1 ] + ' ' + fa( parts[ 0 ] ) : fa( label );
		}
		if ( kind === 'month' && parts.length >= 2 ) {
			return names[ parts[ 1 ] - 1 ] + ' ' + fa( parts[ 0 ] );
		}
		return fa( label );
	}

	function load() {
		var mine = ++state.seq;
		paintControls();
		writeHash();
		setBusy( true );

		var url = ( cfg.restUrl || '' ) + 'monitoring';
		var sep = url.indexOf( '?' ) === -1 ? '?' : '&';
		url += sep + 'period=' + encodeURIComponent( state.period ) + '&calendar=' + state.calendar + ( state.date ? '&date=' + encodeURIComponent( state.date ) : '' );

		var ctrl = window.AbortController ? new AbortController() : null;
		var timer = window.setTimeout( function () {
			if ( ctrl ) {
				ctrl.abort();
			}
		}, 70000 );

		window.fetch( url, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce || '' },
			signal: ctrl ? ctrl.signal : undefined,
		} ).then( function ( res ) {
			return res.json().catch( function () {
				return {};
			} ).then( function ( data ) {
				return { ok: res.ok, status: res.status, data: data };
			} );
		} ).catch( function () {
			return { ok: false, status: 0, data: { message: 'ارتباط با سرور برقرار نشد یا زمان انتظار تمام شد.' } };
		} ).then( function ( r ) {
			window.clearTimeout( timer );
			if ( mine !== state.seq ) {
				return; // درخواست جدیدتری ثبت شده
			}
			setBusy( false );
			if ( ! r.ok || ! r.data || ! r.data.summary ) {
				var msg = ( r.data && r.data.message ) || 'دریافت اطلاعات ناموفق بود.';
				els[ 'mon-error' ].innerHTML = '<strong>دریافت گزارش مانیتورینگ ناموفق بود.</strong><br>' + esc( msg ) + '<br><button type="button" class="paia-btn paia-btn--ghost paia-mon__retry" data-role="mon-retry">تلاش دوباره</button>';
				els[ 'mon-error' ].hidden = false;
				return;
			}
			state.data = r.data;
			render( r.data );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* ساخت صفحه                                                           */
	/* ------------------------------------------------------------------ */

	function bucketInfo( key, unit, calendar ) {
		if ( unit === 'hour' ) {
			var hh = String( key ).slice( -5, -3 );
			return { short: fa( parseInt( hh, 10 ) ), full: 'ساعت ' + fa( hh + ':00' ) + ' تا ' + fa( ( ( parseInt( hh, 10 ) + 1 ) % 24 ) + ':00' ) };
		}
		var p = String( key ).split( '-' ).map( function ( x ) {
			return parseInt( x, 10 );
		} );
		var names = calendar === 'jalali' ? JALALI_MONTHS : GREG_MONTHS;
		if ( unit === 'month' ) {
			return { short: names[ p[ 1 ] - 1 ] || fa( key ), full: ( names[ p[ 1 ] - 1 ] || '' ) + ' ' + fa( p[ 0 ] ) };
		}
		return { short: fa( p.length === 3 ? p[ 2 ] : key ), full: p.length === 3 ? fa( p[ 2 ] ) + ' ' + ( names[ p[ 1 ] - 1 ] || '' ) + ' ' + fa( p[ 0 ] ) : fa( key ) };
	}

	function healthIssues( s, mon ) {
		var out = [];
		var r = s.rates || {};
		function add( level, text ) {
			out.push( { level: level, text: text } );
		}
		if ( ( r.http_5xx || 0 ) >= 0.05 ) {
			add( 'bad', 'نرخ خطای سرور (5xx) برابر ' + pct( r.http_5xx ) + ' است.' );
		} else if ( r.http_5xx > 0 ) {
			add( 'warn', 'بخشی از درخواست‌ها (' + pct( r.http_5xx ) + ') با خطای سرور (5xx) تمام شده است.' );
		}
		if ( ( r.answer_failed || 0 ) >= 0.1 ) {
			add( 'bad', pct( r.answer_failed ) + ' از پاسخ‌ها ناموفق بوده است.' );
		} else if ( r.answer_failed > 0.02 ) {
			add( 'warn', pct( r.answer_failed ) + ' از پاسخ‌ها ناموفق بوده است.' );
		}
		if ( ( r.llm_error || 0 ) >= 0.1 ) {
			add( 'bad', 'نرخ خطای فراخوانی مدل ' + pct( r.llm_error ) + ' است.' );
		} else if ( r.llm_error > 0.02 ) {
			add( 'warn', 'نرخ خطای فراخوانی مدل ' + pct( r.llm_error ) + ' است.' );
		}
		if ( ( r.tool_error || 0 ) > 0.1 ) {
			add( 'warn', 'نرخ خطای ابزارهای زنده ' + pct( r.tool_error ) + ' است؛ دسترسی به API سایت را بررسی کنید.' );
		}
		if ( ( r.callback_failed || 0 ) >= 0.5 ) {
			add( 'bad', 'بیش از نیمی از ارسال‌های callback به سایت ناموفق بوده است.' );
		} else if ( r.callback_failed > 0.1 ) {
			add( 'warn', 'نرخ شکست callback به سایت ' + pct( r.callback_failed ) + ' است.' );
		}
		if ( g( s, 'callbacks.dead' ) > 0 ) {
			add( 'bad', num( g( s, 'callbacks.dead' ) ) + ' ارسال callback به‌طور کامل شکست خورده و رها شده است.' );
		}
		if ( ( r.ingest_failed || 0 ) > 0 ) {
			add( 'warn', pct( r.ingest_failed ) + ' از کارهای آموزش (ingestion) ناموفق بوده است.' );
		}
		if ( ( r.rag_empty || 0 ) > 0.5 && g( s, 'rag.queries' ) >= 10 ) {
			add( 'warn', 'جست‌وجوی دانش در ' + pct( r.rag_empty ) + ' از پرسش‌ها نتیجه‌ای پیدا نکرده است (آستانهٔ RAG_MIN_SCORE یا آموزش اطلاعات را بررسی کنید).' );
		}
		if ( g( s, 'queues.answers_oldest_pending_s_max' ) > 120 ) {
			add( 'bad', 'صف پاسخ تا ' + secs( g( s, 'queues.answers_oldest_pending_s_max' ) ) + ' گیر کرده بوده است.' );
		}
		if ( g( s, 'queues.ingest_oldest_pending_s_max' ) > 900 ) {
			add( 'warn', 'صف آموزش تا ' + secs( g( s, 'queues.ingest_oldest_pending_s_max' ) ) + ' گیر کرده بوده است.' );
		}
		if ( g( s, 'queues.sites_unhealthy_last' ) > 0 ) {
			add( 'bad', num( g( s, 'queues.sites_unhealthy_last' ) ) + ' سایت متصل در وضعیت «ناسالم» است.' );
		}
		if ( g( s, 'queues.deliveries_dead_last' ) > 0 ) {
			add( 'warn', num( g( s, 'queues.deliveries_dead_last' ) ) + ' تحویل callback در صف «مرده» مانده است.' );
		}
		var free = g( s, 'storage.disk_free_mb_last', null );
		var total = g( s, 'storage.disk_total_mb_last', null );
		if ( has( free ) && ( free < 1024 || ( total && free / total < 0.05 ) ) ) {
			add( 'bad', 'فضای آزاد دیسک سرور کم است (' + mb( free ) + ').' );
		} else if ( has( free ) && ( free < 5120 || ( total && free / total < 0.1 ) ) ) {
			add( 'warn', 'فضای آزاد دیسک سرور رو به کم شدن است (' + mb( free ) + ').' );
		}
		if ( g( s, 'system.loop_lag_ms_max' ) > 1000 ) {
			add( 'warn', 'تأخیر event-loop تا ' + ms( g( s, 'system.loop_lag_ms_max' ) ) + ' رسیده است (پروسه زیر فشار بوده).' );
		}
		if ( g( s, 'problems.error' ) > 0 ) {
			add( 'warn', num( g( s, 'problems.error' ) ) + ' خطا در لاگ سرور ثبت شده است.' );
		}
		if ( g( s, 'security.auth_rejected' ) >= 20 ) {
			add( 'warn', num( g( s, 'security.auth_rejected' ) ) + ' درخواست به‌دلیل امضا/IP نامعتبر رد شده است.' );
		}
		if ( g( s, 'monitor.dropped_events' ) > 0 || g( s, 'monitor.cap_reached' ) > 0 ) {
			add( 'warn', 'بعضی رویدادهای مانیتورینگ ثبت نشده‌اند (پر شدن صف یا سقف روزانهٔ فایل)؛ آمار ممکن است کمی کمتر از واقعیت باشد.' );
		}
		if ( mon && mon.enabled === false ) {
			add( 'warn', 'مانیتورینگ در سرور خاموش است (MONITOR_ENABLED=false).' );
		}
		return out;
	}

	function renderHealth( data ) {
		var s = data.summary;
		var issues = healthIssues( s, data.monitor );
		var level = issues.some( function ( i ) {
			return i.level === 'bad';
		} ) ? 'bad' : issues.length ? 'warn' : 'ok';
		var title = level === 'ok' ? 'همه‌چیز عادی است' : level === 'warn' ? 'چند مورد نیازمند توجه است' : 'مشکل جدی دیده می‌شود';
		var cov = data.coverage || {};
		var meta = [];
		meta.push( 'بازه: <strong>' + esc( prettyLabel( data ) ) + '</strong>' );
		meta.push( 'نمودار: ' + ( data.unit === 'hour' ? 'ساعتی' : data.unit === 'month' ? 'ماهانه' : 'روزانه' ) );
		meta.push( num( s.records ) + ' رویداد' );
		if ( cov.days_raw || cov.days_rollup ) {
			meta.push( num( cov.days_raw || 0 ) + ' روز از فایل خام' + ( cov.days_rollup ? ' و ' + num( cov.days_rollup ) + ' روز از خلاصهٔ روزانه' : '' ) );
		}
		meta.push( 'آخرین دریافت: ' + fa( new Date( ( data.generated_at || 0 ) * 1000 ).toLocaleTimeString( 'fa-IR' ) ) + ( data.cached ? ' (از کش سرور)' : '' ) );

		var html = '<section class="paia-card paia-mon__health is-' + level + '" id="mon-health" data-title="وضعیت کلی"><div class="paia-mon__healthhead"><span class="paia-mon__dot"></span><h2 class="paia-card__title">' + title + '</h2></div>';
		if ( issues.length ) {
			html += '<ul class="paia-mon__issues">' + issues.map( function ( i ) {
				return '<li class="is-' + i.level + '">' + esc( i.text ) + '</li>';
			} ).join( '' ) + '</ul>';
		}
		html += '<p class="paia-mon__meta">' + meta.join( ' <span>•</span> ' ) + '</p>';
		var missing = ( cov.days_missing || [] ).length;
		if ( missing && state.period !== 'day' ) {
			html += '<p class="paia-mon__meta paia-mon__meta--warn">برای ' + num( missing ) + ' روز از این بازه داده‌ای وجود ندارد (سرور خاموش بوده، مانیتورینگ روشن نبوده، یا داده‌ها پاک شده‌اند).</p>';
		}
		if ( cov.bad_lines ) {
			html += '<p class="paia-mon__meta paia-mon__meta--warn">' + num( cov.bad_lines ) + ' خط خراب در فایل‌های خام نادیده گرفته شد.</p>';
		}
		return html + '</section>';
	}

	function renderOverview( s ) {
		var r = s.rates || {};
		var http = obj( s, 'http' );
		var tone5 = ( r.http_5xx || 0 ) >= 0.05 ? 'bad' : r.http_5xx > 0 ? 'warn' : 'ok';
		var toneAns = ( r.answer_failed || 0 ) >= 0.1 ? 'bad' : r.answer_failed > 0.02 ? 'warn' : 'ok';
		var cost = s.cost;
		return section( 'overview', 'نمای کلی', kpis( [
			kpi( 'درخواست‌های HTTP', num( http.requests ), num( g( s, 'http.status.4xx' ) ) + ' خطای 4xx · ' + num( g( s, 'http.status.5xx' ) ) + ' خطای 5xx' ),
			kpi( 'نرخ خطای سرور (5xx)', pct( r.http_5xx || 0 ), '', tone5 ),
			kpi( 'تأخیر p95 درخواست‌ها', ms( g( s, 'http.lat.p95', null ) ), 'میانگین ' + ms( g( s, 'http.lat.avg', null ) ) ),
			kpi( 'پیام‌های کاربران', num( g( s, 'chat.messages.user' ) ), num( g( s, 'chat.conversations_created' ) ) + ' گفتگوی جدید' ),
			kpi( 'پاسخ‌های هوش مصنوعی', num( g( s, 'answers.total' ) ), num( g( s, 'answers.status.failed' ) ) + ' ناموفق' ),
			g( s, 'answers.total' ) ? kpi( 'نرخ شکست پاسخ', pct( r.answer_failed || 0 ), '', toneAns ) : '',
			g( s, 'answers.lat.n' ) ? kpi( 'زمان پاسخ (میانه)', ms( g( s, 'answers.lat.p50', null ) ), 'p95: ' + ms( g( s, 'answers.lat.p95', null ) ) ) : '',
			kpi( 'فراخوانی مدل چت', num( g( s, 'llm.calls' ) ), num( g( s, 'llm.errors' ) ) + ' خطا' ),
			kpi( 'توکن ورودی', compact( g( s, 'llm.prompt_tokens' ) ), 'از این تعداد ' + compact( g( s, 'llm.cached_tokens' ) ) + ' کش‌شده' ),
			kpi( 'توکن خروجی', compact( g( s, 'llm.completion_tokens' ) ), g( s, 'llm.reasoning_tokens' ) ? num( g( s, 'llm.reasoning_tokens' ) ) + ' توکن استدلال' : '' ),
			kpi( 'توکن embedding', compact( g( s, 'embedding.tokens' ) ), num( g( s, 'embedding.requests' ) ) + ' درخواست' ),
			kpi( 'جست‌وجوی دانش (RAG)', num( g( s, 'rag.queries' ) ), num( g( s, 'rag.empty' ) ) + ' بدون نتیجه' ),
			kpi( 'ابزارهای زنده', num( g( s, 'tools.calls' ) ), num( g( s, 'tools.errors' ) ) + ' خطا' ),
			kpi( 'کارهای آموزش انجام‌شده', num( g( s, 'ingest.phase.done' ) ), num( g( s, 'ingest.phase.failed' ) ) + ' ناموفق' ),
			kpi( 'ارسال callback به سایت', num( g( s, 'callbacks.sends' ) ), num( g( s, 'callbacks.dead' ) ) + ' ارسال رهاشده' ),
			kpi( 'خطا / هشدار لاگ', num( g( s, 'problems.error' ) ), num( g( s, 'problems.warning' ) ) + ' هشدار', g( s, 'problems.error' ) > 0 ? 'warn' : '' ),
			kpi( 'درخواست‌های ردشده (امنیت)', num( g( s, 'security.auth_rejected' ) ), 'امضا یا IP نامعتبر', g( s, 'security.auth_rejected' ) > 0 ? 'warn' : '' ),
			cost && cost.total ? kpi( 'هزینهٔ برآوردی', num( cost.total ), 'بر پایهٔ قیمت‌های تنظیم‌شده' ) : '',
		] ) );
	}

	function renderTrends( data ) {
		var unit = data.unit;
		var cal = ( data.period || {} ).calendar || state.calendar;
		var map = {};
		( data.series || [] ).forEach( function ( b ) {
			map[ b.bucket ] = b;
		} );
		var axis = data.axis || [];
		var labels = [];
		var tips = [];
		axis.forEach( function ( k ) {
			var i = bucketInfo( k, unit, cal );
			labels.push( i.short );
			tips.push( i.full );
		} );
		function vals( path, nullable ) {
			return axis.map( function ( k ) {
				var v = g( map[ k ] || {}, path, nullable ? null : 0 );
				return has( v ) ? v : nullable ? null : 0;
			} );
		}
		function spec( series, extra ) {
			return Object.assign( { labels: labels, tips: tips, series: series }, extra || {} );
		}
		var C = COLORS;
		var html = '<div class="paia-mon__charts">' + [
			chartBox( 'http', 'درخواست‌های HTTP', spec( [
				{ name: 'درخواست‌ها', color: C.violet, type: 'bar', values: vals( 'http.requests' ) },
				{ name: 'خطای 4xx', color: C.amber, type: 'line', values: vals( 'http.status.4xx' ) },
				{ name: 'خطای 5xx', color: C.red, type: 'line', values: vals( 'http.status.5xx' ) },
			] ) ),
			chartBox( 'chat', 'پیام‌ها و پاسخ‌ها', spec( [
				{ name: 'پیام کاربران', color: C.cyan, type: 'bar', values: vals( 'chat.messages.user' ) },
				{ name: 'پاسخ تولیدشده', color: C.green, type: 'line', values: vals( 'answers.status.generated' ) },
				{ name: 'پاسخ ناموفق', color: C.red, type: 'line', values: vals( 'answers.status.failed' ) },
			] ) ),
			chartBox( 'tokens', 'توکن‌های مصرفی مدل چت', spec( [
				{ name: 'ورودی', color: C.violet, type: 'bar', values: vals( 'llm.prompt_tokens' ) },
				{ name: 'خروجی', color: C.cyan, type: 'bar', values: vals( 'llm.completion_tokens' ) },
			], { stacked: true } ) ),
			chartBox( 'anslat', 'زمان تولید پاسخ', spec( [
				{ name: 'میانگین', color: C.cyan, type: 'line', values: vals( 'answers.lat.avg', true ) },
				{ name: 'p95', color: C.amber, type: 'line', values: vals( 'answers.lat.p95', true ) },
			], { fmt: ms, axis: function ( v ) {
				return v >= 1000 ? NF.format( v / 1000 ) + 'ث' : NF.format( v );
			} } ) ),
			chartBox( 'httplat', 'تأخیر درخواست‌های HTTP', spec( [
				{ name: 'میانه', color: C.green, type: 'line', values: vals( 'http.lat.p50', true ) },
				{ name: 'p95', color: C.amber, type: 'line', values: vals( 'http.lat.p95', true ) },
			], { fmt: ms, axis: function ( v ) {
				return v >= 1000 ? NF.format( v / 1000 ) + 'ث' : NF.format( v );
			} } ) ),
			chartBox( 'ingest', 'کارهای آموزش (ingestion)', spec( [
				{ name: 'انجام شد', color: C.green, type: 'bar', values: vals( 'ingest.phase.done' ) },
				{ name: 'ناموفق', color: C.red, type: 'bar', values: vals( 'ingest.phase.failed' ) },
			], { stacked: true } ) ),
			chartBox( 'problems', 'خطاها و هشدارهای لاگ', spec( [
				{ name: 'خطا', color: C.red, type: 'bar', values: vals( 'problems.error' ) },
				{ name: 'هشدار', color: C.amber, type: 'bar', values: vals( 'problems.warning' ) },
			], { stacked: true } ) ),
			chartBox( 'queues', 'بیشینهٔ موارد در انتظار صف', spec( [
				{ name: 'آموزش', color: C.violet, type: 'line', values: vals( 'queues.ingest_pending_max', true ) },
				{ name: 'پاسخ', color: C.cyan, type: 'line', values: vals( 'queues.answers_pending_max', true ) },
				{ name: 'خلاصه‌سازی', color: C.green, type: 'line', values: vals( 'queues.summaries_pending_max', true ) },
			] ) ),
			chartBox( 'ram', 'بیشینهٔ حافظهٔ مصرفی (RAM)', spec( [
				{ name: 'RAM', color: C.pink, type: 'line', values: vals( 'system.rss_mb_max', true ) },
			], { fmt: mb, axis: function ( v ) {
				return NF.format( Math.round( v ) );
			} } ) ),
		].join( '' ) + '</div>';
		return section( 'trends', 'روند در طول بازه', html, data.unit === 'hour' ? 'هر ستون: یک ساعت' : data.unit === 'month' ? 'هر ستون: یک ماه' : 'هر ستون: یک روز' );
	}

	function renderChat( s ) {
		var c = obj( s, 'chat' );
		var a = obj( s, 'answers' );
		var msgCount = 0;
		Object.keys( obj( c, 'messages' ) ).forEach( function ( k ) {
			msgCount += c.messages[ k ];
		} );
		var attach = [];
		Object.keys( obj( c, 'attachments' ) ).forEach( function ( type ) {
			Object.keys( c.attachments[ type ] ).forEach( function ( st ) {
				attach.push( { label: esc( tr( 'att', type ) + ' — ' + tr( 'att', st ) ), value: c.attachments[ type ][ st ], color: okBad( st ) } );
			} );
		} );
		var calls = [];
		Object.keys( obj( a, 'llm_calls' ) ).forEach( function ( k ) {
			calls.push( { label: fa( k ) + ( k === '9' ? '+' : '' ) + ' فراخوانی', value: a.llm_calls[ k ], color: COLORS.cyan } );
		} );
		var html = kpis( [
			kpi( 'پیام‌های ذخیره‌شده', num( msgCount ), c.chars && msgCount ? 'میانگین ' + num( Math.round( c.chars / msgCount ) ) + ' نویسه' : '' ),
			kpi( 'گفتگوی جدید', num( c.conversations_created || 0 ) ),
			kpi( 'درخواست پاسخ', num( c.answers_requested || 0 ) ),
			kpi( 'پاسخ با استفاده از ابزار', num( a.with_tools || 0 ), a.total ? pct( ( a.with_tools || 0 ) / a.total ) + ' از پاسخ‌ها' : '' ),
			kpi( 'خلاصه‌سازی‌های زمان‌بندی‌شده', num( a.summaries_scheduled || 0 ) ),
			g( c, 'deletes.message' ) || g( c, 'deletes.conversation' ) ? kpi( 'حذف‌ها', num( g( c, 'deletes.message' ) + g( c, 'deletes.conversation' ) ), num( g( c, 'deletes.conversation' ) ) + ' گفتگو · ' + num( g( c, 'deletes.messages' ) ) + ' پیام' ) : '',
		] ) + cols( [
			sub( 'پیام‌ها به تفکیک فرستنده', hbars( items( c.messages, 'role' ), true ) ),
			sub( 'بازدیدکنندگان', hbars( items( c.visitors, 'visitor' ), true ) ),
			sub( 'پیوست‌ها', hbars( attach, true ) ),
			sub( 'وضعیت پاسخ‌ها', hbars( items( a.status, 'answer', okBad ), true ) ),
			sub( 'نوع پرسش', hbars( items( a.question_type, 'qtype' ), true ) ),
			sub( 'حالت پاسخ‌گویی', hbars( items( a.mode, 'mode' ), true ) ),
			sub( 'تعداد فراخوانی مدل در هر پاسخ', hbars( calls, true ) ),
			sub( 'علت شکست پاسخ‌ها', hbars( items( a.error_types, null, function () {
				return COLORS.red;
			} ), true ) ),
		] ) + sub( 'زمان‌ها و اندازه‌ها', latTable( [
			[ 'زمان کل تولید پاسخ', a.lat, 'ms' ],
			[ 'انتظار در صف (پاسخ غیرهمزمان)', g( s, 'answer_jobs.queue_wait', null ), 'ms' ],
			[ 'کل زمان کار پاسخ غیرهمزمان', g( s, 'answer_jobs.total', null ), 'ms' ],
			[ 'طول پاسخ (نویسه)', a.answer_chars, 'num' ],
			[ 'توکن تاریخچهٔ گفتگو', a.history_tokens, 'num' ],
			[ 'مدت خلاصه‌سازی', g( s, 'summary_jobs.lat', null ), 'ms' ],
		] ) ) + cols( [
			sub( 'کارهای پاسخ غیرهمزمان', hbars( items( obj( s, 'answer_jobs.phase' ), 'phase', okBad ), true ) ),
			sub( 'کارهای خلاصه‌سازی', hbars( items( obj( s, 'summary_jobs.phase' ), 'phase', okBad ), true ) ),
		] );

		var models = Object.keys( obj( a, 'by_model' ) ).map( function ( k ) {
			var m = a.by_model[ k ];
			return [ ltr( k ), num( m.total ), num( m.failed || 0 ), ms( g( m, 'lat.avg', null ) ), ms( g( m, 'lat.p95', null ) ), compact( m.total_tokens || 0 ) ];
		} );
		html += sub( 'پاسخ‌ها به تفکیک مدل', table( [ 'مدل', 'پاسخ', 'ناموفق', 'میانگین زمان', 'p95 زمان', 'توکن' ], models, false ) );
		return section( 'chat', 'گفتگو و پاسخ‌ها', html );
	}

	function renderAi( s, data ) {
		var llm = obj( s, 'llm' );
		var rows = Object.keys( obj( llm, 'by_model' ) ).map( function ( k ) {
			var m = llm.by_model[ k ];
			return [ ltr( k ), num( m.calls ), num( m.errors || 0 ), compact( m.prompt_tokens ), compact( m.completion_tokens ), compact( m.cached_tokens ), compact( m.reasoning_tokens ), num( m.tool_calls || 0 ), ms( g( m, 'lat.avg', null ) ), ms( g( m, 'lat.p95', null ) ) ];
		} );
		var purposes = {};
		Object.keys( obj( llm, 'by_model' ) ).forEach( function ( k ) {
			var p = obj( llm.by_model[ k ], 'by_purpose' );
			Object.keys( p ).forEach( function ( x ) {
				purposes[ x ] = ( purposes[ x ] || 0 ) + p[ x ];
			} );
		} );
		var emb = obj( s, 'embedding' );
		var embModels = Object.keys( obj( emb, 'by_model' ) ).map( function ( k ) {
			return [ ltr( k ), num( emb.by_model[ k ].requests ), compact( emb.by_model[ k ].tokens ) ];
		} );
		var embOps = Object.keys( obj( emb, 'by_op' ) ).map( function ( k ) {
			var o = emb.by_op[ k ];
			return [ esc( tr( 'op', k ) ), num( o.requests ), compact( o.tokens ), ms( g( o, 'lat.avg', null ) ), ms( g( o, 'lat.p95', null ) ) ];
		} );
		var stt = obj( s, 'stt' );
		var costRows = [];
		if ( s.cost && s.cost.by_model ) {
			Object.keys( s.cost.by_model ).forEach( function ( k ) {
				var c = s.cost.by_model[ k ];
				costRows.push( [ ltr( k ), num( c.input ), num( c.output ), num( c.total ) ] );
			} );
		}

		var html = kpis( [
			kpi( 'فراخوانی مدل چت', num( llm.calls || 0 ), num( llm.errors || 0 ) + ' خطا' + ( s.rates && has( s.rates.llm_error ) ? ' (' + pct( s.rates.llm_error ) + ')' : '' ), ( s.rates && s.rates.llm_error >= 0.1 ) ? 'bad' : '' ),
			kpi( 'توکن ورودی', compact( llm.prompt_tokens || 0 ), llm.prompt_tokens ? pct( ( llm.cached_tokens || 0 ) / llm.prompt_tokens ) + ' از آن کش‌شده' : '' ),
			kpi( 'توکن خروجی', compact( llm.completion_tokens || 0 ) ),
			kpi( 'مجموع توکن', compact( llm.total_tokens || 0 ), llm.calls ? 'میانگین ' + num( Math.round( ( llm.total_tokens || 0 ) / llm.calls ) ) + ' در هر فراخوانی' : '' ),
			g( s, 'answers.total' ) ? kpi( 'توکن به‌ازای هر پاسخ', compact( Math.round( g( s, 'answers.total_tokens' ) / g( s, 'answers.total' ) ) ), 'میانگین مجموع توکن' ) : '',
			kpi( 'تأخیر فراخوانی مدل', ms( g( llm, 'lat.p50', null ) ), 'p95: ' + ms( g( llm, 'lat.p95', null ) ) ),
			kpi( 'Embedding', compact( emb.tokens || 0 ), num( emb.requests || 0 ) + ' درخواست · ' + num( emb.errors || 0 ) + ' خطا', emb.errors ? 'warn' : '' ),
			stt.calls ? kpi( 'تبدیل گفتار به متن', num( stt.calls ), num( stt.chars || 0 ) + ' نویسه · ' + bytes( stt.bytes || 0 ) ) : '',
			s.cost && s.cost.total ? kpi( 'هزینهٔ برآوردی', num( s.cost.total ), 'بر پایهٔ قیمت‌های تنظیم‌شده' ) : '',
		] ) + sub( 'مدل‌ها', table( [ 'مدل', 'فراخوانی', 'خطا', 'توکن ورودی', 'توکن خروجی', 'کش‌شده', 'استدلال', 'ابزار', 'میانگین زمان', 'p95 زمان' ], rows, false ) ) + cols( [
			sub( 'هدف فراخوانی‌ها', hbars( items( purposes, 'purpose' ), true ) ),
			sub( 'پایان پاسخ مدل (finish reason)', hbars( items( llm.finish, 'finish' ), true ) ),
			sub( 'نوع خطاهای مدل', hbars( items( llm.error_types, null, function () {
				return COLORS.red;
			} ), true ) ),
			stt.calls ? sub( 'وضعیت تبدیل گفتار', hbars( items( stt.status, 'outcome', okBad ), true ) ) : '',
		] ) + sub( 'Embedding به تفکیک مدل', table( [ 'مدل', 'درخواست', 'توکن' ], embModels, false ) ) + sub( 'Embedding به تفکیک نوع', table( [ 'نوع', 'درخواست', 'توکن', 'میانگین زمان', 'p95 زمان' ], embOps, false ) ) +
			sub( 'تأخیرها', latTable( [ [ 'فراخوانی مدل چت', llm.lat, 'ms' ], [ 'Embedding', emb.lat, 'ms' ], [ 'تبدیل گفتار به متن', stt.lat, 'ms' ] ] ) ) +
			sub( 'برآورد هزینه (واحد: همان واحد قیمت‌های تنظیم‌شده)', costRows.length ? table( [ 'مدل', 'ورودی', 'خروجی', 'جمع' ], costRows ) : '' );
		return section( 'ai', 'هوش مصنوعی، توکن و هزینه', html, costRows.length ? '' : 'برای نمایش هزینه، فیلتر paia_monitor_prices را تنظیم کنید' );
	}

	function renderRag( s ) {
		var r = obj( s, 'rag' );
		if ( ! r.queries ) {
			return '';
		}
		var buckets = Object.keys( obj( r, 'top_score_bucket' ) ).sort().map( function ( k ) {
			var lo = parseFloat( k );
			var good = lo >= 0.5;
			return { label: fa( lo.toFixed( 1 ) ) + ' تا ' + fa( ( lo + 0.1 ).toFixed( 1 ) ), value: r.top_score_bucket[ k ], color: good ? COLORS.green : lo >= 0.3 ? COLORS.amber : COLORS.red };
		} );
		var emptyRate = ratio( r.empty || 0, r.queries );
		var html = kpis( [
			kpi( 'جست‌وجوها', num( r.queries ) ),
			kpi( 'بدون نتیجه', num( r.empty || 0 ), pct( emptyRate ) + ' از جست‌وجوها', emptyRate > 0.5 ? 'warn' : '' ),
			kpi( 'میانگین بهترین امتیاز', has( r.top_score_avg ) ? fa( r.top_score_avg.toFixed( 2 ) ) : '—', 'هرچه بالاتر، تطابق بهتر' ),
			kpi( 'رکورد ناهماهنگ (Qdrant/DB)', num( r.out_of_sync || 0 ), 'چانکی که در پایگاه برداری هست ولی در DB نیست', r.out_of_sync ? 'warn' : '' ),
			kpi( 'زمان جست‌وجو (میانه)', ms( g( r, 'lat.p50', null ) ), 'p95: ' + ms( g( r, 'lat.p95', null ) ) ),
		] ) + sub( 'توزیع بهترین امتیاز هر جست‌وجو (برای تنظیم RAG_MIN_SCORE)', hbars( buckets, true ) || note( 'امتیازی ثبت نشده است.' ) );
		return section( 'rag', 'جست‌وجوی دانش (RAG)', html );
	}

	function renderTools( s ) {
		var t = obj( s, 'tools' );
		var site = obj( s, 'site_api' );
		var kinds = Object.keys( obj( t, 'by_kind' ) ).map( function ( k ) {
			var x = t.by_kind[ k ];
			return [ esc( tr( 'kind', k ) ), num( x.calls ), num( x.errors || 0 ), pct( ratio( x.errors || 0, x.calls ) || 0 ), ms( g( x, 'lat.avg', null ) ), ms( g( x, 'lat.p95', null ) ) ];
		} );
		var names = Object.keys( obj( t, 'by_tool' ) ).map( function ( k ) {
			return { key: k, calls: t.by_tool[ k ].calls, errors: t.by_tool[ k ].errors || 0 };
		} ).sort( function ( a, b ) {
			return b.calls - a.calls;
		} ).slice( 0, 20 ).map( function ( x ) {
			return [ ltr( x.key ), num( x.calls ), num( x.errors ), pct( ratio( x.errors, x.calls ) || 0 ) ];
		} );
		var sites = Object.keys( obj( site, 'by_kind' ) ).map( function ( k ) {
			var x = site.by_kind[ k ];
			return [ esc( tr( 'kind', k ) ), num( x.calls ), ms( g( x, 'lat.avg', null ) ), ms( g( x, 'lat.p95', null ) ) ];
		} );
		var outcomes = {};
		Object.keys( obj( site, 'by_kind' ) ).forEach( function ( k ) {
			var o = obj( site.by_kind[ k ], 'outcomes' );
			Object.keys( o ).forEach( function ( x ) {
				outcomes[ x ] = ( outcomes[ x ] || 0 ) + o[ x ];
			} );
		} );
		if ( ! t.calls && ! sites.length ) {
			return '';
		}
		var html = kpis( [
			kpi( 'اجرای ابزار', num( t.calls || 0 ), num( t.errors || 0 ) + ' خطا', t.errors ? 'warn' : '' ),
			kpi( 'زمان اجرای ابزار (میانه)', ms( g( t, 'lat.p50', null ) ), 'p95: ' + ms( g( t, 'lat.p95', null ) ) ),
			kpi( 'تماس با API سایت', num( Object.keys( outcomes ).reduce( function ( a, k ) {
				return a + outcomes[ k ];
			}, 0 ) ) ),
		] ) + sub( 'ابزارها به تفکیک نوع', table( [ 'نوع', 'اجرا', 'خطا', 'نرخ خطا', 'میانگین زمان', 'p95 زمان' ], kinds, false ) ) +
			sub( 'پرکاربردترین ابزارها', table( [ 'ابزار', 'اجرا', 'خطا', 'نرخ خطا' ], names, false ) ) +
			cols( [
				sub( 'نتیجهٔ تماس با API سایت', hbars( items( outcomes, 'outcome', okBad ), true ) ),
				sub( 'تماس با API سایت به تفکیک نوع', table( [ 'نوع', 'تماس', 'میانگین زمان', 'p95 زمان' ], sites, false ) ),
			] );
		return section( 'tools', 'ابزارهای زنده و API سایت', html );
	}

	function renderIngest( s ) {
		var i = obj( s, 'ingest' );
		if ( ! i.requests && ! g( s, 'ingest.phase.done' ) && ! g( s, 'ingest.phase.failed' ) ) {
			return '';
		}
		var kinds = Object.keys( obj( i, 'by_kind' ) ).map( function ( k ) {
			var x = i.by_kind[ k ];
			return [ ltr( k ), num( x.done || 0 ), num( x.failed || 0 ), num( x.retry || 0 ), ms( g( x, 'lat.avg', null ) ), ms( g( x, 'lat.p95', null ) ) ];
		} );
		var done = g( s, 'ingest.phase.done' );
		var html = kpis( [
			kpi( 'درخواست‌های دریافتی', num( i.requests || 0 ), num( i.enqueued_jobs || 0 ) + ' کار وارد صف شد' ),
			kpi( 'کارهای انجام‌شده', num( done ), num( g( s, 'ingest.phase.retry' ) ) + ' بار تلاش دوباره' ),
			kpi( 'کارهای ناموفق', num( g( s, 'ingest.phase.failed' ) ), s.rates && has( s.rates.ingest_failed ) ? pct( s.rates.ingest_failed ) + ' از کارها' : '', g( s, 'ingest.phase.failed' ) ? 'warn' : '' ),
			kpi( 'کار جایگزین‌شده', num( i.superseded || 0 ), 'کارهای قدیمی که نسخهٔ تازه‌تر جایگزینشان شد' ),
			kpi( 'چانک‌ها', num( i.chunks || 0 ), num( i.embedded_chunks || 0 ) + ' embedding جدید · ' + num( i.reused_chunks || 0 ) + ' استفادهٔ مجدد' ),
			kpi( 'توکن پردازش‌شده', compact( i.tokens || 0 ), compact( i.embedding_tokens || 0 ) + ' توکن embedding' ),
			kpi( 'مدت هر کار (میانه)', ms( g( i, 'lat.p50', null ) ), 'p95: ' + ms( g( i, 'lat.p95', null ) ) ),
			kpi( 'انتظار در صف (میانه)', ms( g( i, 'queue_wait.p50', null ) ), 'p95: ' + ms( g( i, 'queue_wait.p95', null ) ) ),
		] ) + cols( [
			sub( 'نتیجهٔ کارهای انجام‌شده', hbars( items( i.status, 'ingestStatus', okBad ), true ) ),
			sub( 'ورودی به صف به تفکیک نوع', hbars( items( i.enqueued_by_kind ), true ) ),
			sub( 'کد خطاهای کارهای ناموفق', hbars( items( i.error_codes, null, function () {
				return COLORS.red;
			} ), true ) ),
		] ) + sub( 'به تفکیک نوع رکورد', table( [ 'نوع', 'انجام شد', 'ناموفق', 'تلاش دوباره', 'میانگین زمان', 'p95 زمان' ], kinds, false ) );
		return section( 'ingest', 'پایگاه دانش و آموزش (ingestion)', html );
	}

	function renderCallbacks( s ) {
		var c = obj( s, 'callbacks' );
		if ( ! c.sends && ! g( s, 'callbacks.pings.ok' ) && ! g( s, 'callbacks.pings.failed' ) && ! Object.keys( obj( c, 'site_state_changes' ) ).length ) {
			return '';
		}
		var ok = g( s, 'callbacks.by_outcome.ok' );
		var sites = Object.keys( obj( c, 'by_site' ) ).map( function ( k ) {
			var x = c.by_site[ k ];
			return { key: k, sends: x.sends, failed: x.failed || 0, lat: x.lat };
		} ).sort( function ( a, b ) {
			return b.sends - a.sends;
		} ).slice( 0, 20 ).map( function ( x ) {
			return [ ltr( x.key ), num( x.sends ), num( x.failed ), pct( ratio( x.failed, x.sends ) || 0 ), ms( g( x, 'lat.avg', null ) ), ms( g( x, 'lat.p95', null ) ) ];
		} );
		var html = kpis( [
			kpi( 'ارسال به سایت‌ها', num( c.sends || 0 ), c.sends ? pct( ok / c.sends ) + ' موفق' : '', c.sends && ok / c.sends < 0.9 ? 'warn' : '' ),
			kpi( 'نتیجهٔ تحویل‌شده', num( c.results_delivered || 0 ), num( c.answers_delivered || 0 ) + ' پاسخ تحویل‌شده' ),
			kpi( 'ارسال رهاشده (dead)', num( c.dead || 0 ), 'بعد از چند بار تلاش ناموفق', c.dead ? 'bad' : '' ),
			kpi( 'تست اتصال (ping)', num( g( s, 'callbacks.pings.ok' ) + g( s, 'callbacks.pings.failed' ) ), num( g( s, 'callbacks.pings.failed' ) ) + ' ناموفق', g( s, 'callbacks.pings.failed' ) ? 'warn' : '' ),
			kpi( 'زمان ارسال (میانه)', ms( g( c, 'lat.p50', null ) ), 'p95: ' + ms( g( c, 'lat.p95', null ) ) ),
		] ) + cols( [
			sub( 'نتیجهٔ ارسال‌ها', hbars( items( c.by_outcome, 'outcome', okBad ), true ) ),
			sub( 'تغییر وضعیت سایت‌ها', hbars( items( c.site_state_changes, 'siteState', function ( k ) {
				return k === 'recovered' ? COLORS.green : COLORS.red;
			} ), true ) ),
		] ) + sub( 'به تفکیک سایت', table( [ 'سایت', 'ارسال', 'ناموفق', 'نرخ شکست', 'میانگین زمان', 'p95 زمان' ], sites, false ) );
		return section( 'callbacks', 'ارسال نتیجه به سایت‌ها (callback)', html );
	}

	function renderHttp( s ) {
		var h = obj( s, 'http' );
		if ( ! h.requests ) {
			return '';
		}
		var routes = Object.keys( obj( h, 'routes' ) ).map( function ( k ) {
			return Object.assign( { key: k }, h.routes[ k ] );
		} ).sort( function ( a, b ) {
			return b.n - a.n;
		} ).slice( 0, 25 ).map( function ( r ) {
			return [ ltr( r.key ), num( r.n ), num( r.err4xx || 0 ), num( r.err5xx || 0 ), ms( g( r, 'lat.avg', null ) ), ms( g( r, 'lat.p95', null ) ), ms( g( r, 'lat.max', null ) ) ];
		} );
		var sec = obj( s, 'security' );
		var ips = Object.keys( obj( sec, 'by_ip' ) ).sort( function ( a, b ) {
			return sec.by_ip[ b ] - sec.by_ip[ a ];
		} ).map( function ( k ) {
			return [ ltr( k ), num( sec.by_ip[ k ] ) ];
		} );
		var html = kpis( [
			kpi( 'درخواست‌ها', num( h.requests ) ),
			kpi( 'حجم دریافتی', bytes( h.bytes_in || 0 ), 'بدنهٔ درخواست‌ها' ),
			kpi( 'حجم ارسالی', bytes( h.bytes_out || 0 ), 'بدنهٔ پاسخ‌ها' ),
			kpi( 'تأخیر (میانه)', ms( g( h, 'lat.p50', null ) ), 'p99: ' + ms( g( h, 'lat.p99', null ) ) ),
			kpi( 'کندترین درخواست', ms( g( h, 'lat.max', null ) ) ),
			kpi( 'رد شده (امضا / IP)', num( sec.auth_rejected || 0 ), '', sec.auth_rejected ? 'warn' : '' ),
		] ) + cols( [
			sub( 'کد وضعیت پاسخ‌ها', hbars( items( h.status, 'status', okBad ), true ) ),
			sub( 'دلیل ردشدن‌ها', hbars( items( sec.by_reason, null, function () {
				return COLORS.amber;
			} ), true ) ),
		] ) + sub( 'پرتکرارترین مسیرها', table( [ 'مسیر', 'تعداد', '4xx', '5xx', 'میانگین زمان', 'p95 زمان', 'بیشینه' ], routes, false ) ) +
			( ips.length ? sub( 'IPهای ردشده', table( [ 'IP', 'تعداد' ], ips.slice( 0, 10 ), false ) ) : '' );
		return section( 'http', 'درخواست‌های API و امنیت', html );
	}

	function renderProblems( s ) {
		var p = obj( s, 'problems' );
		if ( ! p.error && ! p.warning ) {
			return '';
		}
		var html = kpis( [
			kpi( 'خطا', num( p.error || 0 ), '', p.error ? 'bad' : '' ),
			kpi( 'هشدار', num( p.warning || 0 ), '', p.warning ? 'warn' : '' ),
		] ) + cols( [
			sub( 'بیشترین منبع هشدار/خطا (logger)', hbars( items( p.by_logger, null, function () {
				return COLORS.amber;
			}, 10 ), true ) ),
			sub( 'نوع exception', hbars( items( p.by_exception, null, function () {
				return COLORS.red;
			}, 10 ), true ) ),
		] ) + note( 'متن کامل لاگ‌ها در تب «لاگ سرور» صفحهٔ چت‌بات هوشمند قابل مشاهده است.' );
		return section( 'problems', 'خطاها و هشدارهای سرور', html );
	}

	function renderResources( s ) {
		var sy = obj( s, 'system' );
		var q = obj( s, 'queues' );
		var st = obj( s, 'storage' );
		var svcRows = Object.keys( obj( sy, 'by_svc' ) ).map( function ( k ) {
			var x = sy.by_svc[ k ];
			return [ esc( tr( 'svc', k ) ), mb( x.rss_mb_avg ), mb( x.rss_mb_max ), has( x.cpu_pct_avg ) ? NF1.format( x.cpu_pct_avg ) + '٪' : '—', has( x.cpu_pct_max ) ? NF1.format( x.cpu_pct_max ) + '٪' : '—', num( x.threads_max ), has( x.fds_max ) && x.fds_max ? num( x.fds_max ) : '—', ms( x.loop_lag_ms_max ) ];
		} );
		var queueRows = [];
		[ [ 'ingest', 'آموزش (ingest)' ], [ 'answers', 'پاسخ غیرهمزمان' ], [ 'summaries', 'خلاصه‌سازی' ] ].forEach( function ( p ) {
			if ( ! ( ( p[ 0 ] + '_pending_last' ) in q ) ) {
				return;
			}
			queueRows.push( [ p[ 1 ], num( q[ p[ 0 ] + '_pending_last' ] ), num( q[ p[ 0 ] + '_processing_last' ] || 0 ), num( q[ p[ 0 ] + '_failed_last' ] || 0 ), secs( q[ p[ 0 ] + '_oldest_pending_s_last' ] || 0 ), num( q[ p[ 0 ] + '_pending_max' ] || 0 ), secs( q[ p[ 0 ] + '_oldest_pending_s_max' ] || 0 ) ] );
		} );
		var free = st.disk_free_mb_last;
		var total = st.disk_total_mb_last;
		var diskTone = has( free ) ? ( free < 1024 || ( total && free / total < 0.05 ) ? 'bad' : free < 5120 || ( total && free / total < 0.1 ) ? 'warn' : 'ok' ) : '';
		var hasStorage = has( st.disk_free_mb_last ) || has( st.data_mb_last );
		var html = ( svcRows.length ? sub( 'پروسه‌ها (RAM / CPU)', table( [ 'پروسه', 'RAM میانگین', 'RAM بیشینه', 'CPU میانگین', 'CPU بیشینه', 'thread بیشینه', 'fd بیشینه', 'تأخیر event-loop' ], svcRows, false ) ) : '' ) +
			( queueRows.length ? sub( 'صف‌ها (اکنون = آخرین نمونه در بازه)', table( [ 'صف', 'در انتظار (اکنون)', 'در حال پردازش', 'ناموفق', 'قدیمی‌ترین در انتظار', 'بیشینهٔ در انتظار', 'بیشینهٔ سن صف' ], queueRows, false ) +
				kpis( [
					has( q.deliveries_pending_last ) ? kpi( 'callback در انتظار تحویل', num( q.deliveries_pending_last ), num( q.deliveries_pending_answers_last || 0 ) + ' پاسخ · قدیمی‌ترین: ' + secs( q.deliveries_oldest_pending_s_last || 0 ) ) : '',
					has( q.deliveries_dead_last ) ? kpi( 'callback رهاشده', num( q.deliveries_dead_last ), '', q.deliveries_dead_last ? 'bad' : '' ) : '',
					has( q.sites_total_last ) ? kpi( 'سایت‌های متصل', num( q.sites_total_last ), num( q.sites_unhealthy_last || 0 ) + ' ناسالم', q.sites_unhealthy_last ? 'bad' : '' ) : '',
				] ) ) : '' ) +
			( hasStorage ? sub( 'ذخیره‌سازی و حجم داده‌ها (آخرین نمونه)', kpis( [
				has( free ) ? kpi( 'فضای آزاد دیسک', mb( free ), total ? 'از ' + mb( total ) : '', diskTone ) : '',
				has( st.data_mb_last ) ? kpi( 'پوشهٔ داده', mb( st.data_mb_last ) ) : '',
				has( st.db_mb_last ) ? kpi( 'پایگاه داده (SQLite)', mb( st.db_mb_last ) ) : '',
				has( st.qdrant_mb_last ) ? kpi( 'پایگاه برداری (Qdrant)', mb( st.qdrant_mb_last ) ) : '',
				has( st.uploads_mb_last ) ? kpi( 'آپلودهای چت', mb( st.uploads_mb_last ), num( st.uploads_files_last || 0 ) + ' فایل' ) : '',
				has( st.knowledge_files_mb_last ) ? kpi( 'فایل‌های دانش', mb( st.knowledge_files_mb_last ), num( st.knowledge_files_last || 0 ) + ' فایل' ) : '',
				has( st.logs_mb_last ) ? kpi( 'لاگ‌ها', mb( st.logs_mb_last ) ) : '',
				has( st.monitor_mb_last ) ? kpi( 'فایل‌های مانیتورینگ', mb( st.monitor_mb_last ), num( st.monitor_files_last || 0 ) + ' فایل' ) : '',
				has( st.total_conversations_last ) ? kpi( 'کل گفتگوها', num( st.total_conversations_last ) ) : '',
				has( st.total_messages_last ) ? kpi( 'کل پیام‌ها', num( st.total_messages_last ) ) : '',
				has( st.total_records_last ) ? kpi( 'کل رکوردهای دانش', num( st.total_records_last ) ) : '',
				has( st.total_chunks_last ) ? kpi( 'کل چانک‌ها', num( st.total_chunks_last ) ) : '',
			] ) ) : '' );
		return section( 'resources', 'منابع سرور، صف‌ها و ذخیره‌سازی', html );
	}

	function renderMonitorSelf( data ) {
		var s = data.summary;
		var mon = data.monitor || {};
		var events = Object.keys( obj( s, 'events' ) ).map( function ( k ) {
			return { key: k, value: s.events[ k ] };
		} ).sort( function ( a, b ) {
			return b.value - a.value;
		} );
		var starts = obj( s, 'app.starts' );
		var stops = obj( s, 'app.stops' );
		var totalStarts = Object.keys( starts ).reduce( function ( a, k ) {
			return a + starts[ k ];
		}, 0 );
		var html = kpis( [
			kpi( 'رویدادهای ثبت‌شده', compact( s.records || 0 ) ),
			kpi( 'شروع پروسه‌ها', num( totalStarts ), Object.keys( starts ).map( function ( k ) {
				return tr( 'svc', k ) + ': ' + fa( starts[ k ] );
			} ).join( ' · ' ) || 'در این بازه شروع ثبت نشده', totalStarts > 4 ? 'warn' : '' ),
			kpi( 'توقف پروسه‌ها', num( Object.keys( stops ).reduce( function ( a, k ) {
				return a + stops[ k ];
			}, 0 ) ) ),
			kpi( 'رویداد دورریخته‌شده', num( g( s, 'monitor.dropped_events' ) ), 'پر بودن صف حافظه', g( s, 'monitor.dropped_events' ) ? 'warn' : '' ),
			kpi( 'رسیدن به سقف روزانه', num( g( s, 'monitor.cap_reached' ) ), 'LOG_MAX_MB × LOG_BACKUP_COUNT', g( s, 'monitor.cap_reached' ) ? 'warn' : '' ),
		] ) + sub( 'رویدادها به تفکیک نوع', hbars( events.slice( 0, 24 ).map( function ( e ) {
			return { label: esc( tr( 'events', e.key ) ) + ' <small class="paia-mon__ltr">' + esc( e.key ) + '</small>', value: e.value, color: COLORS.slate };
		} ), true ) ) +
			note( 'تنظیمات سرور: نگهداری خط‌های خام ' + fa( mon.retention_days || '—' ) + ' روز · سقف کل فضا ' + ( mon.max_total_mb ? mb( mon.max_total_mb ) : '—' ) + ' · مرز روز: UTC' + esc( mon.utc_offset || '' ) + ' · نمونه‌برداری منابع هر ' + fa( mon.snapshot_seconds || 0 ) + ' ثانیه' );
		return section( 'monitor', 'وضعیت خود مانیتورینگ', html );
	}

	function render( data ) {
		var s = data.summary || {};
		charts = {};

		// نوار بالا
		els[ 'mon-label' ].textContent = prettyLabel( data );
		var nav = data.nav || {};
		[ [ 'mon-prev', nav.prev ], [ 'mon-next', nav.next ] ].forEach( function ( p ) {
			els[ p[ 0 ] ].setAttribute( 'data-off', p[ 1 ] ? '0' : '1' );
			els[ p[ 0 ] ].disabled = ! p[ 1 ];
		} );
		var cur = !! data.is_current;
		els[ 'mon-today' ].setAttribute( 'data-off', cur && ! state.date ? '1' : '0' );
		els[ 'mon-today' ].disabled = cur && ! state.date;

		var html;
		if ( ! s.records ) {
			html = renderHealth( data ).replace( /is-ok/, 'is-warn' ).replace( 'همه‌چیز عادی است', 'در این بازه رویدادی ثبت نشده است' ) +
				section( 'empty', 'بدون داده', note( 'برای این بازه هیچ رویدادی در فایل‌های مانیتورینگ سرور پیدا نشد. اگر سرور تازه راه‌اندازی شده یا مانیتورینگ خاموش بوده، این حالت عادی است؛ بازهٔ دیگری را انتخاب کنید.' ) );
		} else {
			html = [
				renderHealth( data ),
				renderOverview( s ),
				renderTrends( data ),
				renderChat( s ),
				renderAi( s, data ),
				renderRag( s ),
				renderTools( s ),
				renderIngest( s ),
				renderCallbacks( s ),
				renderHttp( s ),
				renderProblems( s ),
				renderResources( s ),
				renderMonitorSelf( data ),
			].join( '' );
		}

		els[ 'mon-body' ].innerHTML = html;
		els[ 'mon-body' ].hidden = false;

		[].slice.call( els[ 'mon-body' ].querySelectorAll( '[data-chart]' ) ).forEach( function ( box ) {
			var spec = charts[ box.getAttribute( 'data-chart' ) ];
			if ( spec ) {
				drawChart( box, spec );
			}
		} );

		// فهرست پرش به بخش‌ها (همه در یک صفحه‌اند؛ تب نیست)
		var jump = '';
		[].slice.call( els[ 'mon-body' ].querySelectorAll( '.paia-card[id^="mon-"]' ) ).forEach( function ( sec ) {
			jump += '<a href="#' + sec.id + '" data-jump="' + sec.id + '">' + esc( sec.getAttribute( 'data-title' ) || '' ) + '</a>';
		} );
		els[ 'mon-jump' ].innerHTML = jump;
		els[ 'mon-jump' ].hidden = ! jump;
	}

	/* ------------------------------------------------------------------ */
	/* رویدادها                                                            */
	/* ------------------------------------------------------------------ */

	periodBtns.forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			var p = b.getAttribute( 'data-period' );
			if ( p === state.period && ! state.date ) {
				return;
			}
			state.period = p;
			state.date = '';
			load();
		} );
	} );

	calBtns.forEach( function ( b ) {
		b.addEventListener( 'click', function () {
			var c = b.getAttribute( 'data-calendar' );
			if ( c === state.calendar ) {
				return;
			}
			state.calendar = c;
			state.date = ''; // تاریخ انتخاب‌شده مال تقویم قبلی بود
			try {
				window.localStorage.setItem( 'paia_mon_calendar', c );
			} catch ( e ) {} // eslint-disable-line no-empty
			load();
		} );
	} );

	els[ 'mon-prev' ].addEventListener( 'click', function () {
		if ( state.data && state.data.nav && state.data.nav.prev ) {
			state.date = state.data.nav.prev;
			load();
		}
	} );
	els[ 'mon-next' ].addEventListener( 'click', function () {
		if ( state.data && state.data.nav && state.data.nav.next ) {
			state.date = state.data.nav.next;
			load();
		}
	} );
	els[ 'mon-today' ].addEventListener( 'click', function () {
		state.date = '';
		load();
	} );
	els[ 'mon-refresh' ].addEventListener( 'click', load );
	els[ 'mon-error' ].addEventListener( 'click', function ( e ) {
		if ( e.target && e.target.getAttribute && e.target.getAttribute( 'data-role' ) === 'mon-retry' ) {
			load();
		}
	} );
	els[ 'mon-jump' ].addEventListener( 'click', function ( e ) {
		var a = e.target && e.target.closest ? e.target.closest( '[data-jump]' ) : null;
		if ( ! a ) {
			return;
		}
		e.preventDefault(); // hash صفحه برای بازه استفاده می‌شود
		var target = document.getElementById( a.getAttribute( 'data-jump' ) );
		if ( target ) {
			target.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		}
	} );

	/* ------------------------------------------------------------------ */

	( function start() {
		var h = readHash();
		state.calendar = h.calendar === 'gregorian' || h.calendar === 'jalali' ? h.calendar : savedCalendar();
		state.period = h.period === 'month' || h.period === 'year' ? h.period : 'day';
		state.date = /^\d{4}(-\d{1,2}){0,2}$/.test( h.date || '' ) ? h.date : '';
		if ( cfg.configured === false ) {
			els[ 'mon-loading' ].hidden = true;
			els[ 'mon-error' ].innerHTML = '<strong>اتصال به سرور هوش مصنوعی تنظیم نشده است.</strong><br>آدرس و کلید اتصال را در «تنظیمات» وارد کنید.';
			els[ 'mon-error' ].hidden = false;
			paintControls();
			return;
		}
		load();
	}() );
}() );
