/**
 * دستیار هوش مصنوعی وردپرس فارسی — اسکریپت بخش مدیریت
 * بدون وابستگی؛ داده و متن‌ها از window.PAIA_ADMIN (تولیدشده در PHP) خوانده می‌شود.
 */
( function () {
	'use strict';

	var config = window.PAIA_ADMIN || {};
	var i18n = config.i18n || {};

	/* ------------------------------------------------------------------ */
	/* تب‌ها                                                               */
	/* ------------------------------------------------------------------ */

	function initTabs( root ) {
		var tabs = [].slice.call( root.querySelectorAll( '[role="tab"]' ) );
		var panels = [].slice.call( root.querySelectorAll( '[role="tabpanel"]' ) );

		if ( ! tabs.length ) {
			return;
		}

		function isLocked( tab ) {
			return tab.getAttribute( 'aria-disabled' ) === 'true';
		}

		function activate( id, focus ) {
			tabs.forEach( function ( tab ) {
				var on = tab.getAttribute( 'data-tab' ) === id && ! isLocked( tab );
				tab.classList.toggle( 'is-active', on );
				tab.setAttribute( 'aria-selected', on ? 'true' : 'false' );
				tab.tabIndex = on ? 0 : -1;
				if ( on && focus ) {
					tab.focus();
				}
			} );
			panels.forEach( function ( panel ) {
				panel.hidden = panel.getAttribute( 'data-panel' ) !== id;
			} );
		}

		function idFromHash() {
			var id = window.location.hash.replace( '#', '' );
			var exists = tabs.some( function ( tab ) {
				return tab.getAttribute( 'data-tab' ) === id && ! isLocked( tab );
			} );
			return exists ? id : tabs[ 0 ].getAttribute( 'data-tab' );
		}

		tabs.forEach( function ( tab, index ) {
			tab.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				if ( isLocked( tab ) ) {
					return;
				}
				var id = tab.getAttribute( 'data-tab' );
				activate( id, false );
				if ( window.history && window.history.replaceState ) {
					window.history.replaceState( null, '', '#' + id );
				}
			} );

			tab.addEventListener( 'keydown', function ( event ) {
				var isRtl = window.getComputedStyle( root ).direction === 'rtl';
				var next = isRtl ? 'ArrowLeft' : 'ArrowRight';
				var prev = isRtl ? 'ArrowRight' : 'ArrowLeft';
				var target = null;

				var open = tabs.filter( function ( t ) {
					return ! isLocked( t );
				} );
				var pos = open.indexOf( tab );

				if ( ! open.length || pos < 0 ) {
					return;
				}
				if ( event.key === next ) {
					target = open[ ( pos + 1 ) % open.length ];
				} else if ( event.key === prev ) {
					target = open[ ( pos - 1 + open.length ) % open.length ];
				} else if ( event.key === 'Home' ) {
					target = open[ 0 ];
				} else if ( event.key === 'End' ) {
					target = open[ open.length - 1 ];
				}

				if ( target ) {
					event.preventDefault();
					activate( target.getAttribute( 'data-tab' ), true );
				}
			} );
		} );

		window.addEventListener( 'hashchange', function () {
			activate( idFromHash(), false );
		} );

		activate( idFromHash(), false );
	}

	/* ------------------------------------------------------------------ */
	/* تب «تنظیمات»: منوی دسته‌بندی سمت راست (عمومی / مدیریت چت)           */
	/* ------------------------------------------------------------------ */

	function initSettingsNav() {
		var nav = document.querySelector( '.paia-setnav' );
		if ( ! nav ) {
			return;
		}
		var items = [].slice.call( nav.querySelectorAll( '[data-set]' ) );
		var panes = [].slice.call( document.querySelectorAll( '[data-setpane]' ) );
		var KEY = 'paia_settings_section';

		function activate( id, focus ) {
			items.forEach( function ( item ) {
				var on = item.getAttribute( 'data-set' ) === id;
				item.classList.toggle( 'is-active', on );
				item.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
				if ( on && focus ) {
					item.focus();
				}
			} );
			var noSave = false;
			panes.forEach( function ( pane ) {
				var on = pane.getAttribute( 'data-setpane' ) === id;
				pane.hidden = ! on;
				if ( on && pane.hasAttribute( 'data-nosave' ) ) {
					noSave = true;
				}
			} );
			// پنل‌هایی با ذخیرهٔ آنی (مثل «استخراج اطلاعات سایت») به دکمهٔ ذخیرهٔ فرم نیازی ندارند.
			var actions = document.querySelector( '.paia-setbody .paia-actions' );
			if ( actions ) {
				actions.style.display = noSave ? 'none' : '';
			}
			try {
				window.localStorage.setItem( KEY, id );
			} catch ( e ) { /* noop */ }
		}

		items.forEach( function ( item, index ) {
			item.addEventListener( 'click', function () {
				activate( item.getAttribute( 'data-set' ), false );
			} );
			item.addEventListener( 'keydown', function ( event ) {
				var target = null;
				if ( event.key === 'ArrowDown' ) {
					target = items[ ( index + 1 ) % items.length ];
				} else if ( event.key === 'ArrowUp' ) {
					target = items[ ( index - 1 + items.length ) % items.length ];
				}
				if ( target ) {
					event.preventDefault();
					activate( target.getAttribute( 'data-set' ), true );
				}
			} );
		} );

		// بعد از «ذخیرهٔ تنظیمات» صفحه دوباره بارگذاری می‌شود؛ بخشِ قبلی را باز نگه می‌داریم.
		var saved = null;
		try {
			saved = window.localStorage.getItem( KEY );
		} catch ( e ) { /* noop */ }
		var valid = items.some( function ( item ) {
			return item.getAttribute( 'data-set' ) === saved;
		} );
		activate( valid ? saved : items[ 0 ].getAttribute( 'data-set' ), false );
	}

	/* ------------------------------------------------------------------ */
	/* ابزارهای کمکی                                                       */
	/* ------------------------------------------------------------------ */

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			if ( key === 'text' ) {
				node.textContent = attrs[ key ];
			} else if ( attrs[ key ] !== null && attrs[ key ] !== undefined && attrs[ key ] !== false ) {
				node.setAttribute( key, attrs[ key ] === true ? '' : attrs[ key ] );
			}
		} );
		( children || [] ).forEach( function ( child ) {
			if ( child ) {
				node.appendChild( typeof child === 'string' ? document.createTextNode( child ) : child );
			}
		} );
		return node;
	}

	function clear( node ) {
		while ( node.firstChild ) {
			node.removeChild( node.firstChild );
		}
	}

	function sprintf( template ) {
		var args = [].slice.call( arguments, 1 );
		var next = 0;
		return String( template ).replace( /%(\d+\$)?[sd]/g, function ( match, position ) {
			return position ? args[ parseInt( position, 10 ) - 1 ] : args[ next++ ];
		} );
	}

	function formatNumber( n ) {
		try {
			return Number( n ).toLocaleString( document.documentElement.lang || undefined );
		} catch ( e ) {
			return String( n );
		}
	}

	function sourceLabel( id ) {
		return ( config.sources || {} )[ id ] || id;
	}

	function sleep( ms ) {
		return new Promise( function ( resolve ) {
			window.setTimeout( resolve, ms );
		} );
	}

	/** درخواست REST با دو بار تلاش مجدد فقط برای خطای شبکه. */
	async function request( path, method, body ) {
		var attempt = 0;
		while ( true ) {
			try {
				var response = await window.fetch( ( config.restUrl || '' ) + path, {
					method: method,
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce || '' },
					body: body ? JSON.stringify( body ) : undefined,
				} );
				var data = await response.json().catch( function () {
					return {};
				} );
				return { ok: response.ok, status: response.status, data: data };
			} catch ( e ) {
				if ( ++attempt > 2 ) {
					return { ok: false, status: 0, data: { message: i18n.networkError || '' } };
				}
				await sleep( 1200 );
			}
		}
	}


	/** GET با پارامتر؛ روی لینک ساده‌ی ?rest_route= هم درست کار می‌کند. */
	function getPath( path, params ) {
		var sep = ( config.restUrl || '' ).indexOf( '?' ) === -1 ? '?' : '&';
		var query = Object.keys( params || {} )
			.map( function ( key ) {
				return encodeURIComponent( key ) + '=' + encodeURIComponent( params[ key ] );
			} )
			.join( '&' );
		return path + ( query ? sep + query : '' );
	}

	function role( root, name ) {
		return root.querySelector( '[data-role="' + name + '"]' );
	}

	function newState() {
		return { site: null, siteRecord: '', trained: {}, sources: {} };
	}

	function newSourceState() {
		return { items: [], recordIds: [], taxonomies: [], taxonomiesRecord: '', warnings: [], total: 0, duration: 0, error: '', skipped: 0 };
	}

	/**
	 * state.trained[id]: true = پردازش‌شده (ai_status ۲، آیکن هوش مصنوعی)،
	 * 'processing' = در حال پردازش سمت سرور (ai_status ۱، آیکن loading)، نبودن/false = آموزش‌ندیده (تیک).
	 */
	function markTrained( state, ids, processing ) {
		( ids || [] ).forEach( function ( id ) {
			state.trained[ id ] = true;
		} );
		( processing || [] ).forEach( function ( id ) {
			if ( state.trained[ id ] !== true ) {
				state.trained[ id ] = 'processing';
			}
		} );
	}

	/* ------------------------------------------------------------------ */
	/* رکوردهای «در حال پردازش»: وقتی سرور نتیجه را اعلام کرد آیکن عوض شود */
	/* ------------------------------------------------------------------ */

	var STATUS_POLL_MS = 8000;
	var statusWatchers = {}; // شناسهٔ رکورد => callback( status, id )
	var statusTimer = 0;

	/**
	 * شناسه‌ها هر ۸ ثانیه با یک درخواست سبک (GET ai-status) پرسیده می‌شوند، فقط تا وقتی ai_status آن‌ها ۱ است.
	 * به محض اینکه ۲ (پردازش‌شده) یا ۰ شد، callback( status, id ) یک‌بار صدا زده و رکورد از پایش برداشته می‌شود.
	 * وقتی تب مرورگر پنهان است درخواستی نمی‌رود. برای یک شناسه فقط آخرین callback نگه داشته می‌شود.
	 */
	function watchStatus( ids, callback ) {
		( ids || [] ).forEach( function ( id ) {
			if ( id ) {
				statusWatchers[ id ] = callback;
			}
		} );
		scheduleStatusPoll();
	}

	function scheduleStatusPoll() {
		if ( statusTimer || ! Object.keys( statusWatchers ).length ) {
			return;
		}
		statusTimer = window.setTimeout( pollStatuses, STATUS_POLL_MS );
	}

	async function pollStatuses() {
		statusTimer = 0;
		var ids = Object.keys( statusWatchers ).slice( 0, 200 );
		if ( ! ids.length ) {
			return;
		}
		if ( document.hidden ) {
			scheduleStatusPoll();
			return;
		}
		var res = await request( getPath( 'ai-status', { ids: ids.join( ',' ) } ), 'GET' );
		if ( res.ok && res.data && res.data.statuses ) {
			ids.forEach( function ( id ) {
				var status = id in res.data.statuses ? parseInt( res.data.statuses[ id ], 10 ) : 0; // رکورد دیگر نیست: مثل آموزش‌ندیده
				if ( status !== 1 ) {
					var callback = statusWatchers[ id ];
					delete statusWatchers[ id ];
					if ( callback ) {
						callback( status, id );
					}
				}
			} );
		}
		scheduleStatusPoll();
	}

	document.addEventListener( 'visibilitychange', function () {
		if ( ! document.hidden && Object.keys( statusWatchers ).length ) {
			window.clearTimeout( statusTimer );
			statusTimer = 0;
			pollStatuses();
		}
	} );

	/** افزودن یک دستهٔ پاسخ (استخراج یا خوانش از جدول) به وضعیت یک منبع. */
	function mergeBatch( state, st, data ) {
		st.total = data.total || 0;
		st.duration += data.duration_ms || 0;
		st.skipped += data.skipped || 0;
		st.items = st.items.concat( data.items || [] );
		st.recordIds = st.recordIds.concat( data.record_ids || [] );
		st.warnings = st.warnings.concat( data.warnings || [] );
		if ( data.taxonomies ) {
			st.taxonomies = data.taxonomies;
			st.taxonomiesRecord = data.taxonomies_record_id || '';
		}
		markTrained( state, data.trained_ids, data.processing_ids );
	}

	/* ------------------------------------------------------------------ */
	/* خلاصهٔ اطلاعات ثبت‌شده (آمار + آخرین به‌روزرسانی + تیک خودکار)       */
	/* ------------------------------------------------------------------ */

	function initOverview( root, extractors, dialog ) {
		var box = role( root, 'overview' );
		if ( ! box ) {
			return;
		}

		var statsBox = role( box, 'overview-stats' );
		var lastBox = role( box, 'overview-last' );
		var detailsBtn = role( box, 'details-btn' );
		var newBtn = role( box, 'new-btn' );
		var newBlock = extractors.new;
		var data = config.overview || { sources: {}, total: 0, trained: 0 };
		var recentNew = {}; // منبع => تعداد موارد جدیدِ همین نشست

		function render() {
			clear( statsBox );

			var grid = el( 'div', { class: 'paia-summary' } );
			Object.keys( data.sources || {} ).forEach( function ( id ) {
				var s = data.sources[ id ];
				var chips = el( 'ul', { class: 'paia-chips' } );
				if ( s.trained > 0 ) {
					chips.appendChild( el( 'li', { class: 'paia-chip paia-chip--ai', text: sprintf( i18n.ovTrained, formatNumber( s.trained ) ) } ) );
				}
				if ( recentNew[ id ] > 0 ) {
					chips.appendChild( el( 'li', { class: 'paia-chip paia-chip--new', text: sprintf( i18n.ovAdded, formatNumber( recentNew[ id ] ) ) } ) );
				}
				grid.appendChild(
					el( 'div', { class: 'paia-stat' }, [
						el( 'h3', { class: 'paia-stat__name', text: s.label } ),
						el( 'div', { class: 'paia-stat__value', text: formatNumber( s.total ) } ),
						el( 'div', { class: 'paia-stat__hint', text: i18n.ovItems } ),
						chips.childNodes.length ? chips : null,
					] )
				);
			} );
			statsBox.appendChild( grid );
			statsBox.appendChild( el( 'p', { class: 'paia-note paia-overview__total', text: sprintf( i18n.ovTotal, formatNumber( data.total || 0 ), formatNumber( data.trained || 0 ) ) } ) );

			lastBox.textContent = data.last_label ? i18n.ovLast + ' ' + data.last_label : i18n.ovNever;

			var has = ( data.total || 0 ) > 0 || !! data.site;
			detailsBtn.disabled = ! has;
			newBtn.disabled = ! has;
		}

		function setData( next, added ) {
			data = next || data;
			config.overview = data;
			if ( added ) {
				Object.keys( added ).forEach( function ( id ) {
					recentNew[ id ] = ( recentNew[ id ] || 0 ) + added[ id ];
				} );
			}
			render();
		}

		async function refresh( added ) {
			if ( added ) {
				newBtn.setAttribute( 'aria-expanded', 'false' );
			}
			var res = await request( 'extraction/overview', 'GET' );
			if ( res.ok && res.data ) {
				setData( res.data, added );
			}
		}

		detailsBtn.addEventListener( 'click', function () {
			dialog.open();
		} );

		// فرم استخراج اطلاعات جدید: تا زدن این دکمه مخفی است.
		newBtn.addEventListener( 'click', function () {
			if ( ! newBlock || newBtn.disabled ) {
				return;
			}
			newBlock.show();
			newBtn.setAttribute( 'aria-expanded', 'true' );
		} );

		render();

		// استخراج اولیه / جدید که تمام شد، آمار بالا به‌روز شود.
		return { refresh: refresh };
	}

	/* ------------------------------------------------------------------ */
	/* دیالوگ «نمایش جزئیات»                                              */
	/* ------------------------------------------------------------------ */

	function initDialog( root, onChange ) {
		var dlg = role( root, 'dialog' );
		if ( ! dlg ) {
			return { open: function () {} };
		}

		var closeBtn = role( dlg, 'dialog-close' );
		var progress = role( dlg, 'progress' );
		var fill = role( dlg, 'progress-fill' );
		var text = role( dlg, 'progress-text' );
		var notice = role( dlg, 'notice' );
		var results = role( dlg, 'results' );
		var body = role( dlg, 'dialog-body' );
		var loading = false;

		function close() {
			if ( typeof dlg.close === 'function' ) {
				dlg.close();
			} else {
				dlg.removeAttribute( 'open' );
			}
		}

		closeBtn.addEventListener( 'click', close );
		// کلیک روی پس‌زمینهٔ تیره
		dlg.addEventListener( 'click', function ( event ) {
			if ( event.target === dlg ) {
				close();
			}
		} );

		function setProgress( fraction, message ) {
			progress.hidden = false;
			fill.style.width = Math.round( Math.max( 0, Math.min( 1, fraction ) ) * 100 ) + '%';
			text.textContent = message || '';
		}

		/** JSON ذخیره‌شده را با یک درخواست می‌خواند؛ فقط منبع‌های بیش از ۱۰۰ رکورد صفحه‌های بعدی را جدا می‌گیرند. */
		async function load() {
			var state = newState();

			setProgress( 0.1, i18n.loadingSaved );
			var all = await request( 'extraction/saved-all', 'GET' );
			if ( ! all.ok || ! all.data ) {
				throw new Error( 'load' );
			}

			var site = all.data.site || {};
			if ( site.info ) {
				state.site = site.info;
				state.siteRecord = site.record_id || '';
				markTrained( state, site.trained_ids, site.processing_ids );
			}

			var ids = [];
			var sourceIds = Object.keys( all.data.sources || {} );
			for ( var i = 0; i < sourceIds.length; i++ ) {
				var id = sourceIds[ i ];
				var first = all.data.sources[ id ];
				if ( ! first || ! first.total ) {
					continue;
				}
				ids.push( id );
				var st = newSourceState();
				state.sources[ id ] = st;
				mergeBatch( state, st, first );

				var next = first.done ? null : first.next_offset;
				while ( next !== null && next !== undefined ) {
					setProgress( 0.5, sprintf( i18n.readSource, sourceLabel( id ), formatNumber( st.items.length ), formatNumber( st.total ) ) );
					var res = await request( getPath( 'extraction/saved', { source: id, offset: next, limit: 100 } ), 'GET' );
					if ( ! res.ok ) {
						st.error = ( res.data && res.data.message ) || i18n.unknownError || '';
						break;
					}
					mergeBatch( state, st, res.data );
					next = res.data.done ? null : res.data.next_offset;
				}
			}

			return { state: state, sources: ids };
		}

		/** دکمهٔ «آموزش هوش مصنوعی» دیالوگ: رکوردهای تیک‌خورده (۲۰۰ تا ۲۰۰ تا) برای آموزش فرستاده می‌شوند. */
		var trainBtn = role( results, 'train' );
		var trainCount = role( results, 'tree-count' );
		var training = false;
		if ( trainBtn ) {
			trainBtn.disabled = false;
			trainBtn.removeAttribute( 'aria-disabled' );
			trainBtn.addEventListener( 'click', async function () {
				var ids = results._paiaSelected ? results._paiaSelected() : [];
				if ( training || ! ids.length ) {
					if ( ! ids.length && trainCount ) {
						trainCount.textContent = i18n.treeNone;
					}
					return;
				}
				training = true;
				trainBtn.classList.add( 'is-loading' );
				trainBtn.disabled = true;
				var sent = 0;
				var done = 0;
				var failed = false;
				var lastError = '';
				for ( var i = 0; i < ids.length; i += 200 ) {
					var res = await request( 'extraction/auto-train', 'POST', { ids: ids.slice( i, i + 200 ) } );
					if ( ! res.ok || ! res.data ) {
						failed = true;
						break;
					}
					sent += res.data.sent || 0;
					done += res.data.trained || 0;
					if ( res.data.error ) {
						lastError = res.data.error;
					}
				}
				trainBtn.classList.remove( 'is-loading' );
				trainBtn.disabled = false;
				training = false;
				if ( trainCount ) {
					trainCount.textContent = failed ? i18n.anTrainFailed : done > 0 ? sprintf( i18n.anTrained, formatNumber( done ) ) : lastError || i18n.anTrainPending;
				}
				if ( done > 0 ) {
					if ( onChange ) {
						onChange();
					}
					open( true ); // آیکن هوش مصنوعی موارد آموزش‌دیده را نشان بده
				}
			} );
		}

		async function open( keepMessage ) {
			if ( loading ) {
				return;
			}
			loading = true;
			notice.hidden = true;
			results.hidden = true;
			body.scrollTop = 0;

			if ( typeof dlg.showModal === 'function' ) {
				if ( ! dlg.open ) {
					dlg.showModal();
				}
			} else {
				dlg.setAttribute( 'open', '' );
			}

			try {
				var loaded = await load();
				progress.hidden = true;
				if ( ! loaded.sources.length && ! loaded.state.site ) {
					notice.className = 'paia-notice paia-notice--info';
					notice.textContent = i18n.noSaved || '';
					notice.hidden = false;
				} else {
					renderSummary( role( results, 'summary' ), loaded.state, loaded.sources, { hint: i18n.summarySaved, empty: i18n.summaryNone } );
					renderTree( results, loaded.state, loaded.sources, {} );
					results.hidden = false;
				}
			} catch ( e ) {
				progress.hidden = true;
				notice.className = 'paia-notice paia-notice--error';
				notice.textContent = i18n.unknownError || '';
				notice.hidden = false;
			}

			loading = false;
		}

		return {
			open: function () {
				return open( false );
			},
		};
	}

	/* ------------------------------------------------------------------ */
	/* استخراج اطلاعات سایت (اولیه و جدید: یک کد، دو حالت)                  */
	/* ------------------------------------------------------------------ */

	function initExtractor( block, onDone ) {
		var mode = block.getAttribute( 'data-mode' ) || 'initial';
		var isNew = mode === 'new';
		var form = role( block, 'form' );
		var boxes = [].slice.call( form.querySelectorAll( 'input[name="sources[]"]' ) );
		var button = role( form, 'extract-btn' );
		var label = button.querySelector( '.paia-btn__label' );
		var counter = role( form, 'selected-count' );
		var notice = role( form, 'notice' );
		var progress = role( form, 'progress' );
		var progressFill = role( form, 'progress-fill' );
		var progressText = role( form, 'progress-text' );
		var results = role( block, 'results' );
		var summaryBox = role( results, 'summary' );
		var idleLabel = label.textContent;
		var busy = false;

		function selected() {
			return boxes
				.filter( function ( box ) {
					return box.checked;
				} )
				.map( function ( box ) {
					return box.value;
				} );
		}

		function refresh() {
			var count = selected().length;
			counter.textContent = count ? ( i18n.selectedCount || '%d' ).replace( '%d', formatNumber( count ) ) : i18n.noneSelected || '';
			button.disabled = busy || count === 0;
		}

		function showNotice( type, message ) {
			notice.className = 'paia-notice paia-notice--' + type;
			notice.textContent = message;
			notice.hidden = false;
		}

		function setBusy( state ) {
			busy = state;
			button.classList.toggle( 'is-loading', state );
			button.setAttribute( 'aria-busy', state ? 'true' : 'false' );
			label.textContent = state ? ( isNew ? i18n.workingNew : i18n.working ) || idleLabel : idleLabel;
			refresh();
		}

		function setProgress( fraction, text ) {
			progress.hidden = false;
			progressFill.style.width = Math.round( Math.max( 0, Math.min( 1, fraction ) ) * 100 ) + '%';
			progressText.textContent = text || '';
		}

		function setAll( checked ) {
			boxes.forEach( function ( box ) {
				box.checked = checked;
			} );
			refresh();
		}

		boxes.forEach( function ( box ) {
			box.addEventListener( 'change', refresh );
		} );

		[].slice.call( form.querySelectorAll( '[data-action]' ) ).forEach( function ( control ) {
			control.addEventListener( 'click', function () {
				setAll( control.getAttribute( 'data-action' ) === 'select-all' );
			} );
		} );

		/** همهٔ منبع‌ها را به‌ترتیب و دسته‌دسته می‌خواند (سمت سرور هر دسته همان لحظه در جدول ذخیره می‌شود). */
		async function run( sources ) {
			var state = newState();

			// اطلاعات کلی سایت فقط در استخراج اولیه؛ استخراج جدید فقط آیتم‌های تازه را می‌خواهد.
			if ( ! isNew ) {
				setProgress( 0, i18n.progressSite );
				var site = await request( 'extraction/site', 'GET' );
				if ( site.ok && site.data && site.data.info ) {
					state.site = site.data.info;
					state.siteRecord = site.data.record_id || '';
					markTrained( state, site.data.trained_ids, site.data.processing_ids );
				}
			}

			for ( var i = 0; i < sources.length; i++ ) {
				var id = sources[ i ];
				var st = newSourceState();
				state.sources[ id ] = st;
				var offset = 0;

				setProgress( i / sources.length, sprintf( i18n.progressStart, sourceLabel( id ) ) );

				while ( true ) {
					var res = await request( 'extraction', 'POST', { source: id, offset: offset, mode: mode } );
					if ( ! res.ok ) {
						st.error = ( res.data && res.data.message ) || i18n.unknownError || '';
						break;
					}

					var data = res.data;
					mergeBatch( state, st, data );

					// در حالت جدید، فقط موارد تازه برمی‌گردند؛ پیشرفت را از موقعیت پیمایش می‌سازیم.
					var scanned = data.done || data.next_offset === null || data.next_offset === undefined ? st.total : data.next_offset;
					var fraction = st.total ? Math.min( 1, scanned / st.total ) : 1;
					setProgress(
						( i + fraction ) / sources.length,
						isNew
							? sprintf( i18n.progressCheck, sourceLabel( id ), formatNumber( Math.min( scanned, st.total ) ), formatNumber( st.total ) )
							: sprintf( i18n.progressSource, sourceLabel( id ), formatNumber( st.items.length ), formatNumber( st.total ) )
					);

					if ( data.done || data.next_offset === null || data.next_offset === undefined || data.next_offset <= offset ) {
						break;
					}
					offset = data.next_offset;
				}
			}

			setProgress( 1, isNew ? i18n.finishedNew : i18n.finished );
			return state;
		}

		form.addEventListener( 'submit', async function ( event ) {
			event.preventDefault();

			var sources = selected();
			if ( busy || ! sources.length ) {
				return;
			}

			notice.hidden = true;
			results.hidden = true;
			setBusy( true );

			try {
				var state = await run( sources );
				var failed = sources.some( function ( id ) {
					return state.sources[ id ].error;
				} );
				var added = {};
				var addedTotal = 0;
				sources.forEach( function ( id ) {
					var n = state.sources[ id ].items.length;
					if ( n ) {
						added[ id ] = n;
						addedTotal += n;
					}
				} );

				if ( isNew && ! addedTotal && ! failed ) {
					// چیز جدیدی نبود: فقط پیام نشان داده می‌شود.
					showNotice( 'info', i18n.noNewFound );
				} else {
					renderSummary( summaryBox, state, sources, isNew ? { hint: i18n.summaryNew, empty: i18n.summaryNoneNew } : {} );
					renderTree( results, state, sources, { isNew: isNew } );
					results.hidden = false;
					if ( failed ) {
						showNotice( 'error', i18n.finishedErrors );
					} else {
						// نتیجه نشان داده شد؛ دیگر فرم استخراج روی صفحه نمی‌ماند.
						notice.hidden = true;
						progress.hidden = true;
						form.hidden = true;
					}
					if ( results.scrollIntoView ) {
						results.scrollIntoView( { behavior: 'smooth', block: 'start' } );
					}
				}

				if ( onDone ) {
					onDone( isNew ? added : null );
				}
			} catch ( e ) {
				showNotice( 'error', i18n.unknownError || '' );
			}

			setBusy( false );
		} );

		refresh();

		return {
			show: function () {
				block.hidden = false;
				form.hidden = false;
				if ( block.scrollIntoView ) {
					block.scrollIntoView( { behavior: 'smooth', block: 'start' } );
				}
			},
		};
	}

	/* ------------------------------------------------------------------ */
	/* تنظیمات › «استخراج خودکار اطلاعات سایت» (تیک‌های هر دسته)            */
	/* ------------------------------------------------------------------ */

	/**
	 * فقط منطق ظاهری: تیک دوم (آموزش) تا وقتی تیک اول روشن نیست غیرفعال است و با برداشتن تیک اول برداشته می‌شود.
	 * هیچ درخواستی به سرور نمی‌رود و هیچ تیکی منتظر ذخیره نمی‌ماند؛ همهٔ تغییرها فقط با دکمهٔ «ذخیرهٔ تغییرات»
	 * (همان فرم options.php) ذخیره می‌شوند.
	 */
	function initAutoSync() {
		var rows = [].slice.call( document.querySelectorAll( '[data-role="sync-row"]' ) );

		rows.forEach( function ( row ) {
			var add = role( row, 'sync-add' );
			var train = role( row, 'sync-train' );
			if ( ! add || ! train ) {
				return;
			}

			function apply() {
				if ( ! add.checked ) {
					train.checked = false;
				}
				train.disabled = ! add.checked;
			}

			add.addEventListener( 'change', apply );
			apply();
		} );
	}

	/* ------------------------------------------------------------------ */
	/* فرم «اطلاعات جدید ثبت شده به صورت خودکار»                            */
	/* ------------------------------------------------------------------ */

	/**
	 * به‌جای تیک:
	 *  - aiIcon()      پردازش‌شده (ai_status = ۲)؛ با hover متن «به هوش مصنوعی آموزش داده شده» می‌آید.
	 *  - aiIcon(true)  در حال پردازش سمت سرور (ai_status = ۱)؛ آیکن loading با hover «در حال پردازش سمت سرور».
	 */
	function aiIcon( processing ) {
		var tip = processing ? i18n.aiProcessing : i18n.aiTrained;
		var badge = el( 'span', { class: 'paia-ai' + ( processing ? ' paia-ai--loading' : '' ), tabindex: '0', role: 'img', 'aria-label': tip, 'data-tip': tip } );
		if ( processing ) {
			badge.appendChild( el( 'span', { class: 'paia-ai__spin', 'aria-hidden': 'true' } ) );
		} else {
			badge.innerHTML = ( config.icons || {} ).ai || ''; // SVG داخلی و امن از PHP
		}
		badge.addEventListener( 'click', function ( event ) {
			event.stopPropagation();
		} );
		return badge;
	}

	/**
	 * هر مورد مثل درخت استخراج: تیک (یا آیکن هوش مصنوعی برای آموزش‌دیده‌ها) + عنوان؛ با زدن روی مورد، جزئیاتش
	 * (همان نمایش کلید/مقدار درخت) زیرش باز می‌شود. دکمه‌ها:
	 *  - «همه را دیدم»: همهٔ موارد نمایش‌داده‌شده دیده می‌شوند.
	 *  - «آموزش به هوش مصنوعی»: فقط موارد تیک‌خورده آموزش داده می‌شوند و همان‌ها هم «دیده» می‌شوند.
	 * هر دو اعمال آنی دارند (مورد همان لحظه از فرم برمی‌دارد) و در شکست به حالت قبل برمی‌گردند.
	 */
	function initAutoNew( root, onGrow ) {
		var box = role( root, 'autonew' );
		if ( ! box ) {
			return;
		}

		var list = role( box, 'autonew-list' );
		var countChip = role( box, 'autonew-count' );
		var errorBox = role( box, 'autonew-error' );
		var infoBox = role( box, 'autonew-info' );
		var allBox = role( box, 'autonew-all' );
		var seenBtn = role( box, 'autonew-seen' );
		var trainBtn = role( box, 'autonew-train' );
		var selectedLabel = role( box, 'autonew-selected' );

		var last = null; // آخرین پاسخ سرور { count, items } (برای برگرداندن در صورت شکست)
		var shown = []; // موارد نمایش‌داده‌شده؛ فقط همین‌ها «دیده» می‌شوند
		var selected = {}; // شناسهٔ رکورد => تیک خورده؟
		var ticks = {}; // شناسهٔ رکورد => چک‌باکس رندرشده
		var cache = {}; // شناسهٔ رکورد => داده‌ی کامل (برای جزئیات)
		var lastSig = '';
		var flashing = false;
		var flashTimer = 0;
		var busy = false;
		var loading = false;

		function setError( message ) {
			errorBox.textContent = message || '';
			errorBox.hidden = ! message;
		}

		function flash( message ) {
			window.clearTimeout( flashTimer );
			infoBox.textContent = message;
			infoBox.hidden = ! message;
			flashing = !! message;
			if ( message ) {
				box.hidden = false; // حتی اگر موردی نمانده، پیام نتیجه تا چند ثانیه دیده شود
				flashTimer = window.setTimeout( function () {
					flashing = false;
					infoBox.hidden = true;
					if ( ! last || ! last.count ) {
						box.hidden = true;
					}
				}, 8000 );
			}
		}

		function selectable() {
			return shown.filter( function ( item ) {
				return ! item.trained && ! item.processing;
			} );
		}

		function pickedIds() {
			return selectable()
				.filter( function ( item ) {
					return selected[ item.id ];
				} )
				.map( function ( item ) {
					return item.id;
				} );
		}

		function refreshControls() {
			var pool = selectable();
			var picked = pickedIds().length;
			allBox.disabled = busy || ! pool.length;
			allBox.checked = pool.length > 0 && picked === pool.length;
			allBox.indeterminate = picked > 0 && picked < pool.length;
			seenBtn.disabled = busy || ! shown.length;
			trainBtn.disabled = busy || ! picked;
			selectedLabel.textContent = picked ? sprintf( i18n.treeSelected, formatNumber( picked ) ) : shown.length ? i18n.treeNone : '';
		}

		/** جزئیات هر مورد: با اولین باز شدن، داده‌ی کامل رکورد از سرور خوانده و با همان نمایش درخت رسم می‌شود. */
		function loadDetails( item, host ) {
			if ( cache[ item.id ] ) {
				host.appendChild( objectNode( cache[ item.id ] ) );
				return;
			}
			var wait = el( 'p', { class: 'paia-note paia-autonew__wait', text: i18n.anLoading } );
			host.appendChild( wait );
			request( getPath( 'extraction/record', { id: item.id } ), 'GET' ).then( function ( res ) {
				if ( wait.parentNode ) {
					wait.parentNode.removeChild( wait );
				}
				if ( res.ok && res.data && res.data.data ) {
					cache[ item.id ] = res.data.data;
					host.appendChild( objectNode( res.data.data ) );
				} else {
					host.appendChild( el( 'p', { class: 'paia-note', text: i18n.anDetailFailed } ) );
				}
			} );
		}

		function rowNode( item ) {
			var summary = el( 'summary' );
			var lead;

			if ( item.trained || item.processing ) {
				lead = aiIcon( ! item.trained );
			} else {
				lead = el( 'input', { type: 'checkbox', class: 'paia-tick', 'aria-label': item.title } );
				lead.checked = !! selected[ item.id ];
				// کلیک روی تیک نباید جزئیات را باز/بسته کند.
				lead.addEventListener( 'click', function ( event ) {
					event.stopPropagation();
				} );
				lead.addEventListener( 'change', function () {
					selected[ item.id ] = lead.checked;
					refreshControls();
				} );
				ticks[ item.id ] = lead;
			}

			summary.appendChild( lead );
			summary.appendChild( el( 'span', { class: 'paia-item__title', dir: 'auto', text: item.title } ) );
			summary.appendChild( el( 'span', { class: 'paia-chip paia-chip--tax', text: item.label } ) );
			if ( item.url ) {
				var view = el( 'a', { class: 'paia-autonew__view', href: item.url, target: '_blank', rel: 'noopener noreferrer', text: i18n.anView } );
				view.addEventListener( 'click', function ( event ) {
					event.stopPropagation();
				} );
				summary.appendChild( view );
			}

			var details = el( 'details', { class: 'paia-item' }, [ summary ] );
			details.addEventListener( 'toggle', function () {
				if ( details.open && ! details.getAttribute( 'data-ready' ) ) {
					details.setAttribute( 'data-ready', '1' );
					var host = el( 'div', { class: 'paia-sub' } );
					details.appendChild( host );
					loadDetails( item, host );
				}
			} );
			return details;
		}

		function render( data ) {
			last = data;
			shown = ( data.items || [] ).slice();
			ticks = {};
			clear( list );

			var alive = {};
			shown.forEach( function ( item ) {
				alive[ item.id ] = true;
				if ( ! ( item.id in selected ) ) {
					selected[ item.id ] = true; // مثل درخت استخراج: پیش‌فرض همه تیک‌خورده
				}
				list.appendChild( rowNode( item ) );
			} );
			Object.keys( selected ).forEach( function ( id ) {
				if ( ! alive[ id ] ) {
					delete selected[ id ];
				}
			} );

			var rest = ( data.count || 0 ) - shown.length;
			if ( rest > 0 ) {
				list.appendChild( el( 'p', { class: 'paia-autonew__more', text: sprintf( i18n.anMore, formatNumber( rest ) ) } ) );
			}
			countChip.textContent = sprintf( i18n.anCount, formatNumber( data.count || 0 ) );
			lastSig = shown
				.map( function ( item ) {
					return item.id + ( item.trained ? '*' : item.processing ? '~' : '' );
				} )
				.join( '|' ) + '#' + ( data.count || 0 );
			box.hidden = ! ( data.count > 0 || flashing || busy );
			box.classList.toggle( 'paia-autonew--empty', ! shown.length && ! busy );
			refreshControls();
			scheduleRefresh();
		}

		/** مورد «در حال پردازش» در لیست هست: هر ۸ ثانیه بی‌صدا دوباره خوانده می‌شود تا با اعلام نتیجهٔ سرور آیکن هوش مصنوعی بیاید. */
		var refreshTimer = 0;
		function scheduleRefresh() {
			window.clearTimeout( refreshTimer );
			refreshTimer = 0;
			var waiting = shown.some( function ( item ) {
				return item.processing;
			} );
			if ( ! waiting ) {
				return;
			}
			refreshTimer = window.setTimeout( function () {
				refreshTimer = 0;
				if ( busy || loading || document.hidden ) {
					scheduleRefresh();
					return;
				}
				load( true ).then( scheduleRefresh );
			}, STATUS_POLL_MS );
		}

		async function load( silent ) {
			if ( loading ) {
				return;
			}
			loading = true;
			if ( ! silent ) {
				clear( list );
				list.appendChild( el( 'p', { class: 'paia-autonew__more', text: i18n.anLoading } ) );
				seenBtn.disabled = true;
				trainBtn.disabled = true;
				box.hidden = false;
			}
			var res = await request( 'extraction/auto-new', 'GET' );
			loading = false;
			if ( res.ok && res.data && Array.isArray( res.data.items ) ) {
				var before = last ? last.count || 0 : 0;
				setError( '' );
				var sig = res.data.items
					.map( function ( item ) {
						return item.id + ( item.trained ? '*' : item.processing ? '~' : '' );
					} )
					.join( '|' ) + '#' + ( res.data.count || 0 );
				// تغییری نکرده: فهرست دوباره رسم نمی‌شود تا جزئیات بازشده و تیک‌ها نپرند.
				if ( ! ( silent && sig === lastSig ) ) {
					render( res.data );
				}
				if ( silent && res.data.count > before && onGrow ) {
					onGrow(); // مورد تازه‌ای ثبت شده؛ آمار «خلاصهٔ اطلاعات ثبت‌شده» هم به‌روز شود.
				}
			} else if ( ! silent ) {
				clear( list );
				setError( i18n.anLoadFailed );
			}
		}

		allBox.addEventListener( 'change', function () {
			selectable().forEach( function ( item ) {
				selected[ item.id ] = allBox.checked;
				if ( ticks[ item.id ] ) {
					ticks[ item.id ].checked = allBox.checked;
				}
			} );
			refreshControls();
		} );

		/**
		 * اعمال آنی + loading: موارد همان لحظه از فرم برداشته می‌شوند؛ اگر ذخیره شکست بخورد، فرم با همان
		 * موارد و تیک‌ها برمی‌گردد و خطا نشان داده می‌شود.
		 */
		async function dispatch( ids, path, button, done ) {
			if ( busy || ! ids.length ) {
				return;
			}
			busy = true;
			var snapshot = last;
			var picks = Object.assign( {}, selected );
			var gone = {};
			ids.forEach( function ( id ) {
				gone[ id ] = true;
			} );

			setError( '' );
			flash( '' );
			button.classList.add( 'is-loading' );
			render( {
				count: Math.max( 0, ( last.count || 0 ) - ids.length ),
				items: ( last.items || [] ).filter( function ( item ) {
					return ! gone[ item.id ];
				} ),
			} );

			var res = await request( path, 'POST', { ids: ids } );

			busy = false;
			button.classList.remove( 'is-loading' );

			if ( res.ok && res.data ) {
				last = { count: res.data.count || 0, items: last.items || [] };
				done( res.data );
				if ( res.data.count > 0 ) {
					load( true ); // مواردی که بین نمایش و کلیک ثبت شده‌اند (یا از سقف لیست بیرون بودند)
				}
			} else if ( snapshot ) {
				selected = picks;
				render( snapshot );
				setError( path === 'extraction/auto-train' ? i18n.anTrainFailed : i18n.anSeenFailed );
			}
			refreshControls();
		}

		seenBtn.addEventListener( 'click', function () {
			dispatch(
				shown.map( function ( item ) {
					return item.id;
				} ),
				'extraction/auto-seen',
				seenBtn,
				function () {}
			);
		} );

		trainBtn.addEventListener( 'click', function () {
			dispatch( pickedIds(), 'extraction/auto-train', trainBtn, function ( data ) {
				var sent = data.sent || 0;
				var trained = data.trained || 0;
				var msg = trained > 0 ? sprintf( i18n.anTrained, formatNumber( trained ) ) : '';
				if ( trained < sent ) {
					// بک‌اند آموزش را تأیید نکرده: موارد در لیست می‌مانند (dispatch بعد از این دوباره لیست را می‌خواند).
					msg += ( msg ? ' ' : '' ) + ( data.error || i18n.anTrainPending );
				}
				flash( msg );
				if ( onGrow ) {
					onGrow();
				}
			} );
		} );

		// وقتی مدیر به این تب برمی‌گردد، موارد تازه (منتشرشده در این فاصله) هم دیده شوند.
		document.addEventListener( 'visibilitychange', function () {
			if ( ! document.hidden && ! busy ) {
				load( true );
			}
		} );

		if ( ( ( config.autoNew || {} ).count || 0 ) > 0 ) {
			load( false );
		}
	}

	/**
	 * لیست «موارد حذف‌شده از سایت» (تب «استخراج اطلاعات سایت»): محصول/وبلاگ/برگه‌ای که از سایت حذف شده و مدیر هنوز
	 * دربارهٔ حذفش از آموزش‌های هوش مصنوعی تصمیم نگرفته. هر ردیف دکمهٔ «حذف» دارد: ردیف همان لحظه از لیست خارج می‌شود
	 * (اعمال آنی)، درخواست به سرور می‌رود (بک‌اند هوش مصنوعی: AiBackend::delete) و اگر شکست بخورد ردیف برمی‌گردد.
	 */
	function initRemoved( root, onChange ) {
		var box = role( root, 'removed' );
		if ( ! box ) {
			return;
		}

		var list = role( box, 'removed-list' );
		var countChip = role( box, 'removed-count' );
		var errorBox = role( box, 'removed-error' );

		var last = { count: 0, items: [] };
		var lastSig = '';
		var loadingNow = false;
		var pending = 0; // حذف‌های در حال انجام؛ در این مدت خواندن دوباره لیست را نمی‌پراند

		function setError( message ) {
			errorBox.textContent = message || '';
			errorBox.hidden = ! message;
		}

		function signature( data ) {
			return ( data.items || [] )
				.map( function ( item ) {
					return item.id;
				} )
				.join( '|' ) + '#' + ( data.count || 0 );
		}

		function paint() {
			var shown = ( last.items || [] ).length;
			var rest = ( last.count || 0 ) - shown;
			if ( rest > 0 ) {
				list.appendChild( el( 'p', { class: 'paia-removed__more', text: sprintf( i18n.rmMore, formatNumber( rest ) ) } ) );
			}
			countChip.textContent = sprintf( i18n.rmCount, formatNumber( last.count || 0 ) );
			box.hidden = ! ( last.count > 0 );
		}

		// ردیف‌هایی که سرور حذفشان را پذیرفته و منتظر وب‌هوک‌اند: شناسه => { row, button }
		var deleting = {};
		var pollTimer = 0;

		function setBusy( entry, busy ) {
			var label = entry.button.querySelector( '.paia-btn__label' );
			entry.button.disabled = busy;
			entry.button.classList.toggle( 'is-loading', busy );
			entry.row.classList.toggle( 'is-deleting', busy );
			if ( label ) {
				label.textContent = busy ? i18n.rmDeleting : i18n.rmDelete;
			}
		}

		function schedulePoll() {
			if ( pollTimer || ! Object.keys( deleting ).length ) {
				return;
			}
			pollTimer = window.setTimeout( pollDeleting, 4000 );
		}

		/** هر چند ثانیه (تا وقتی ردیفی «در حال حذف» است): سرور حذف را تمام کرد ← ردیف می‌رود؛ شکست خورد ← دکمه برمی‌گردد. */
		async function pollDeleting() {
			pollTimer = 0;
			var ids = Object.keys( deleting );
			if ( ! ids.length ) {
				return;
			}
			if ( document.hidden ) {
				schedulePoll();
				return;
			}
			var res = await request( getPath( 'extraction/removed', { check: ids.join( ',' ) } ), 'GET' );
			if ( res.ok && res.data ) {
				var finished = res.data.finished || [];
				finished.forEach( function ( id ) {
					var entry = deleting[ id ];
					delete deleting[ id ];
					last.items = last.items.filter( function ( entryItem ) {
						return entryItem.id !== id;
					} );
					if ( entry && entry.row.parentNode ) {
						entry.row.parentNode.removeChild( entry.row );
					}
				} );
				( res.data.failed || [] ).forEach( function ( id ) {
					var entry = deleting[ id ];
					delete deleting[ id ];
					if ( entry ) {
						setBusy( entry, false );
					}
					setError( i18n.rmDeleteFailed );
				} );
				if ( typeof res.data.count === 'number' ) {
					last.count = res.data.count;
					lastSig = signature( last );
					countChip.textContent = sprintf( i18n.rmCount, formatNumber( last.count ) );
					box.hidden = ! ( last.count > 0 );
				}
				if ( finished.length && onChange ) {
					onChange();
				}
			}
			schedulePoll();
		}

		function rowNode( item ) {
			var button = el( 'button', { type: 'button', class: 'paia-btn paia-btn--danger paia-btn--sm' }, [ iconSpan( 'trash' ), el( 'span', { class: 'paia-btn__label', text: i18n.rmDelete } ), el( 'span', { class: 'paia-spinner', 'aria-hidden': 'true' } ) ] );
			var row = el( 'div', { class: 'paia-removed__row' }, [
				el( 'span', { class: 'paia-removed__title', dir: 'auto', text: item.title } ),
				el( 'span', { class: 'paia-chip paia-chip--tax', text: item.label } ),
				button,
			] );
			var entry = { row: row, button: button };
			button.addEventListener( 'click', function () {
				removeItem( item, entry );
			} );
			if ( item.processing ) {
				// سرور قبلاً حذف را پذیرفته (صفحه دوباره باز شده): loading تا اعلام نتیجه.
				setBusy( entry, true );
				deleting[ item.id ] = entry;
				schedulePoll();
			}
			return row;
		}

		function render( data ) {
			last = { count: data.count || 0, items: ( data.items || [] ).slice() };
			lastSig = signature( last );
			deleting = {};
			clear( list );
			last.items.forEach( function ( item ) {
				list.appendChild( rowNode( item ) );
			} );
			paint();
		}

		async function removeItem( item, entry ) {
			var row = entry.row;
			setBusy( entry, true ); // loading تا پذیرفتن سرور (۲۰۰)
			pending++;
			setError( '' );

			var res = await request( 'extraction/removed/delete', 'POST', { id: item.id } );
			pending--;

			if ( res.ok && res.data && res.data.processing ) {
				// سرور پذیرفت (= ۱): ردیف با loading می‌ماند تا وب‌هوک `untrained` برسد؛ pollDeleting آن را برمی‌دارد.
				deleting[ item.id ] = entry;
				schedulePoll();
				return;
			}

			if ( res.ok && res.data ) {
				var before = last.count || 0;
				last.items = last.items.filter( function ( entry ) {
					return entry.id !== item.id;
				} );
				last.count = typeof res.data.count === 'number' ? res.data.count : Math.max( 0, before - 1 );
				lastSig = signature( last );
				if ( row.parentNode ) {
					row.parentNode.removeChild( row );
				}
				countChip.textContent = sprintf( i18n.rmCount, formatNumber( last.count ) );
				box.hidden = ! ( last.count > 0 );
				if ( last.count > last.items.length && ! pending ) {
					load( true ); // مواردی که از سقف لیست بیرون بودند بیایند
				}
				if ( onChange ) {
					onChange();
				}
			} else {
				setBusy( entry, false );
				setError( res && res.data && res.data.message ? res.data.message : i18n.rmDeleteFailed );
			}
			}

		async function load( silent ) {
			if ( loadingNow || pending ) {
				return;
			}
			loadingNow = true;
			if ( ! silent ) {
				clear( list );
				list.appendChild( el( 'p', { class: 'paia-removed__more', text: i18n.rmLoading } ) );
				box.hidden = false;
			}
			var res = await request( 'extraction/removed', 'GET' );
			loadingNow = false;

			if ( res.ok && res.data && Array.isArray( res.data.items ) ) {
				setError( '' );
				var before = last.count || 0;
				// تغییری نکرده: دوباره رسم نمی‌شود.
				if ( ! ( silent && signature( res.data ) === lastSig ) ) {
					render( res.data );
				}
				if ( silent && ( res.data.count || 0 ) !== before && onChange ) {
					onChange(); // آمار «خلاصهٔ اطلاعات ثبت‌شده» هم به‌روز شود.
				}
			} else if ( ! silent ) {
				clear( list );
				setError( i18n.rmLoadFailed );
			}
		}

		// وقتی مدیر به این تب برمی‌گردد، موارد تازه حذف‌شده هم دیده شوند.
		document.addEventListener( 'visibilitychange', function () {
			if ( ! document.hidden ) {
				load( true );
			}
		} );

		if ( ( ( config.removed || {} ).count || 0 ) > 0 ) {
			load( false );
		}
	}

	/* ------------------------------------------------------------------ */
	/* تب «اسناد اضافه»: مطالب اضافه شده + فرم «اضافه کردن متن جدید»        */
	/* ------------------------------------------------------------------ */

	/**
	 * ادیتور متن با «خط جداکننده‌ی چانک». ظاهرش یک باکس متن است؛ هر چانک یک textarea است و بین چانک‌ها یک خط (hr) کشیده می‌شود.
	 *  - دو بار Enter (Enter روی خط خالیِ تازه): همان‌جا متن دو نیم می‌شود و بین‌شان خط کشیده می‌شود.
	 *  - Backspace در ابتدای یک چانک (بعد از خط): خط پاک می‌شود و دو چانک یکی می‌شوند. Delete در انتهای چانک هم همین‌طور.
	 *  - چسباندن (paste) متنی که خط خالی دارد: خودکار به چانک‌های جدا تبدیل می‌شود.
	 * (از beforeinput استفاده شده تا روی کیبورد موبایل هم کار کند.)
	 */
	function createEditor( host, onChange ) {
		function chunkEls() {
			return [].slice.call( host.querySelectorAll( 'textarea' ) );
		}

		function autosize( ta ) {
			if ( ! ta.scrollHeight ) {
				ta.style.height = ''; // فرم هنوز مخفی است؛ هنگام باز شدن دوباره اندازه گرفته می‌شود.
				return;
			}
			ta.style.height = 'auto';
			ta.style.height = ta.scrollHeight + 2 + 'px';
		}

		function prevChunk( ta ) {
			var line = ta.previousElementSibling;
			return line ? line.previousElementSibling : null;
		}

		function nextChunk( ta ) {
			var line = ta.nextElementSibling;
			return line ? line.nextElementSibling : null;
		}

		function focusAt( ta, pos ) {
			ta.focus();
			var at = Math.max( 0, Math.min( ta.value.length, pos ) );
			ta.setSelectionRange( at, at );
		}

		function placeholders() {
			var all = chunkEls();
			all.forEach( function ( ta, index ) {
				if ( all.length === 1 && index === 0 ) {
					ta.setAttribute( 'placeholder', i18n.dcPlaceholder || '' );
				} else {
					ta.removeAttribute( 'placeholder' );
				}
			} );
		}

		function changed() {
			placeholders();
			if ( onChange ) {
				onChange();
			}
		}

		/** دو چانکِ پشت‌سرهم را یکی می‌کند (خط بینشان پاک می‌شود). */
		function merge( first, second ) {
			var joined;
			var caret;
			if ( second.value === '' ) {
				joined = first.value;
				caret = first.value.length;
			} else if ( first.value === '' ) {
				joined = second.value;
				caret = 0;
			} else {
				joined = first.value + '\n' + second.value;
				caret = first.value.length + 1;
			}
			var line = second.previousElementSibling;
			first.value = joined;
			host.removeChild( line );
			host.removeChild( second );
			autosize( first );
			focusAt( first, caret );
			changed();
		}

		function insertAfter( ta, value ) {
			var line = el( 'hr', { class: 'paia-rtb__line', 'aria-hidden': 'true' } );
			var next = makeChunk( value );
			host.insertBefore( line, ta.nextSibling );
			host.insertBefore( next, line.nextSibling );
			return next;
		}

		function makeChunk( value ) {
			var ta = el( 'textarea', { class: 'paia-rtb__chunk', rows: '1', dir: 'auto', 'aria-label': i18n.dcPlaceholder } );
			ta.value = value || '';

			ta.addEventListener( 'input', function () {
				autosize( ta );
				changed();
			} );

			ta.addEventListener( 'beforeinput', function ( event ) {
				var type = event.inputType;
				var start = ta.selectionStart;
				var end = ta.selectionEnd;

				// دو بار Enter → خط جداکننده (چانک جدید)
				if ( ( type === 'insertLineBreak' || type === 'insertParagraph' ) && start === end && start > 0 && ta.value.charAt( start - 1 ) === '\n' ) {
					event.preventDefault();
					var before = ta.value.slice( 0, start - 1 ).replace( /\s+$/, '' );
					if ( before === '' ) {
						return; // چانکِ خالی ساخته نمی‌شود
					}
					var after = ta.value.slice( start ).replace( /^\s*\n/, '' );
					ta.value = before;
					var next = insertAfter( ta, after );
					autosize( ta );
					autosize( next );
					focusAt( next, 0 );
					changed();
					return;
				}

				// Backspace در ابتدای چانک → پاک شدن خط و یکی شدن با چانک قبلی
				if ( type === 'deleteContentBackward' && start === 0 && end === 0 && prevChunk( ta ) ) {
					event.preventDefault();
					merge( prevChunk( ta ), ta );
					return;
				}

				// Delete در انتهای چانک → پاک شدن خطِ بعدی
				if ( type === 'deleteContentForward' && start === end && start === ta.value.length && nextChunk( ta ) ) {
					event.preventDefault();
					merge( ta, nextChunk( ta ) );
				}
			} );

			// رفت‌وآمد بین چانک‌ها با کلیدهای جهت‌دار
			ta.addEventListener( 'keydown', function ( event ) {
				if ( event.isComposing || event.shiftKey || event.ctrlKey || event.metaKey || event.altKey ) {
					return;
				}
				if ( event.key === 'ArrowUp' && ta.selectionStart === 0 && ta.selectionEnd === 0 && prevChunk( ta ) ) {
					event.preventDefault();
					var p = prevChunk( ta );
					focusAt( p, p.value.length );
				} else if ( event.key === 'ArrowDown' && ta.selectionStart === ta.value.length && ta.selectionEnd === ta.value.length && nextChunk( ta ) ) {
					event.preventDefault();
					focusAt( nextChunk( ta ), 0 );
				}
			} );

			ta.addEventListener( 'paste', function ( event ) {
				var clip = event.clipboardData || window.clipboardData;
				var text = clip ? clip.getData( 'text' ) : '';
				if ( ! text ) {
					return;
				}
				var parts = text.replace( /\r\n?/g, '\n' ).split( /\n[ \t]*\n(?:[ \t]*\n)*/ );
				if ( parts.length < 2 ) {
					return; // چسباندن معمولی
				}
				event.preventDefault();
				var tail = ta.value.slice( ta.selectionEnd );
				ta.value = ta.value.slice( 0, ta.selectionStart ) + parts[ 0 ].replace( /\s+$/, '' );
				autosize( ta );
				var cur = ta;
				for ( var i = 1; i < parts.length; i++ ) {
					var last = i === parts.length - 1;
					cur = insertAfter( cur, last ? parts[ i ] + tail : parts[ i ] );
					autosize( cur );
				}
				focusAt( cur, parts[ parts.length - 1 ].length );
				changed();
			} );

			return ta;
		}

		// کلیک روی فضای خالیِ باکس: مکان‌نما به آخرین چانک می‌رود.
		host.addEventListener( 'click', function ( event ) {
			if ( event.target === host ) {
				var all = chunkEls();
				var lastEl = all[ all.length - 1 ];
				focusAt( lastEl, lastEl.value.length );
			}
		} );

		function reset() {
			clear( host );
			host.appendChild( makeChunk( '' ) );
			changed();
		}

		// ساخت اولیه بدون فراخوانی onChange (نمونهٔ ادیتور هنوز به صاحبش برنگشته است).
		host.appendChild( makeChunk( '' ) );
		placeholders();

		return {
			/** چانک‌های غیرخالی؛ خط خالی داخل یک چانک هم (برای اطمینان) مرز چانک حساب می‌شود. */
			chunks: function () {
				var out = [];
				chunkEls().forEach( function ( ta ) {
					ta.value.split( /\n[ \t]*\n+/ ).forEach( function ( part ) {
						var text = part.trim();
						if ( text ) {
							out.push( text );
						}
					} );
				} );
				return out;
			},
			stats: function () {
				var chunks = this.chunks();
				var words = 0;
				var chars = 0;
				chunks.forEach( function ( text ) {
					words += text.split( /\s+/ ).filter( Boolean ).length;
					chars += text.length;
				} );
				return { chunks: chunks.length, words: words, chars: chars };
			},
			resize: function () {
				chunkEls().forEach( autosize );
			},
			focus: function () {
				var all = chunkEls();
				var lastEl = all[ all.length - 1 ];
				focusAt( lastEl, lastEl.value.length );
			},
			reset: reset,
		};
	}

	function initDocuments() {
		var wrap = document.querySelector( '[data-role="docs"]' );
		if ( ! wrap ) {
			return;
		}

		var filesBox = role( wrap, 'docs-files' );
		var textsBox = role( wrap, 'docs-texts' );
		var addBtn = role( wrap, 'doc-add' );
		var form = role( wrap, 'doc-form' );
		var host = role( wrap, 'doc-editor' );
		var notice = role( wrap, 'doc-notice' );
		var countBox = role( wrap, 'doc-count' );
		var status = role( wrap, 'doc-status' );
		var trainBtn = role( wrap, 'doc-train' );
		var draftBtn = role( wrap, 'doc-draft' );
		var data = config.docs || { files: {}, texts: {} };
		var lists = null; // لیست مطالب (تب متن‌ها / فایل‌ها)
		var currentId = ''; // بعد از «ذخیره پیش‌نویس»، ذخیره‌های بعدیِ همین متن همان رکورد را به‌روز می‌کنند
		var busy = false;

		function statBox( box, name, value, hint, chips ) {
			clear( box );
			box.appendChild( el( 'h3', { class: 'paia-stat__name', text: name } ) );
			box.appendChild( el( 'div', { class: 'paia-stat__value', text: formatNumber( value ) } ) );
			box.appendChild( el( 'div', { class: 'paia-stat__hint', text: hint } ) );
			var list = el( 'ul', { class: 'paia-chips' } );
			chips.forEach( function ( c ) {
				list.appendChild( c );
			} );
			box.appendChild( list );
		}

		function renderStats() {
			var files = data.files || {};
			var texts = data.texts || {};

			// فایل‌های آپلود شده: چه فرمت‌هایی، به چه تعدادی، و چند تا آموزش داده شده (فعلاً همه صفر)
			var fileChips = ( files.formats || [] ).map( function ( f ) {
				return chip( f.label + ': ' + formatNumber( f.count || 0 ), 'tax' );
			} );
			fileChips.push( chip( sprintf( i18n.dcTrained, formatNumber( files.trained || 0 ) ), 'ai' ) );
			statBox( filesBox, i18n.dcFiles, files.total || 0, i18n.dcFilesHint, fileChips );

			// متن‌های اضافه شده: چند باکس متن، چند چانک و چند کلمه
			var textChips = [
				chip( sprintf( i18n.dcChunks, formatNumber( texts.chunks || 0 ) ) ),
				chip( sprintf( i18n.dcWords, formatNumber( texts.words || 0 ) ) ),
				chip( sprintf( i18n.dcTrained, formatNumber( texts.trained || 0 ) ), 'ai' ),
			];
			if ( texts.drafts > 0 ) {
				textChips.push( chip( sprintf( i18n.dcDrafts, formatNumber( texts.drafts ) ), 'warn' ) );
			}
			statBox( textsBox, i18n.dcTexts, texts.boxes || 0, i18n.dcTextsHint, textChips );
		}

		var editor = createEditor( host, refresh );

		function refresh() {
			var st = editor.stats();
			countBox.textContent = st.chunks
				? [ sprintf( i18n.dcChunks, formatNumber( st.chunks ) ), sprintf( i18n.dcWords, formatNumber( st.words ) ), sprintf( i18n.dcChars, formatNumber( st.chars ) ) ].join( ' · ' )
				: i18n.dcEmpty;
			trainBtn.disabled = busy || ! st.chunks;
			draftBtn.disabled = busy || ! st.chunks;
		}

		function setStatus( text ) {
			status.textContent = text || '';
			status.hidden = ! text;
		}

		function showNotice( type, message ) {
			notice.className = 'paia-notice paia-notice--' + type;
			notice.textContent = message;
			notice.hidden = ! message;
		}

		function openForm( scroll ) {
			form.hidden = false;
			addBtn.setAttribute( 'aria-expanded', 'true' );
			editor.resize();
			if ( scroll ) {
				editor.focus();
				if ( form.scrollIntoView ) {
					form.scrollIntoView( { behavior: 'smooth', block: 'start' } );
				}
			}
		}

		function closeForm() {
			form.hidden = true;
			addBtn.setAttribute( 'aria-expanded', 'false' );
		}

		addBtn.addEventListener( 'click', function () {
			if ( form.hidden ) {
				openForm( true );
			} else {
				closeForm();
			}
		} );

		/**
		 * اول در دیتابیس ذخیره می‌شود، بعد (برای «آموزش به هوش مصنوعی») ارسال به هوش مصنوعی انجام می‌شود (سمت سرور).
		 * اعمال آنی + loading: فرم همان لحظه بسته می‌شود (متن در صفحه می‌ماند)؛ اگر ذخیره شکست بخورد فرم با همان متن برمی‌گردد.
		 */
		async function submit( train ) {
			var chunks = editor.chunks();
			if ( busy || ! chunks.length ) {
				return;
			}
			busy = true;
			var button = train ? trainBtn : draftBtn;
			button.classList.add( 'is-loading' );
			showNotice( 'error', '' );
			setStatus( i18n.dcSaving );
			closeForm();
			refresh();

			var res = await request( 'documents/texts', 'POST', { id: currentId, chunks: chunks, train: train } );

			busy = false;
			button.classList.remove( 'is-loading' );

			if ( res.ok && res.data && res.data.id ) {
				if ( res.data.overview ) {
					data = res.data.overview;
					config.docs = data;
					renderStats();
				}
				if ( train ) {
					currentId = '';
					editor.reset();
					setStatus( i18n.dcTrainSaved );
				} else {
					currentId = res.data.id;
					setStatus( i18n.dcDraftSaved );
				}
				if ( lists ) {
					lists.reload( 'texts' );
				}
			} else {
				setStatus( '' );
				openForm( false );
				showNotice( 'error', i18n.dcFailed );
			}
			refresh();
		}

		trainBtn.addEventListener( 'click', function () {
			submit( true );
		} );
		draftBtn.addEventListener( 'click', function () {
			submit( false );
		} );

		// لیست مطالب (تب متن‌ها / فایل‌ها) و دیالوگ آپلود فایل
		var api = {
			overview: function ( next ) {
				if ( next ) {
					data = next;
					config.docs = data;
					renderStats();
				}
			},
			status: setStatus,
		};
		lists = initDocLists( wrap, api );
		initUploadDialog( wrap, function ( item, overview ) {
			api.overview( overview );
			lists.addFile( item );
			setStatus( i18n.dcUpDone );
		} );

		renderStats();
		refresh();
	}

	/* ------------------------------------------------------------------ */
	/* ابزارهای تب «اسناد اضافه» و «لاگ سرور»                              */
	/* ------------------------------------------------------------------ */

	/** حجم به شکل خوانا (مگابایت / کیلوبایت / بایت). */
	function formatBytes( bytes ) {
		var n = Number( bytes ) || 0;

		function num( value ) {
			try {
				return value.toLocaleString( document.documentElement.lang || undefined, { maximumFractionDigits: 1 } );
			} catch ( e ) {
				return String( Math.round( value * 10 ) / 10 );
			}
		}

		if ( n >= 1048576 ) {
			return num( n / 1048576 ) + ' ' + i18n.sizeMB;
		}
		if ( n >= 1024 ) {
			return num( n / 1024 ) + ' ' + i18n.sizeKB;
		}
		return num( n ) + ' ' + i18n.sizeB;
	}

	/** برچسب کوچک (مثل chip ولی span؛ برای داخل summary). */
	function badge( text, modifier ) {
		return el( 'span', { class: 'paia-chip' + ( modifier ? ' paia-chip--' + modifier : '' ), dir: 'auto', text: text } );
	}

	function iconSpan( name ) {
		var span = el( 'span', { class: 'paia-btn__icon', 'aria-hidden': 'true' } );
		span.innerHTML = ( config.icons || {} )[ name ] || ''; // SVG داخلی و امن از PHP
		return span;
	}

	/**
	 * آپلود با XMLHttpRequest (برای نوار پیشرفتِ فایل‌های حجیم). پاسخ: { ok, status, data }.
	 */
	function uploadFile( file, title, onProgress ) {
		return new Promise( function ( resolve ) {
			var xhr = new window.XMLHttpRequest();
			xhr.open( 'POST', ( config.restUrl || '' ) + 'documents/files' );
			xhr.setRequestHeader( 'X-WP-Nonce', config.nonce || '' );
			xhr.upload.onprogress = function ( event ) {
				if ( event.lengthComputable && event.total > 0 ) {
					onProgress( event.loaded / event.total );
				}
			};
			xhr.onload = function () {
				var data = {};
				try {
					data = JSON.parse( xhr.responseText ) || {};
				} catch ( e ) { /* پاسخ غیر JSON (مثلاً خطای سرور) */ }
				resolve( { ok: xhr.status >= 200 && xhr.status < 300, status: xhr.status, data: data } );
			};
			xhr.onerror = xhr.ontimeout = xhr.onabort = function () {
				resolve( { ok: false, status: 0, data: { message: i18n.networkError || '' } } );
			};
			var form = new window.FormData();
			form.append( 'file', file );
			form.append( 'title', title );
			xhr.send( form );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* لیست مطالب: تب «متن‌ها» و تب «فایل‌ها»                              */
	/* ------------------------------------------------------------------ */

	/**
	 * api.overview(آمار تازه) و api.status(پیام) توسط initDocuments داده می‌شود.
	 *
	 * «حذف آموزش» فقط در نمایش اعمال می‌شود (removedAi): هیچ درخواستی به سرور نمی‌رود و ai_status صفر نمی‌شود؛
	 * با بارگذاری دوبارهٔ صفحه دوباره آموزش‌دیده نمایش داده می‌شود (بک‌اند هوش مصنوعی بعداً آن را انجام می‌دهد).
	 * «حذف کامل» رکورد (و برای فایل، خود فایل) را از دیتابیس/سایت پاک می‌کند.
	 */
	function initDocLists( wrap, api ) {
		var box = role( wrap, 'doc-list' );
		if ( ! box ) {
			return { reload: function () {}, addFile: function () {} };
		}

		var errorBox = role( box, 'doclist-error' );
		var tabs = [].slice.call( box.querySelectorAll( '[data-doclist-tab]' ) );
		var panes = {
			texts: box.querySelector( '[data-doclist-pane="texts"]' ),
			files: box.querySelector( '[data-doclist-pane="files"]' ),
		};
		var lists = { texts: [], files: [] };
		var loaded = { texts: false, files: false };
		var removedAi = {}; // شناسه => true (فقط نمایشی)

		function showError( message ) {
			errorBox.textContent = message || '';
			errorBox.hidden = ! message;
		}

		function countOf( kind ) {
			if ( loaded[ kind ] ) {
				return lists[ kind ].length;
			}
			var docs = config.docs || {};
			return kind === 'texts' ? ( docs.texts || {} ).boxes || 0 : ( docs.files || {} ).total || 0;
		}

		function updateTabs() {
			tabs.forEach( function ( tab ) {
				var kind = tab.getAttribute( 'data-doclist-tab' );
				tab.textContent = ( kind === 'texts' ? i18n.dcTabTexts : i18n.dcTabFiles ) + ' (' + formatNumber( countOf( kind ) ) + ')';
			} );
		}

		function activate( kind ) {
			tabs.forEach( function ( tab ) {
				var on = tab.getAttribute( 'data-doclist-tab' ) === kind;
				tab.classList.toggle( 'is-active', on );
				tab.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
			} );
			Object.keys( panes ).forEach( function ( key ) {
				panes[ key ].hidden = key !== kind;
			} );
		}

		function isTrained( item ) {
			return item.trained && ! removedAi[ item.id ];
		}

		function itemNode( kind, item ) {
			var summary = el( 'summary' );
			var details = el( 'details', { class: 'paia-item paia-doc' }, [ summary ] );
			var host = null;
			var info = null;
			var actions = null;
			var msg = null;
			var busy = false;

			function drawSummary() {
				clear( summary );
				if ( isTrained( item ) ) {
					summary.appendChild( aiIcon() );
				} else if ( item.processing ) {
					summary.appendChild( aiIcon( true ) );
				}
				summary.appendChild( el( 'span', { class: 'paia-item__title', dir: 'auto', text: item.title || i18n.dcUntitled } ) );
				if ( kind === 'texts' ) {
					summary.appendChild( badge( sprintf( i18n.dcChunks, formatNumber( item.chunks ) ), 'tax' ) );
					summary.appendChild( badge( sprintf( i18n.dcWords, formatNumber( item.words ) ), 'tax' ) );
					if ( item.draft ) {
						summary.appendChild( badge( i18n.dcDraftChip, 'warn' ) );
					}
				} else {
					summary.appendChild( badge( String( item.ext || '' ).toUpperCase(), 'tax' ) );
					summary.appendChild( badge( formatBytes( item.size ), 'tax' ) );
				}
				if ( item.trained && removedAi[ item.id ] ) {
					summary.appendChild( badge( i18n.dcRemovedChip, 'warn' ) );
				}
				if ( item.time ) {
					summary.appendChild( el( 'span', { class: 'paia-doc__time', dir: 'ltr', text: item.time } ) );
				}
			}

			function setMsg( text, type ) {
				if ( ! msg ) {
					return;
				}
				msg.textContent = text || '';
				msg.hidden = ! text;
				msg.className = 'paia-note paia-doc__msg' + ( type === 'error' ? ' is-error' : '' );
			}

			/** جزئیات فایل (کلید/مقدار)؛ وضعیت آموزش هم با «حذف آموزش» به‌روز می‌شود. */
			function drawInfo() {
				if ( ! info ) {
					return;
				}
				clear( info );
				var status = isTrained( item ) ? i18n.dcStTrained : item.processing ? i18n.dcStProcessing : item.trained ? i18n.dcStRemoved : i18n.dcStNot;
				var dl = el( 'dl', { class: 'paia-kv' } );
				[
					[ i18n.dcFTitle, item.title ],
					[ i18n.dcFName, item.original_name ],
					[ i18n.dcFFormat, String( item.ext || '' ).toUpperCase() ],
					[ i18n.dcFSize, formatBytes( item.size ) ],
					[ i18n.dcFDate, item.time ],
					[ i18n.dcFStatus, status ],
				].forEach( function ( row ) {
					if ( ! row[ 1 ] ) {
						return;
					}
					dl.appendChild( el( 'dt', { text: row[ 0 ] } ) );
					dl.appendChild( el( 'dd', {}, [ el( 'span', { dir: 'auto', text: row[ 1 ] } ) ] ) );
				} );
				info.appendChild( dl );
			}

			async function loadChunks( target ) {
				target.appendChild( el( 'p', { class: 'paia-note', text: i18n.dcListLoading } ) );
				var res = await request( getPath( 'documents/texts/item', { id: item.id } ), 'GET' );
				clear( target );
				if ( ! res.ok || ! res.data || ! Array.isArray( res.data.chunks ) ) {
					target.appendChild( el( 'p', { class: 'paia-note is-error', text: i18n.dcDetailFailed } ) );
					return;
				}
				res.data.chunks.forEach( function ( text, index ) {
					if ( index > 0 ) {
						target.appendChild( el( 'hr', { class: 'paia-doc__sep' } ) );
					}
					target.appendChild( el( 'div', { class: 'paia-doc__chunk', dir: 'auto', text: text } ) );
				} );
			}

			function update() {
				drawSummary();
				drawInfo();
				drawActions();
			}

			/** «در حال پردازش»: تا اعلام نتیجهٔ سرور وضعیت پرسیده می‌شود، بعد آیکن عوض می‌شود. */
			function watchItem() {
				watchStatus( [ item.id ], function ( status ) {
					item.processing = false;
					item.trained = 2 === status;
					update();
					if ( msg ) {
						setMsg( '' );
					}
				} );
			}

			async function train( button ) {
				if ( busy ) {
					return;
				}
				busy = true;
				button.disabled = true;
				button.classList.add( 'is-loading' );
				setMsg( '' );

				var res = await request( 'documents/' + kind + '/train', 'POST', { id: item.id } );
				busy = false;

				if ( res.ok && res.data ) {
					api.overview( res.data.overview );
					if ( kind === 'texts' ) {
						item.draft = false;
					}
					if ( res.data.trained ) {
						item.trained = true;
						item.processing = false;
						delete removedAi[ item.id ];
						update();
						setMsg( i18n.dcItemTrained );
					} else if ( res.data.processing ) {
						// سرور درخواست را پذیرفت (۲۰۰): آیکن loading؛ با اعلام نتیجه آیکن هوش مصنوعی می‌آید.
						item.processing = true;
						delete removedAi[ item.id ];
						update();
						setMsg( i18n.dcItemTrained );
						watchItem();
					} else {
						update();
						setMsg( i18n.dcItemReady );
					}
				} else {
					button.disabled = false;
					button.classList.remove( 'is-loading' );
					setMsg( i18n.dcItemFailed, 'error' );
				}
			}

			/** حذف کامل: همان لحظه از لیست پنهان می‌شود؛ اگر سرور موفق نبود برمی‌گردد. */
			async function removeFull() {
				if ( busy || ! window.confirm( kind === 'texts' ? i18n.dcDelTextAsk : i18n.dcDelFileAsk ) ) {
					return;
				}
				busy = true;
				details.hidden = true;

				var res = await request( 'documents/' + kind + '/delete', 'POST', { id: item.id } );
				busy = false;

				if ( res.ok && res.data ) {
					lists[ kind ] = lists[ kind ].filter( function ( other ) {
						return other.id !== item.id;
					} );
					delete removedAi[ item.id ];
					api.overview( res.data.overview );
					renderPane( kind );
					updateTabs();
					api.status( i18n.dcDeleted );
				} else {
					details.hidden = false;
					setMsg( i18n.dcDeleteFailed, 'error' );
				}
			}

			function drawActions() {
				if ( ! actions ) {
					return;
				}
				clear( actions );

				if ( isTrained( item ) ) {
					// حذف آموزش واقعی: درخواست به سرور می‌رود؛ بعد از پذیرفتن (۲۰۰) آیکن loading می‌شود و با وب‌هوک `untrained` دکمهٔ «آموزش» برمی‌گردد.
					var removeAi = el( 'button', { type: 'button', class: 'paia-btn paia-btn--ghost paia-btn--sm' }, [ iconSpan( 'trash' ), el( 'span', { class: 'paia-btn__label', text: i18n.dcRemoveAi } ), el( 'span', { class: 'paia-spinner', 'aria-hidden': 'true' } ) ] );
					removeAi.addEventListener( 'click', async function () {
						if ( busy || ! window.confirm( i18n.removeAiConfirm ) ) {
							return;
						}
						busy = true;
						removeAi.disabled = true;
						removeAi.classList.add( 'is-loading' );
						setMsg( '' );
						var res = await request( 'extraction/untrain', 'POST', { ids: [ item.id ] } );
						busy = false;
						if ( res.ok && res.data && ( res.data.accepted || [] ).indexOf( item.id ) !== -1 ) {
							item.trained = false;
							item.processing = true;
							delete removedAi[ item.id ];
							update();
							setMsg( i18n.removedAi );
							watchItem();
						} else {
							removeAi.disabled = false;
							removeAi.classList.remove( 'is-loading' );
							setMsg( ( res.data && res.data.error ) || ( res.data && res.data.message ) || i18n.untrainFailed, 'error' );
						}
					} );
					actions.appendChild( removeAi );
				} else if ( ! item.processing ) {
					var trainBtn = el( 'button', { type: 'button', class: 'paia-btn paia-btn--sm' }, [ iconSpan( 'ai' ), el( 'span', { class: 'paia-btn__label', text: i18n.dcTrainBtn } ), el( 'span', { class: 'paia-spinner', 'aria-hidden': 'true' } ) ] );
					trainBtn.addEventListener( 'click', function () {
						train( trainBtn );
					} );
					actions.appendChild( trainBtn );
				}

				if ( kind === 'files' ) {
					// دانلود فایل آپلودشده (REST با _wpnonce؛ فایل روی دیسک مستقیم در دسترس نیست).
					var dl = el( 'a', {
						class: 'paia-btn paia-btn--ghost paia-btn--sm',
						href: ( config.restUrl || '' ) + getPath( 'documents/files/download', { id: item.id, _wpnonce: config.nonce || '' } ),
						download: '',
						rel: 'noopener',
					}, [ el( 'span', { class: 'paia-btn__label', text: i18n.dcDownload } ) ] );
					actions.appendChild( dl );
				}

				var del = el( 'button', { type: 'button', class: 'paia-btn paia-btn--danger paia-btn--sm' }, [ iconSpan( 'trash' ), el( 'span', { class: 'paia-btn__label', text: i18n.dcDeleteFull } ) ] );
				del.addEventListener( 'click', removeFull );
				actions.appendChild( del );
			}

			function ensureBody() {
				if ( host ) {
					return;
				}
				host = el( 'div', { class: 'paia-sub paia-doc__body' } );
				if ( kind === 'texts' ) {
					var chunks = el( 'div', { class: 'paia-doc__chunks' } );
					host.appendChild( chunks );
					loadChunks( chunks );
				} else {
					info = el( 'div', { class: 'paia-doc__info' } );
					host.appendChild( info );
				}
				msg = el( 'p', { class: 'paia-note paia-doc__msg', role: 'status', hidden: true } );
				actions = el( 'div', { class: 'paia-doc__actions' } );
				host.appendChild( msg );
				host.appendChild( actions );
				details.appendChild( host );
				drawInfo();
				drawActions();
			}

			details.addEventListener( 'toggle', function () {
				if ( details.open ) {
					ensureBody();
				}
			} );

			drawSummary();
			if ( item.processing ) {
				watchItem();
			}
			return details;
		}

		function renderPane( kind ) {
			var pane = panes[ kind ];
			clear( pane );
			if ( ! loaded[ kind ] ) {
				pane.appendChild( el( 'p', { class: 'paia-note', text: i18n.dcListLoading } ) );
				return;
			}
			if ( ! lists[ kind ].length ) {
				pane.appendChild( el( 'p', { class: 'paia-note', text: kind === 'texts' ? i18n.dcListEmptyText : i18n.dcListEmptyFile } ) );
				return;
			}
			var items = el( 'div', { class: 'paia-doclist__items' } );
			lists[ kind ].forEach( function ( item ) {
				items.appendChild( itemNode( kind, item ) );
			} );
			pane.appendChild( items );
		}

		async function load( kind, quiet ) {
			var res = await request( 'documents/' + kind, 'GET' );
			if ( res.ok && res.data && Array.isArray( res.data.items ) ) {
				lists[ kind ] = res.data.items;
				loaded[ kind ] = true;
				showError( '' );
			} else if ( ! quiet || ! loaded[ kind ] ) {
				loaded[ kind ] = false;
				clear( panes[ kind ] );
				panes[ kind ].appendChild( el( 'p', { class: 'paia-note is-error', text: i18n.dcListFailed } ) );
				updateTabs();
				return;
			}
			renderPane( kind );
			updateTabs();
		}

		tabs.forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				activate( tab.getAttribute( 'data-doclist-tab' ) );
			} );
		} );

		updateTabs();
		renderPane( 'texts' );
		renderPane( 'files' );
		load( 'texts' );
		load( 'files' );

		return {
			/** بعد از ذخیرهٔ یک متن، فهرست بی‌صدا دوباره خوانده می‌شود. */
			reload: function ( kind ) {
				load( kind, true );
			},
			/** فایلِ تازه آپلودشده بالای فهرست فایل‌ها می‌آید و تب فایل‌ها باز می‌شود. */
			addFile: function ( item ) {
				lists.files = [ item ].concat( lists.files.filter( function ( other ) {
					return other.id !== item.id;
				} ) );
				loaded.files = true;
				renderPane( 'files' );
				updateTabs();
				activate( 'files' );
			},
		};
	}

	/* ------------------------------------------------------------------ */
	/* دیالوگ آپلود فایل                                                  */
	/* ------------------------------------------------------------------ */

	function initUploadDialog( wrap, onUploaded ) {
		var dlg = role( wrap, 'file-dialog' );
		var openBtn = role( wrap, 'file-add' );
		if ( ! dlg || ! openBtn ) {
			return;
		}

		var up = config.upload || {};
		var limit = up.limit || 104857600;
		var max = Math.min( limit, up.max || limit );
		var exts = up.exts || [ 'pdf', 'doc', 'docx', 'txt' ];

		var closeBtn = role( dlg, 'file-close' );
		var cancelBtn = role( dlg, 'file-cancel' );
		var submitBtn = role( dlg, 'file-submit' );
		var input = role( dlg, 'file-input' );
		var drop = role( dlg, 'file-drop' );
		var nameBox = role( dlg, 'file-name' );
		var titleInput = role( dlg, 'file-title' );
		var confirmBox = role( dlg, 'file-confirm' );
		var errorBox = role( dlg, 'file-error' );
		var progress = role( dlg, 'file-progress' );
		var fill = role( dlg, 'file-progress-fill' );
		var progressText = role( dlg, 'file-progress-text' );
		var limitBox = role( dlg, 'file-limit' );
		var serverNote = role( dlg, 'file-server-note' );

		var picked = null;
		var uploading = false;

		limitBox.textContent = sprintf( i18n.dcUpLimit, formatBytes( limit ) );
		if ( up.serverMax && up.serverMax < limit ) {
			serverNote.textContent = sprintf( i18n.dcUpServerLimit, formatBytes( up.serverMax ) );
			serverNote.hidden = false;
		}

		function showError( message ) {
			errorBox.textContent = message || '';
			errorBox.hidden = ! message;
		}

		function refresh() {
			submitBtn.disabled = uploading || ! picked || ! confirmBox.checked;
			cancelBtn.disabled = uploading;
		}

		function setProgress( fraction ) {
			var percent = Math.round( fraction * 100 );
			fill.style.width = percent + '%';
			progressText.textContent = fraction >= 1 ? i18n.dcUpSaving : sprintf( i18n.dcUploading, formatNumber( percent ) );
		}

		function validate( file ) {
			var ext = String( file.name || '' ).split( '.' ).pop().toLowerCase();
			if ( exts.indexOf( ext ) === -1 ) {
				return i18n.dcUpBadType;
			}
			if ( ! file.size ) {
				return i18n.dcUpEmpty;
			}
			if ( file.size > max ) {
				return sprintf( i18n.dcUpTooBig, formatBytes( max ) );
			}
			return '';
		}

		function reset() {
			picked = null;
			input.value = '';
			nameBox.textContent = i18n.dcUpNone;
			titleInput.value = '';
			confirmBox.checked = false;
			progress.hidden = true;
			fill.style.width = '0';
			showError( '' );
			refresh();
		}

		function pick( file ) {
			showError( '' );
			if ( ! file ) {
				picked = null;
				nameBox.textContent = i18n.dcUpNone;
				refresh();
				return;
			}
			var problem = validate( file );
			if ( problem ) {
				picked = null;
				input.value = '';
				nameBox.textContent = i18n.dcUpNone;
				showError( problem );
			} else {
				picked = file;
				nameBox.textContent = file.name + ' — ' + formatBytes( file.size );
			}
			refresh();
		}

		function open() {
			reset();
			if ( typeof dlg.showModal === 'function' ) {
				dlg.showModal();
			} else {
				dlg.setAttribute( 'open', '' );
			}
		}

		function close() {
			if ( uploading ) {
				return;
			}
			if ( typeof dlg.close === 'function' ) {
				dlg.close();
			} else {
				dlg.removeAttribute( 'open' );
			}
		}

		async function submit() {
			if ( ! picked || uploading || ! confirmBox.checked ) {
				return;
			}
			uploading = true;
			submitBtn.classList.add( 'is-loading' );
			showError( '' );
			progress.hidden = false;
			setProgress( 0 );
			refresh();

			var res = await uploadFile( picked, titleInput.value.trim(), setProgress );

			uploading = false;
			submitBtn.classList.remove( 'is-loading' );

			if ( res.ok && res.data && res.data.item ) {
				close();
				onUploaded( res.data.item, res.data.overview );
			} else {
				progress.hidden = true;
				var message = res.data && res.data.message ? res.data.message : res.status === 413 ? i18n.dcUpTooBigSrv : i18n.dcUpFailed;
				showError( message );
			}
			refresh();
		}

		openBtn.addEventListener( 'click', open );
		closeBtn.addEventListener( 'click', close );
		cancelBtn.addEventListener( 'click', close );
		submitBtn.addEventListener( 'click', submit );
		confirmBox.addEventListener( 'change', refresh );
		input.addEventListener( 'change', function () {
			pick( input.files && input.files[ 0 ] ? input.files[ 0 ] : null );
		} );

		// کشیدن و رها کردن فایل روی کادر انتخاب
		[ 'dragenter', 'dragover' ].forEach( function ( type ) {
			drop.addEventListener( type, function ( event ) {
				event.preventDefault();
				drop.classList.add( 'is-over' );
			} );
		} );
		[ 'dragleave', 'drop' ].forEach( function ( type ) {
			drop.addEventListener( type, function () {
				drop.classList.remove( 'is-over' );
			} );
		} );
		drop.addEventListener( 'drop', function ( event ) {
			event.preventDefault();
			var files = event.dataTransfer && event.dataTransfer.files;
			if ( files && files.length ) {
				try {
					input.files = files;
				} catch ( e ) { /* مرورگرهای قدیمی */ }
				pick( files[ 0 ] );
			}
		} );

		// در حال آپلود، Esc و کلیک روی پس‌زمینه دیالوگ را نمی‌بندد.
		dlg.addEventListener( 'cancel', function ( event ) {
			if ( uploading ) {
				event.preventDefault();
			}
		} );
		dlg.addEventListener( 'click', function ( event ) {
			if ( event.target === dlg ) {
				close();
			}
		} );
	}

	/* ------------------------------------------------------------------ */
	/* تب «لاگ سرور»                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * آمار (کل / موفق / ناموفق) + فهرست لاگ‌ها؛ موفق سبز، ناموفق قرمز، عادی بدون رنگ ویژه.
	 * هر ردیف با زدن باز می‌شود و متن کامل + جزئیات را زیرش نشان می‌دهد (متن بلند در ردیف با «…» کوتاه است).
	 */
	function initServerLog() {
		var wrap = document.querySelector( '[data-role="slog"]' );
		if ( ! wrap ) {
			return;
		}

		var statsBox = role( wrap, 'slog-stats' );
		var list = role( wrap, 'slog-list' );
		var more = role( wrap, 'slog-more' );
		var refreshBtn = role( wrap, 'slog-refresh' );
		var errorBox = role( wrap, 'slog-error' );
		var updatedTag = role( wrap, 'slog-updated' );
		var stats = config.logs || { total: 0, success: 0, error: 0 };
		var lastUpdate = stats.last_update || '';

		function renderUpdated() {
			updatedTag.textContent = i18n.lgLastUpdate + ' ' + ( lastUpdate || i18n.lgNever );
		}
		var cursor = 0;
		var busy = false;

		function statBox( modifier, name, value ) {
			return el( 'div', { class: 'paia-stat' + ( modifier ? ' ' + modifier : '' ) }, [
				el( 'h3', { class: 'paia-stat__name', text: name } ),
				el( 'div', { class: 'paia-stat__value', text: formatNumber( value ) } ),
				el( 'div', { class: 'paia-stat__hint', text: i18n.lgHint } ),
			] );
		}

		function renderStats() {
			clear( statsBox );
			statsBox.appendChild( statBox( '', i18n.lgTotal, stats.total || 0 ) );
			statsBox.appendChild( statBox( 'paia-stat--ok', i18n.lgSuccess, stats.success || 0 ) );
			statsBox.appendChild( statBox( 'paia-stat--bad', i18n.lgError, stats.error || 0 ) );
		}

		function statusLabel( status ) {
			return status === 'success' ? i18n.lgStSuccess : status === 'error' ? i18n.lgStError : i18n.lgStInfo;
		}

		function showError( message ) {
			errorBox.textContent = message || '';
			errorBox.hidden = ! message;
		}

		async function loadDetails( item, host ) {
			host.appendChild( el( 'p', { class: 'paia-note', text: i18n.lgLoading } ) );
			var res = await request( getPath( 'logs/item', { id: item.id } ), 'GET' );
			clear( host );

			if ( ! res.ok || ! res.data ) {
				host.appendChild( el( 'p', { class: 'paia-note is-error', text: i18n.lgDetailFailed } ) );
				return;
			}

			var main = el( 'dl', { class: 'paia-kv' } );
			main.appendChild( el( 'dt', { text: i18n.lgFullText } ) );
			main.appendChild( el( 'dd', {}, [ el( 'div', { class: 'paia-log__full', dir: 'auto', text: res.data.message || '' } ) ] ) );
			main.appendChild( el( 'dt', { text: i18n.lgTime } ) );
			main.appendChild( el( 'dd', {}, [ el( 'span', { dir: 'ltr', text: res.data.time || '' } ) ] ) );
			host.appendChild( main );

			var data = res.data.data || {};
			if ( Object.keys( data ).length ) {
				host.appendChild( el( 'h4', { class: 'paia-log__sub', text: i18n.lgDetails } ) );
				host.appendChild( objectNode( data ) );
			}
		}

		function rowNode( item ) {
			var summary = el( 'summary', {}, [
				el( 'span', { class: 'paia-log__dot', 'aria-hidden': 'true' } ),
				el( 'span', { class: 'paia-log__text', dir: 'auto', text: item.message } ),
				el( 'span', { class: 'paia-chip paia-log__chip', text: statusLabel( item.status ) } ),
				el( 'span', { class: 'paia-log__time', dir: 'ltr', text: item.time } ),
			] );
			var details = el( 'details', { class: 'paia-item paia-log paia-log--' + item.status }, [ summary ] );

			details.addEventListener( 'toggle', function () {
				if ( details.open && ! details.getAttribute( 'data-ready' ) ) {
					details.setAttribute( 'data-ready', '1' );
					var host = el( 'div', { class: 'paia-sub paia-log__body' } );
					details.appendChild( host );
					loadDetails( item, host );
				}
			} );

			return details;
		}

		async function load( reset ) {
			if ( busy ) {
				return;
			}
			busy = true;
			refreshBtn.classList.add( 'is-loading' );
			more.classList.add( 'is-loading' );
			if ( reset ) {
				cursor = 0;
				more.hidden = true;
				clear( list );
				list.appendChild( el( 'p', { class: 'paia-note', text: i18n.lgLoading } ) );
			}

			var params = { limit: 30 };
			if ( ! reset && cursor ) {
				params.before = cursor;
			}
			var res = await request( getPath( 'logs', params ), 'GET' );

			busy = false;
			refreshBtn.classList.remove( 'is-loading' );
			more.classList.remove( 'is-loading' );

			if ( res.ok && res.data && Array.isArray( res.data.items ) ) {
				if ( reset ) {
					clear( list );
				}
				stats = res.data.stats || stats;
				config.logs = stats;
				renderStats();
				renderUpdated();
				res.data.items.forEach( function ( item ) {
					list.appendChild( rowNode( item ) );
				} );
				if ( res.data.items.length ) {
					cursor = res.data.items[ res.data.items.length - 1 ].id;
				}
				more.hidden = ! res.data.has_more;
				if ( ! list.firstChild ) {
					list.appendChild( el( 'p', { class: 'paia-note', text: i18n.lgEmpty } ) );
				}
				showError( '' );
			} else {
				if ( reset ) {
					clear( list );
				}
				showError( i18n.lgFailed );
			}
		}

		// فقط با زدن دکمه: ابتدا لاگ‌های تازه از سرور گرفته می‌شود، بعد فهرست محلی دوباره خوانده می‌شود.
		refreshBtn.addEventListener( 'click', async function () {
			if ( busy ) {
				return;
			}
			refreshBtn.classList.add( 'is-loading' );
			var sync = await request( 'logs/sync', 'POST', {} );
			refreshBtn.classList.remove( 'is-loading' );
			if ( sync.ok && sync.data && sync.data.ok ) {
				lastUpdate = sync.data.last_update || lastUpdate;
				showError( sync.data.more ? i18n.lgSyncMore : '' );
				await load( true );
				if ( sync.data.more ) {
					showError( i18n.lgSyncMore );
				}
			} else {
				showError( i18n.lgSyncFailed + ' ' + ( ( sync.data && ( sync.data.error || sync.data.message ) ) || '' ) );
			}
		} );
		more.addEventListener( 'click', function () {
			load( false );
		} );

		renderStats();
		renderUpdated();
		load( true ); // فقط از دیتابیس خود افزونه؛ بدون تماس با سرور
	}

	/* ------------------------------------------------------------------ */
	/* تب «اطلاعات به‌روز»: لیست + فرم‌ها (جدول‌های paia_live_*)             */
	/* ------------------------------------------------------------------ */

	function initLive() {
		var wrap = document.querySelector( '[data-role="live"]' );
		if ( ! wrap ) {
			return;
		}

		var listBox = role( wrap, 'live-list' );
		var listMsg = role( wrap, 'live-listmsg' );
		var addBtn = role( wrap, 'live-add' );
		var cForm = role( wrap, 'live-custom' );
		var cMsg = role( wrap, 'live-msg' );
		var title = role( wrap, 'live-title' );
		var saveBtn = role( wrap, 'live-save' );
		var delBtn = role( wrap, 'live-delete' );

		// سه ردیف ثابت اول لیست: تمام محصولات، تمام وبلاگ‌ها، تمام برگه‌ها (هر کدام یک فرم تنظیمات دارد).
		// وبلاگ‌ها و برگه‌ها از ۰.۵.۲۴ سمت سرور پنهان‌اند (LiveStore::HIDDEN_SOURCES)؛ فقط منبع‌هایی که در داده آمده‌اند نمایش داده می‌شوند.
		var SOURCES = [ 'products', 'blog', 'pages' ].filter( function ( key ) {
			return !! ( ( config.live && config.live.sources ) || {} )[ key ];
		} );
		var sourceTitle = { products: i18n.lvProductsTitle, blog: i18n.lvBlogTitle, pages: i18n.lvPagesTitle };
		var sourceChip = { products: i18n.lvProductsChip, blog: i18n.lvBlogChip, pages: i18n.lvPagesChip };
		var data = config.live || { sources: {}, items: [] };
		var sources = {};
		var forms = {};
		SOURCES.forEach( function ( key ) {
			var state = ( data.sources || {} )[ key ] || {};
			sources[ key ] = { enabled: state.enabled !== false, fields: state.fields || {} };
			var form = wrap.querySelector( '[data-role="live-source"][data-source="' + key + '"]' );
			if ( form ) {
				forms[ key ] = { form: form, msg: role( form, 'live-source-msg' ), save: role( form, 'live-source-save' ) };
			}
		} );
		var items = ( data.items || [] ).slice();
		var selected = ''; // '' | 'products' | 'blog' | 'pages' | 'new' | شناسهٔ مورد
		var editingId = 0;
		var busy = false;
		var loadToken = 0;

		function note( node, text, type ) {
			node.textContent = text || '';
			node.hidden = ! text;
			node.className = node.className.replace( / is-error/g, '' ) + ( type === 'error' ? ' is-error' : '' );
		}

		function errorText( res, fallback ) {
			return res && res.data && res.data.message ? res.data.message : fallback;
		}

		function setLabel( button, text ) {
			var label = button.querySelector( '.paia-btn__label' );
			if ( label ) {
				label.textContent = text;
			}
		}

		function loading( button, state ) {
			button.disabled = state;
			button.classList.toggle( 'is-loading', state );
		}

		/* ---- لیست ---- */

		function switchNode( checked, name, onChange ) {
			var input = el( 'input', { type: 'checkbox', role: 'switch', class: 'paia-switch__input', 'aria-label': sprintf( i18n.lvToggleLabel, name ) } );
			input.checked = !! checked;
			input.addEventListener( 'change', function () {
				onChange( input );
			} );
			return el( 'label', { class: 'paia-switch' }, [ input, el( 'span', { class: 'paia-switch__track', 'aria-hidden': 'true' } ) ] );
		}

		function rowNode( key, name, enabled, time, extraClass, onToggle, chipText ) {
			var off = el( 'span', { class: 'paia-chip paia-chip--warn', text: i18n.lvOff } );
			off.hidden = !! enabled;
			var main = el( 'button', { type: 'button', class: 'paia-live__main', 'aria-pressed': selected === key ? 'true' : 'false' }, [
				el( 'span', { class: 'paia-live__name', dir: 'auto', text: name } ),
				chipText ? el( 'span', { class: 'paia-chip paia-chip--ai', text: chipText } ) : null,
				off,
				time ? el( 'span', { class: 'paia-live__time', dir: 'ltr', text: time } ) : null,
			] );
			var row = el( 'div', { class: 'paia-live__row' + ( extraClass ? ' ' + extraClass : '' ) + ( selected === key ? ' is-active' : '' ) + ( enabled ? '' : ' is-off' ) }, [ main ] );
			row.appendChild(
				switchNode( enabled, name, function ( input ) {
					off.hidden = input.checked;
					onToggle( input, row, off );
				} )
			);
			main.addEventListener( 'click', function () {
				if ( sources[ key ] ) {
					openSource( key );
				} else {
					openItem( key );
				}
			} );
			return row;
		}

		/** تغییر وضعیت: همان لحظه اعمال می‌شود؛ اگر سرور ثبت نکرد برمی‌گردد. */
		async function toggle( input, row, off, path, body, apply ) {
			var want = input.checked;
			apply( want );
			row.classList.toggle( 'is-off', ! want );
			row.classList.add( 'is-saving' );
			note( listMsg, '' );

			var res = await request( path, 'POST', Object.assign( { enabled: want }, body ) );
			row.classList.remove( 'is-saving' );

			if ( ! res.ok ) {
				apply( ! want );
				input.checked = ! want;
				off.hidden = ! want;
				row.classList.toggle( 'is-off', want );
				note( listMsg, errorText( res, i18n.lvToggleFailed ), 'error' );
			}
		}

		function renderList() {
			clear( listBox );

			SOURCES.forEach( function ( key ) {
				listBox.appendChild(
					rowNode( key, sourceTitle[ key ], sources[ key ].enabled, '', 'paia-live__row--products', function ( input, row, off ) {
						toggle( input, row, off, 'live/source/toggle', { source: key }, function ( value ) {
							sources[ key ].enabled = value;
						} );
					}, sourceChip[ key ] )
				);
			} );

			items.forEach( function ( item ) {
				listBox.appendChild(
					rowNode( String( item.id ), item.name, item.enabled, item.time, '', function ( input, row, off ) {
						toggle( input, row, off, 'live/item/toggle', { id: item.id }, function ( value ) {
							item.enabled = value;
						} );
					} )
				);
			} );

			if ( ! items.length ) {
				listBox.appendChild( el( 'p', { class: 'paia-note paia-live__empty', text: i18n.lvEmpty } ) );
			}
		}

		function showForms() {
			SOURCES.forEach( function ( key ) {
				if ( forms[ key ] ) {
					forms[ key ].form.hidden = selected !== key;
				}
			} );
			cForm.hidden = selected === '' || !! sources[ selected ];
			renderList();
		}

		function reveal( form ) {
			if ( form.scrollIntoView ) {
				form.scrollIntoView( { block: 'nearest' } );
			}
		}

		/* ---- فرم تنظیمات هر منبع (محصولات، وبلاگ‌ها، برگه‌ها) ---- */

		function fieldBoxes( form ) {
			return [].slice.call( form.querySelectorAll( 'input[name="live_fields[]"]' ) );
		}

		function openSource( key ) {
			if ( ! forms[ key ] ) {
				return;
			}
			selected = key;
			editingId = 0;
			fieldBoxes( forms[ key ].form ).forEach( function ( box ) {
				box.checked = !! sources[ key ].fields[ box.value ];
			} );
			note( forms[ key ].msg, '' );
			showForms();
			reveal( forms[ key ].form );
		}

		SOURCES.forEach( function ( key ) {
			if ( ! forms[ key ] ) {
				return;
			}
			forms[ key ].form.addEventListener( 'submit', async function ( event ) {
				event.preventDefault();
				if ( busy ) {
					return;
				}
				var fields = {};
				fieldBoxes( forms[ key ].form ).forEach( function ( box ) {
					fields[ box.value ] = box.checked;
				} );

				busy = true;
				loading( forms[ key ].save, true );
				note( forms[ key ].msg, '' );
				var res = await request( 'live/source', 'POST', { source: key, fields: fields } );
				busy = false;
				loading( forms[ key ].save, false );

				if ( res.ok && res.data && res.data.source ) {
					sources[ key ].fields = res.data.source.fields || fields;
					note( forms[ key ].msg, i18n.lvSaved );
				} else {
					note( forms[ key ].msg, errorText( res, i18n.lvSaveFailed ), 'error' );
				}
			} );
		} );

		/* ---- فرم اطلاعات تعریف‌شده ---- */

		function fillCustom( item ) {
			cForm.elements.live_name.value = item ? item.name || '' : '';
			cForm.elements.live_description.value = item ? item.description || '' : '';
			cForm.elements.live_data.value = item ? item.content || '' : '';
			title.textContent = item ? i18n.lvEditTitle : i18n.lvAddTitle;
			setLabel( saveBtn, item ? i18n.lvSaveEdit : i18n.lvSaveNew );
			delBtn.hidden = ! item;
		}

		function lockCustom( state ) {
			[].forEach.call( cForm.elements, function ( field ) {
				if ( field.getAttribute( 'data-role' ) !== 'live-close' ) {
					field.disabled = state;
				}
			} );
		}

		function openNew() {
			selected = 'new';
			editingId = 0;
			fillCustom( null );
			lockCustom( false );
			note( cMsg, '' );
			showForms();
			reveal( cForm );
			cForm.elements.live_name.focus();
		}

		async function openItem( id ) {
			var token = ++loadToken;
			selected = String( id );
			editingId = Number( id );
			var summary = items.filter( function ( item ) {
				return String( item.id ) === String( id );
			} )[ 0 ];

			fillCustom( summary ? { name: summary.name, description: '', content: '' } : null );
			lockCustom( true );
			note( cMsg, i18n.lvLoading );
			showForms();
			reveal( cForm );

			var res = await request( getPath( 'live/item', { id: id } ), 'GET' );
			if ( token !== loadToken ) {
				return; // مدیر در این فاصله مورد دیگری را باز کرد
			}
			if ( res.ok && res.data && res.data.id ) {
				fillCustom( res.data );
				lockCustom( false );
				note( cMsg, '' );
			} else {
				note( cMsg, errorText( res, i18n.lvLoadFailed ), 'error' );
			}
		}

		function closeForms() {
			loadToken++;
			selected = '';
			editingId = 0;
			showForms();
		}

		cForm.addEventListener( 'submit', async function ( event ) {
			event.preventDefault();
			if ( busy ) {
				return;
			}
			var body = {
				id: editingId,
				name: cForm.elements.live_name.value.trim(),
				description: cForm.elements.live_description.value.trim(),
				content: cForm.elements.live_data.value.trim(),
			};
			if ( ! body.name || ! body.description || ! body.content ) {
				note( cMsg, i18n.lvRequired, 'error' );
				return;
			}

			busy = true;
			loading( saveBtn, true );
			note( cMsg, '' );
			var res = await request( 'live/item', 'POST', body );
			busy = false;
			loading( saveBtn, false );

			if ( res.ok && res.data && res.data.item ) {
				var saved = res.data.item;
				var isNew = ! editingId;
				items = items.filter( function ( item ) {
					return item.id !== saved.id;
				} );
				items.unshift( { id: saved.id, name: saved.name, enabled: saved.enabled, time: saved.time } );
				editingId = saved.id;
				selected = String( saved.id );
				fillCustom( saved );
				renderList();
				note( cMsg, isNew ? i18n.lvAdded : i18n.lvSaved );
			} else {
				note( cMsg, errorText( res, i18n.lvSaveFailed ), 'error' );
			}
		} );

		delBtn.addEventListener( 'click', async function () {
			if ( busy || ! editingId || ! window.confirm( i18n.lvDeleteAsk ) ) {
				return;
			}
			busy = true;
			loading( delBtn, true );
			var id = editingId;
			var res = await request( 'live/item/delete', 'POST', { id: id } );
			busy = false;
			loading( delBtn, false );

			if ( res.ok ) {
				items = items.filter( function ( item ) {
					return item.id !== id;
				} );
				closeForms();
			} else {
				note( cMsg, errorText( res, i18n.lvDeleteFailed ), 'error' );
			}
		} );

		[].forEach.call( wrap.querySelectorAll( '[data-role="live-close"]' ), function ( button ) {
			button.addEventListener( 'click', closeForms );
		} );
		addBtn.addEventListener( 'click', openNew );

		renderList();
	}

	function initExtraction( root ) {
		var blocks = [].slice.call( root.querySelectorAll( '[data-role="extractor"]' ) );
		if ( ! blocks.length ) {
			return;
		}

		var overview = null;
		var dialog = initDialog( root, function () {
			if ( overview ) {
				overview.refresh();
			}
		} );
		var extractors = {};

		blocks.forEach( function ( block ) {
			var mode = block.getAttribute( 'data-mode' );
			extractors[ mode ] = initExtractor( block, function ( added ) {
				if ( overview ) {
					overview.refresh( added );
				}
			} );
		} );

		overview = initOverview( root, extractors, dialog );

		initAutoNew( root, function () {
			if ( overview ) {
				overview.refresh();
			}
		} );

		initRemoved( root, function () {
			if ( overview ) {
				overview.refresh();
			}
		} );

		initPanelLimit( root );
	}

	/**
	 * حداکثر سه فرم هم‌زمان در تب «استخراج اطلاعات سایت»: «خلاصهٔ اطلاعات ثبت‌شده» همیشه می‌ماند و فقط دو فرم دیگر
	 * (اطلاعات جدید خودکار، موارد حذف‌شده، استخراج جدید/اولیه) هم‌زمان باز می‌مانند. وقتی فرم سومی باز شود،
	 * قدیمی‌ترین فرم باز جمع می‌شود و جایش یک نوار «نمایش: …» می‌ماند (چیزی گم نمی‌شود).
	 */
	function initPanelLimit( root ) {
		var MAX_OTHERS = 2;
		var panels = Array.prototype.slice.call( root.querySelectorAll( '[data-role="autonew"], [data-role="removed"], [data-role="extractor"]' ) );
		if ( ! panels.length || ! window.MutationObserver ) {
			return;
		}

		var order = []; // panelها به ترتیب زمان باز شدن (قدیمی‌ترین اول)
		var bars = [];
		var busy = false;

		function titleOf( panel ) {
			var h = panel.querySelector( '.paia-card__title' );
			return h ? h.textContent.replace( /\s+/g, ' ' ).trim() : '';
		}

		function isOpen( panel ) {
			return ! panel.hidden && ! panel.classList.contains( 'paia-folded' );
		}

		function bar( panel ) {
			if ( panel._paiaBar ) {
				return panel._paiaBar;
			}
			var b = el( 'button', { type: 'button', class: 'paia-fold-bar' } );
			b.addEventListener( 'click', function () {
				unfold( panel );
			} );
			panel.parentNode.insertBefore( b, panel );
			panel._paiaBar = b;
			bars.push( b );
			return b;
		}

		function fold( panel ) {
			panel.classList.add( 'paia-folded' );
			var b = bar( panel );
			b.textContent = sprintf( i18n.foldShow, titleOf( panel ) );
			b.hidden = false;
		}

		function unfold( panel ) {
			panel.classList.remove( 'paia-folded' );
			if ( panel._paiaBar ) {
				panel._paiaBar.hidden = true;
			}
			order = order.filter( function ( p ) {
				return p !== panel;
			} );
			order.push( panel );
			enforce();
		}

		function enforce() {
			if ( busy ) {
				return;
			}
			busy = true;
			panels.forEach( function ( panel ) {
				var known = order.indexOf( panel ) !== -1;
				if ( panel.hidden ) {
					order = order.filter( function ( p ) {
						return p !== panel;
					} );
					panel.classList.remove( 'paia-folded' );
					if ( panel._paiaBar ) {
						panel._paiaBar.hidden = true;
					}
				} else if ( ! known ) {
					order.push( panel );
				}
			} );
			var open = order.filter( isOpen );
			while ( open.length > MAX_OTHERS ) {
				fold( open.shift() );
			}
			busy = false;
		}

		var observer = new MutationObserver( enforce );
		panels.forEach( function ( panel ) {
			observer.observe( panel, { attributes: true, attributeFilter: [ 'hidden' ] } );
		} );
		enforce();
	}


	/* ------------------------------------------------------------------ */
	/* خلاصهٔ نتیجه                                                        */
	/* ------------------------------------------------------------------ */

	function sumStats( st ) {
		var totals = {};
		st.items.forEach( function ( item ) {
			var stats = item.stats || {};
			Object.keys( stats ).forEach( function ( key ) {
				totals[ key ] = ( totals[ key ] || 0 ) + ( Number( stats[ key ] ) || 0 );
			} );
		} );
		return totals;
	}

	function chip( text, modifier ) {
		return el( 'li', { class: 'paia-chip' + ( modifier ? ' paia-chip--' + modifier : '' ), text: text } );
	}

	/** opts: { hint, empty } متن زیر عدد هر منبع (پیش‌فرض: «مورد پیدا شد»). */
	function renderSummary( box, state, sources, opts ) {
		opts = opts || {};
		clear( box );

		if ( state.site ) {
			box.appendChild(
				el( 'p', { class: 'paia-summary__site', dir: 'auto' }, [
					el( 'strong', { text: state.site.name || '' } ),
					' — ',
					el( 'span', { dir: 'ltr', text: state.site.url || '' } ),
				] )
			);
		}

		var grid = el( 'div', { class: 'paia-summary' } );
		sources.forEach( function ( id ) {
			var st = state.sources[ id ];
			var stats = sumStats( st );
			var chips = el( 'ul', { class: 'paia-chips' } );
			var labels = config.stats || {};

			Object.keys( stats ).forEach( function ( key ) {
				if ( stats[ key ] > 0 && labels[ key ] && key !== 'empty_content' ) {
					chips.appendChild( chip( formatNumber( stats[ key ] ) + ' ' + labels[ key ] ) );
				}
			} );
			st.taxonomies.forEach( function ( tax ) {
				if ( tax.total > 0 ) {
					chips.appendChild( chip( tax.label + ': ' + formatNumber( tax.total ), 'tax' ) );
				}
			} );
			if ( stats.empty_content > 0 ) {
				chips.appendChild( chip( formatNumber( stats.empty_content ) + ' ' + labels.empty_content, 'warn' ) );
			}
			if ( st.warnings.length ) {
				chips.appendChild( chip( formatNumber( st.warnings.length ) + ' ' + i18n.warnings, 'warn' ) );
			}

			var count = st.items.length;
			grid.appendChild(
				el( 'div', { class: 'paia-stat' + ( st.error ? ' is-error' : '' ) }, [
					el( 'h3', { class: 'paia-stat__name', text: sourceLabel( id ) } ),
					el( 'div', { class: 'paia-stat__value', text: formatNumber( count ) } ),
					el( 'div', { class: 'paia-stat__hint', text: count ? opts.hint || i18n.summaryItems : opts.empty || i18n.summaryNone } ),
					st.error ? el( 'p', { class: 'paia-stat__error', text: sprintf( i18n.sourceFailed, sourceLabel( id ) ) + st.error } ) : null,
					chips.childNodes.length ? chips : null,
					st.duration > 0 ? el( 'div', { class: 'paia-stat__time', text: ( st.duration / 1000 ).toFixed( 1 ) + ' ' + i18n.seconds } ) : null,
				] )
			);
		} );
		box.appendChild( grid );
	}

	/* ------------------------------------------------------------------ */
	/* نمایش اطلاعات استخراج‌شده: درخت تیک‌دار                              */
	/* ------------------------------------------------------------------ */

	function isScalar( value ) {
		return value === null || [ 'string', 'number', 'boolean' ].indexOf( typeof value ) !== -1;
	}

	function scalarNode( value ) {
		if ( typeof value === 'boolean' ) {
			return el( 'span', { text: value ? i18n.yes : i18n.no } );
		}
		var text = String( value );
		if ( /^https?:\/\/\S+$/.test( text ) ) {
			return el( 'a', { href: text, target: '_blank', rel: 'noopener noreferrer', dir: 'ltr', class: 'paia-kv__link', text: text } );
		}
		if ( text.length > 280 || text.indexOf( '\n' ) !== -1 ) {
			return el( 'div', { class: 'paia-long', dir: 'auto' }, [
				el( 'div', { class: 'paia-long__body', text: text } ),
				el( 'small', { class: 'paia-long__meta', text: formatNumber( text.length ) + ' ' + i18n.chars } ),
			] );
		}
		return el( 'span', { dir: 'auto', text: text } );
	}

	function valueNode( value ) {
		if ( isScalar( value ) ) {
			return scalarNode( value );
		}
		if ( Array.isArray( value ) ) {
			if ( value.every( isScalar ) ) {
				var list = el( 'ul', { class: 'paia-chips' } );
				value.forEach( function ( v ) {
					list.appendChild( el( 'li', { class: 'paia-chip', dir: 'auto', text: String( v ) } ) );
				} );
				return list;
			}
			var wrap = el( 'div', { class: 'paia-sublist' } );
			value.forEach( function ( v ) {
				wrap.appendChild( el( 'div', { class: 'paia-sub' }, [ isScalar( v ) ? scalarNode( v ) : valueNode( v ) ] ) );
			} );
			return wrap;
		}
		return objectNode( value );
	}

	function objectNode( obj ) {
		var dl = el( 'dl', { class: 'paia-kv' } );
		Object.keys( obj ).forEach( function ( key ) {
			var value = obj[ key ];
			if ( value === null || value === '' || ( Array.isArray( value ) && ! value.length ) ) {
				return;
			}
			var known = ( config.fields || {} )[ key ];
			dl.appendChild( el( 'dt', { text: known || key, dir: known ? null : 'ltr', title: key } ) );
			dl.appendChild( el( 'dd', {}, [ valueNode( value ) ] ) );
		} );
		return dl;
	}

	function taxonomyNode( tax ) {
		var title = tax.label + ' (' + tax.taxonomy + ') — ' + formatNumber( tax.total );
		var body = el( 'div', { class: 'paia-sub' } );
		if ( tax.truncated ) {
			body.appendChild( el( 'p', { class: 'paia-note', text: i18n.truncated } ) );
		}
		( tax.terms || [] ).forEach( function ( term ) {
			var meta = [];
			if ( term.slug ) {
				meta.push( term.slug );
			}
			meta.push( formatNumber( term.count || 0 ) );
			body.appendChild(
				el( 'div', { class: 'paia-term' }, [
					el( 'strong', { dir: 'auto', text: term.path || term.name } ),
					el( 'small', { dir: 'ltr', text: meta.join( ' · ' ) } ),
					term.description ? el( 'p', { dir: 'auto', text: term.description } ) : null,
				] )
			);
		} );

		return el( 'details', { class: 'paia-item' }, [ el( 'summary', {}, [ el( 'span', { class: 'paia-item__title', dir: 'auto', text: title } ) ] ), body ] );
	}

	function listNode( lines, modifier ) {
		var ul = el( 'ul', { class: 'paia-bullets' + ( modifier ? ' paia-bullets--' + modifier : '' ) } );
		lines.forEach( function ( line ) {
			ul.appendChild( el( 'li', { dir: 'auto', text: line } ) );
		} );
		return ul;
	}

	/**
	 * درخت اطلاعات استخراج‌شده:
	 *   اطلاعات سایت و محیط (یک رکورد)
	 *   هر منبع (محصولات، وبلاگ، برگه‌ها) ← دسته‌بندی‌ها و طبقه‌بندی‌ها (یک رکورد) + هر آیتم (یک رکورد)
	 * هر رکورد یک تیک دارد؛ تیک سربرگ = همهٔ زیرمجموعه‌ها تیک خورده‌اند (اگر فقط بعضی تیک داشته باشند حالت نیمه‌انتخاب).
	 * رکوردی که ai_status آن true است (state.trained) به‌جای تیک، آیکن هوش مصنوعی دارد و انتخاب‌شدنی نیست.
	 * پیش‌فرض: همه تیک‌خورده به‌جز منبع‌هایی که پیش‌فرضشان خاموش است (برگه‌های عمومی)؛ همه گره‌ها بسته‌اند.
	 * شناسهٔ رکوردهای تیک‌خورده با config.getSelectedIds() در دسترس است (برای دکمهٔ آموزش در مرحلهٔ بعد).
	 *
	 * container: عنصری که [data-role="tree"] و [data-role="tree-count"] را دارد. opts: { isNew }
	 */
	function renderTree( container, state, sources, opts ) {
		opts = opts || {};
		var tree = role( container, 'tree' );
		var counter = role( container, 'tree-count' );
		if ( ! tree ) {
			return;
		}
		clear( tree );

		var defaults = config.defaults || {};
		var trained = state.trained || {};
		var selected = {}; // شناسهٔ رکورد => تیک خورده؟
		var leafBoxes = {}; // شناسهٔ رکورد => چک‌باکس‌های رندرشده
		var groups = []; // { box, ids }

		function tick( label ) {
			var box = el( 'input', { type: 'checkbox', class: 'paia-tick', 'aria-label': label } );
			// کلیک روی تیک نباید گره را باز/بسته کند.
			box.addEventListener( 'click', function ( event ) {
				event.stopPropagation();
			} );
			return box;
		}

		/** آیکن هوش مصنوعی به‌جای تیک (processing = true: آیکن loading «در حال پردازش سمت سرور»). */
		function aiBadge( processing ) {
			return aiIcon( !! processing );
		}

		function isProcessing( recordId ) {
			return trained[ recordId ] === 'processing';
		}

		/**
		 * دکمهٔ حذف (سمت چپِ آیتمِ آموزش‌دیده در RTL). فعلاً فقط ظاهری است: بعد از تأیید، آیکن هوش مصنوعی برداشته می‌شود
		 * و به‌جایش تیک می‌آید (آیتم در لیست می‌ماند)؛ ai_status در دیتابیس عمداً دست نمی‌خورد
		 * (بک‌اند هوش مصنوعی بعداً انجام می‌دهد).
		 */
		function removeBtn( title, onConfirm, confirmText, tip ) {
			var btn = el( 'button', { type: 'button', class: 'paia-ai-del', title: tip || i18n.removeAi, 'aria-label': ( tip || i18n.removeAi ) + ': ' + title } );
			btn.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12.2a1.8 1.8 0 0 0 1.8 1.6h6.4a1.8 1.8 0 0 0 1.8-1.6L18 7M9 7V4.5h6V7"/></svg>';
			btn.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				event.stopPropagation();
				if ( window.confirm( confirmText || i18n.removeAiConfirm ) ) {
					onConfirm( btn );
				}
			} );
			return btn;
		}

		var hooks = {}; // شناسهٔ رکورد => تابعی که سربرگ منبع را بعد از حذف آیکن به‌روز می‌کند
		var statusHooks = {}; // شناسهٔ رکورد => تابعی که آیکن سربرگ منبع را (loading/هوش مصنوعی) به‌روز می‌کند
		var leafUpdaters = {}; // شناسهٔ رکورد => تابعی که آیکن همان برگ را بعد از اعلام نتیجهٔ سرور عوض می‌کند
		var leafStarters = {}; // شناسهٔ رکورد => تابعی که برگ را «در حال حذف» (loading) می‌کند

		/** وقتی سرور نتیجهٔ یک رکورد را اعلام کرد (۲ = آموزش‌دیده، ۰ = آموزش‌ندیده/حذف‌شده از دانش): آیکن‌ها و تیک‌ها به‌روز می‌شوند. */
		function onStatus( status, recordId ) {
			trained[ recordId ] = 2 === status;
			if ( 2 !== status ) {
				selected[ recordId ] = true;
			}
			if ( leafUpdaters[ recordId ] ) {
				leafUpdaters[ recordId ]( status );
			}
			if ( 2 !== status && hooks[ recordId ] ) {
				hooks[ recordId ]();
			}
			if ( statusHooks[ recordId ] ) {
				statusHooks[ recordId ]();
			}
			sync();
		}

		/**
		 * «حذف آموزش» واقعی: درخواست به سرور می‌رود (POST extraction/untrain)؛ رکوردهایی که سرور پذیرفت loading می‌شوند
		 * (ai_status = ۱) و با وب‌هوک `untrained` تیک برمی‌گردد (رکورد در سایت می‌ماند و دوباره قابل آموزش است).
		 * @return {Promise<string[]>} شناسهٔ رکوردهای پذیرفته‌شده
		 */
		async function untrainIds( ids ) {
			var accepted = [];
			var error = '';
			for ( var i = 0; i < ids.length; i += 200 ) {
				var res = await request( 'extraction/untrain', 'POST', { ids: ids.slice( i, i + 200 ) } );
				if ( res.ok && res.data ) {
					accepted = accepted.concat( res.data.accepted || [] );
					error = res.data.error || error;
				} else {
					error = ( res.data && res.data.message ) || i18n.untrainFailed;
				}
			}
			accepted.forEach( function ( recordId ) {
				trained[ recordId ] = 'processing';
				selected[ recordId ] = false;
				if ( leafStarters[ recordId ] ) {
					leafStarters[ recordId ]();
				}
				if ( statusHooks[ recordId ] ) {
					statusHooks[ recordId ]();
				}
			} );
			watchStatus( accepted, onStatus );
			sync();
			if ( error && accepted.length < ids.length ) {
				window.alert( error );
			}
			return accepted;
		}

		function selectedIds() {
			return Object.keys( selected ).filter( function ( id ) {
				return selected[ id ];
			} );
		}

		function sync() {
			Object.keys( leafBoxes ).forEach( function ( id ) {
				leafBoxes[ id ].forEach( function ( box ) {
					box.checked = !! selected[ id ];
				} );
			} );
			groups.forEach( function ( group ) {
				var on = group.ids.filter( function ( id ) {
					return selected[ id ];
				} ).length;
				group.box.disabled = group.ids.length === 0;
				group.box.checked = on > 0 && on === group.ids.length;
				group.box.indeterminate = on > 0 && on < group.ids.length;
			} );
			var count = selectedIds().length;
			if ( counter ) {
				counter.textContent = count ? sprintf( i18n.treeSelected, formatNumber( count ) ) : i18n.treeNone;
			}
		}

		config.getSelectedIds = selectedIds;
		container._paiaSelected = selectedIds;

		function newBadge() {
			return opts.isNew ? el( 'span', { class: 'paia-chip paia-chip--new', text: i18n.badgeNew } ) : null;
		}

		/** گرهٔ برگ (یک رکورد): سربرگ با تیک (یا آیکن هوش مصنوعی) + بدنهٔ بسته که با اولین باز شدن ساخته می‌شود. */
		function leafNode( recordId, title, chips, buildBody ) {
			var head = [];
			var summary = el( 'summary' );
			var badge = null;
			var del = null;

			function addTick() {
				var box = tick( title );
				box.addEventListener( 'change', function () {
					selected[ recordId ] = box.checked;
					sync();
				} );
				( leafBoxes[ recordId ] = leafBoxes[ recordId ] || [] ).push( box );
				if ( ! ( recordId in selected ) ) {
					selected[ recordId ] = true;
				}
				return box;
			}

			if ( recordId ) {
				if ( trained[ recordId ] ) {
					badge = aiBadge( isProcessing( recordId ) );
					head.push( badge );
				} else {
					head.push( addTick() );
				}
			}
			head.push( el( 'span', { class: 'paia-item__title', dir: 'auto', text: title } ) );
			( chips || [] ).forEach( function ( c ) {
				head.push( c );
			} );
			function makeDel() {
				return removeBtn( title, function ( btn ) {
					// درخواست حذف آموزش به سرور می‌رود؛ تا پذیرفتن سرور دکمه loading است، بعد آیکن loading می‌شود (leafStarters).
					btn.classList.add( 'is-loading' );
					btn.disabled = true;
					untrainIds( [ recordId ] ).then( function ( accepted ) {
						if ( accepted.indexOf( recordId ) === -1 && btn.parentNode ) {
							btn.classList.remove( 'is-loading' );
							btn.disabled = false;
						}
					} );
				} );
			}
			// دکمهٔ حذف فقط برای پردازش‌شده‌ها؛ «در حال پردازش» هنوز چیزی در دانش هوش مصنوعی نیست.
			if ( badge && ! isProcessing( recordId ) ) {
				del = makeDel();
				head.push( del );
			}

			// اعلام نتیجهٔ سرور (از watchStatus): loading ← آیکن هوش مصنوعی (آموزش) یا تیک (حذف آموزش/شکست).
			if ( recordId ) {
				leafUpdaters[ recordId ] = function ( status ) {
					if ( ! badge ) {
						return;
					}
					if ( 2 === status ) {
						var done = aiBadge( false );
						summary.replaceChild( done, badge );
						badge = done;
						del = makeDel();
						summary.appendChild( del );
					} else {
						var box = addTick();
						box.checked = true;
						summary.replaceChild( box, badge );
						badge = null;
					}
				};
				// «حذف آموزش» پذیرفته شد: آیکن هوش مصنوعی ← loading و دکمهٔ حذف برداشته می‌شود.
				leafStarters[ recordId ] = function () {
					if ( ! badge ) {
						return;
					}
					var wait = aiBadge( true );
					summary.replaceChild( wait, badge );
					badge = wait;
					if ( del && del.parentNode ) {
						del.parentNode.removeChild( del );
					}
					del = null;
				};
			}
			head.forEach( function ( n ) {
				if ( n ) {
					summary.appendChild( n );
				}
			} );

			var details = el( 'details', { class: 'paia-item' }, [ summary ] );
			details.addEventListener( 'toggle', function () {
				if ( details.open && ! details.getAttribute( 'data-ready' ) ) {
					details.setAttribute( 'data-ready', '1' );
					details.appendChild( buildBody() );
				}
			} );
			return details;
		}

		function itemNode( item, recordId ) {
			var stats = item.stats || {};
			var chips = [];
			if ( item.id ) {
				chips.push( el( 'span', { class: 'paia-item__id', text: '#' + item.id } ) );
			}
			if ( stats.empty_content ) {
				chips.push( el( 'span', { class: 'paia-chip paia-chip--warn', text: i18n.emptyContent } ) );
			} else if ( stats.words ) {
				chips.push( el( 'span', { class: 'paia-chip', text: formatNumber( stats.words ) + ' ' + ( ( config.stats || {} ).words || '' ) } ) );
			}
			chips.push( newBadge() );

			return leafNode( recordId, item.title || '#' + item.id, chips, function () {
				return objectNode( item );
			} );
		}

		function itemsNode( st ) {
			var box = el( 'div', { class: 'paia-items' } );
			var page = 50;
			var shown = 0;
			var more = el( 'button', { type: 'button', class: 'paia-link' } );

			function showMore() {
				st.items.slice( shown, shown + page ).forEach( function ( item, offset ) {
					box.insertBefore( itemNode( item, st.recordIds[ shown + offset ] || '' ), more );
				} );
				shown = Math.min( st.items.length, shown + page );
				if ( shown >= st.items.length ) {
					more.hidden = true;
				} else {
					more.textContent = sprintf( i18n.showMore, formatNumber( Math.min( page, st.items.length - shown ) ) );
				}
			}

			more.addEventListener( 'click', showMore );
			box.appendChild( more );
			showMore();
			return box;
		}

		/** منبع (محصولات، وبلاگ، برگه‌ها): تیک سربرگ (یا آیکن هوش مصنوعی) + دسته‌بندی‌ها + آیتم‌ها. */
		function sourceNode( id ) {
			var st = state.sources[ id ];
			var title = sourceLabel( id );
			var on = opts.isNew ? true : !! defaults[ id ];
			var all = [];
			var ids = []; // فقط رکوردهای انتخاب‌شدنی (آموزش‌ندیده)

			if ( st.taxonomies.length && st.taxonomiesRecord ) {
				all.push( st.taxonomiesRecord );
			}
			st.recordIds.forEach( function ( recordId ) {
				if ( recordId ) {
					all.push( recordId );
				}
			} );
			all.forEach( function ( recordId ) {
				if ( ! trained[ recordId ] ) {
					ids.push( recordId );
					selected[ recordId ] = on;
				}
			} );

			var lead;
			var leadIsBadge = false;
			var summaryEl = null;

			function makeTickLead() {
				var box = tick( title );
				box.addEventListener( 'change', function () {
					ids.forEach( function ( recordId ) {
						selected[ recordId ] = box.checked;
					} );
					sync();
				} );
				groups.push( { box: box, ids: ids } );
				return box;
			}

			var leadProcessing = false;
			function anyProcessing() {
				return all.some( function ( recordId ) {
					return isProcessing( recordId );
				} );
			}

			if ( all.length && ! ids.length ) {
				// همهٔ رکوردهای این منبع آموزش دیده‌اند (یا در حال پردازش سمت سرور)
				leadProcessing = anyProcessing();
				lead = aiBadge( leadProcessing );
				leadIsBadge = true;
			} else {
				lead = makeTickLead();
			}

			// سربرگ منبع: تا وقتی حتی یک رکورد در حال پردازش است loading؛ وقتی همه تمام شدند آیکن هوش مصنوعی.
			function updateLead() {
				if ( ! leadIsBadge || ! summaryEl ) {
					return;
				}
				var processing = anyProcessing();
				if ( processing === leadProcessing ) {
					return;
				}
				var next = aiBadge( processing );
				summaryEl.replaceChild( next, lead );
				lead = next;
				leadProcessing = processing;
			}

			// وقتی آیکن هوش مصنوعیِ یک آیتم برداشته شد (فقط ظاهری)، آن آیتم جزو رکوردهای انتخاب‌شدنی منبع می‌شود.
			// دکمهٔ «حذف آموزش» سربرگ منبع (مثلاً کل محصولات یا کل وبلاگ): وقتی حداقل یک رکورد پردازش‌شده دارد دیده می‌شود.
			var srcDel = null;
			function doneIds() {
				return all.filter( function ( recordId ) {
					return trained[ recordId ] === true;
				} );
			}
			function refreshDel() {
				if ( ! summaryEl ) {
					return;
				}
				var count = doneIds().length;
				if ( count && ! srcDel ) {
					srcDel = removeBtn(
						title,
						function ( btn ) {
							btn.classList.add( 'is-loading' );
							btn.disabled = true;
							untrainIds( doneIds() ).then( function ( accepted ) {
								if ( ! accepted.length && btn.parentNode ) {
									btn.classList.remove( 'is-loading' );
									btn.disabled = false;
								}
							} );
						},
						sprintf( i18n.removeSrcAiConfirm, formatNumber( count ) ),
						i18n.removeSrcAi
					);
					summaryEl.appendChild( srcDel );
				} else if ( ! count && srcDel ) {
					if ( srcDel.parentNode ) {
						srcDel.parentNode.removeChild( srcDel );
					}
					srcDel = null;
				}
			}

			all.forEach( function ( recordId ) {
				statusHooks[ recordId ] = function () {
					updateLead();
					refreshDel();
				};
				hooks[ recordId ] = function () {
					if ( ids.indexOf( recordId ) === -1 ) {
						ids.push( recordId );
					}
					if ( leadIsBadge && summaryEl ) {
						var box = makeTickLead();
						summaryEl.replaceChild( box, lead );
						lead = box;
						leadIsBadge = false;
					}
				};
			} );

			var body = el( 'div', { class: 'paia-node__body' } );
			if ( st.error ) {
				body.appendChild( listNode( [ sprintf( i18n.sourceFailed, title ) + st.error ], 'error' ) );
			}
			if ( st.warnings.length ) {
				body.appendChild( el( 'p', { class: 'paia-note', text: i18n.warningsTitle + ' (' + formatNumber( st.warnings.length ) + ')' } ) );
				body.appendChild( listNode( st.warnings, 'warn' ) );
			}
			if ( st.taxonomies.length ) {
				body.appendChild(
					leafNode( st.taxonomiesRecord, i18n.taxTitle + ' (' + formatNumber( st.taxonomies.length ) + ')', [], function () {
						return el( 'div', { class: 'paia-sub' }, st.taxonomies.map( taxonomyNode ) );
					} )
				);
			}
			if ( st.items.length ) {
				body.appendChild( el( 'p', { class: 'paia-note', text: i18n.itemsTitle + ' (' + formatNumber( st.items.length ) + ')' } ) );
				body.appendChild( itemsNode( st ) );
			} else if ( ! st.error ) {
				body.appendChild( el( 'p', { class: 'paia-note', text: i18n.noItems } ) );
			}

			summaryEl = el( 'summary', {}, [
				lead,
				el( 'span', { class: 'paia-item__title', text: title } ),
				el( 'span', { class: 'paia-chip', text: formatNumber( st.items.length ) } ),
				newBadge(),
			] );
			refreshDel();

			return el( 'details', { class: 'paia-node' }, [ summaryEl, body ] );
			}

		if ( state.site ) {
			tree.appendChild(
				leafNode( state.siteRecord, i18n.siteTitle, [], function () {
					return objectNode( state.site );
				} )
			);
		}

		sources.forEach( function ( id ) {
			tree.appendChild( sourceNode( id ) );
		} );

		sync();

		/**
		 * رکوردهای «در حال پردازش سمت سرور»: وقتی سرور نتیجه را اعلام کرد (ai_status ۲) آیکن loading به آیکن هوش مصنوعی
		 * تبدیل می‌شود؛ اگر پردازش شکست خورد (۰) تیک برمی‌گردد. فقط برگ‌های رندرشده DOM دارند؛ بقیه فقط وضعیتشان به‌روز می‌شود.
		 */
		watchStatus(
			Object.keys( trained ).filter( function ( recordId ) {
				return isProcessing( recordId );
			} ),
			onStatus
			);
			}

	/* ------------------------------------------------------------------ */



	/* ------------------------------------------------------------------ */
	/* تب «تنظیمات»: خاکستری‌شدن رنگ‌های تم دارک وقتی فعال نیست (فقط ظاهر) */
	/* ------------------------------------------------------------------ */

	function initDarkGroup() {
		var group = document.querySelector( '[data-role="dark-group"]' );
		var toggle = group ? group.querySelector( '[data-role="dark-toggle"]' ) : null;
		if ( ! group || ! toggle ) {
			return;
		}
		function sync() {
			group.classList.toggle( 'is-disabled', ! toggle.checked );
		}
		toggle.addEventListener( 'change', sync );
		sync();
	}

	/* ------------------------------------------------------------------ */
	/* وضعیت چت‌بات (تب «عمومی»): روشن/خاموش‌کردن کل چت‌بات                */
	/* ------------------------------------------------------------------ */

	/* ------------------------------------------------------------------ */
	/* وضعیت اشتراک (تب «عمومی»): دکمهٔ «همگام‌سازی با سرور»                */
	/* ------------------------------------------------------------------ */

	function initSubscription( root ) {
		var box = root.querySelector( '[data-role="subscription"]' );
		var btn = box ? box.querySelector( '[data-role="sub-sync"]' ) : null;
		if ( ! box || ! btn ) {
			return;
		}
		var foot = box.querySelector( '[data-role="sub-foot"]' );
		var busy = false;

		function field( name ) {
			return box.querySelector( '[data-sub="' + name + '"]' );
		}

		function apply( d ) {
			[ 'plan', 'remaining', 'expires', 'tokens_used', 'tokens_total', 'tokens_left' ].forEach( function ( key ) {
				var node = field( key );
				if ( node && d[ key ] !== undefined ) {
					node.textContent = d[ key ];
				}
			} );
			field( 'time_bar' ).style.width = ( d.time_pct || 0 ) + '%';
			field( 'tokens_bar' ).style.width = ( d.tokens_pct || 0 ) + '%';
			field( 'demo' ).hidden = ! d.demo;
			box.classList.toggle( 'is-expired', !! d.expired );
			foot.textContent = sprintf( i18n.subSynced, d.synced_label || '' );
		}

		btn.addEventListener( 'click', async function () {
			if ( busy ) {
				return;
			}
			busy = true;
			btn.disabled = true;
			btn.classList.add( 'is-loading' );
			foot.textContent = i18n.subSyncing;
			var res = await request( 'subscription/sync', 'POST', {} );
			busy = false;
			btn.disabled = false;
			btn.classList.remove( 'is-loading' );
			if ( res.ok && res.data && res.data.synced ) {
				apply( res.data );
			} else {
				foot.textContent = ( res.data && res.data.message ) || i18n.subFailed;
			}
		} );
	}

	function initStatus( root ) {
		var box = root.querySelector( '[data-role="status"]' );
		var toggle = box ? box.querySelector( '[data-role="status-toggle"]' ) : null;
		if ( ! box || ! toggle ) {
			return;
		}
		var label = box.querySelector( '[data-role="status-label"]' );
		var msg = box.querySelector( '[data-role="status-msg"]' );

		function show( text, bad ) {
			if ( ! msg ) {
				return;
			}
			msg.textContent = text || '';
			msg.hidden = ! text;
			msg.classList.toggle( 'is-bad', !! bad );
		}

		/** ظاهر کلید + قفل‌شدن آنیِ تب‌ها (بدون منتظر ماندن برای سرور). */
		function paint( on ) {
			toggle.checked = on;
			box.classList.toggle( 'is-off', ! on );
			root.classList.toggle( 'is-off', ! on );
			if ( label ) {
				label.textContent = label.getAttribute( on ? 'data-on' : 'data-off' ) || '';
			}
			[].slice.call( root.querySelectorAll( '[role="tab"]' ) ).forEach( function ( tab ) {
				if ( tab.getAttribute( 'data-tab' ) === 'general' ) {
					return;
				}
				tab.classList.toggle( 'is-locked', ! on );
				if ( on ) {
					tab.removeAttribute( 'aria-disabled' );
				} else {
					tab.setAttribute( 'aria-disabled', 'true' );
				}
			} );
		}

		toggle.addEventListener( 'change', async function () {
			var want = toggle.checked;
			var before = ! want;

			if ( ! want && ! window.confirm( i18n.stOffConfirm || '' ) ) {
				toggle.checked = true;
				return;
			}

			// اعمال آنی + loading؛ تا پایان ذخیره کلید قفل است.
			paint( want );
			toggle.disabled = true;
			box.classList.add( 'is-busy' );
			show( i18n.stSaving, false );

			var res = await request( 'status', 'POST', { enabled: want } );

			if ( res.ok && res.data && typeof res.data.enabled !== 'undefined' && !! res.data.enabled === want ) {
				show( want ? i18n.stOnDone : i18n.stOffDone, false );
				// بارگذاری دوباره: ماژول‌های چت‌بات با وضعیت جدید ثبت/حذف می‌شوند و تب‌ها از سرور قفل/باز می‌شوند.
				window.location.hash = '#general';
				window.location.reload();
				return;
			}

			// شکست: برگشت به حالت قبل.
			paint( before );
			toggle.disabled = false;
			box.classList.remove( 'is-busy' );
			show( ( res.data && res.data.message ) || i18n.stFailed || '', true );
		} );
	}

	/* ------------------------------------------------------------------ */

	document.addEventListener( 'DOMContentLoaded', function () {
		var root = document.getElementById( 'paia-app' );
		if ( ! root ) {
			return;
		}
		initTabs( root );
		initStatus( root );
		initSubscription( root );

		// چت‌بات خاموش: محتوای تب‌ها ساخته نشده؛ هیچ بخش دیگری راه‌اندازی نمی‌شود.
		if ( config.enabled === false ) {
			return;
		}

		initSettingsNav();
		initDarkGroup();
		initAutoSync();
		initExtraction( root );
		initDocuments();
		initServerLog();
		initLive();
	} );
} )();
