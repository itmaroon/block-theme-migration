/**
 * 点検の画面（ツール → Theme Migration → Inspection）のブラウザー側の処理。
 *
 * ブロックの有効性を、ブロックエディター自身の検証（wp.blocks.parse）で検査する。
 * 検査は、サーバーが返す全項目（テンプレート、パーツ、テーマのパターン、同期パターン）について、
 * 1項目ずつ行い、処理中の表示を出す（大きなテーマでは時間がかかるため）。
 *
 * 要件: docs/requirements-v0.2.md の VL-1、VL-4、D11。
 */
( function () {
	'use strict';

	var cfg = window.itmarBtmInspect || {};
	var i18n = cfg.i18n || {};
	var byId = function ( id ) {
		return document.getElementById( id );
	};
	var runBtn = byId( 'itmar-btm-run' );
	var dlBtn = byId( 'itmar-btm-download' );
	var progress = byId( 'itmar-btm-progress' );
	var result = byId( 'itmar-btm-result' );
	var reportEl = byId( 'itmar-btm-report' );
	if ( ! runBtn || ! reportEl ) {
		return;
	}
	var report = JSON.parse( reportEl.textContent );
	var validation = null;

	/** %s、%d、%o を、順に引数で置き換える（メッセージの整形用） */
	function format( template, args ) {
		var i = 0;
		return String( template ).replace( /%[sdo]/g, function () {
			var v = args[ i++ ];
			if ( v && typeof v === 'object' ) {
				try {
					return JSON.stringify( v );
				} catch ( e ) {
					return '[object]';
				}
			}
			return String( v );
		} );
	}

	/** i18n の文字列の %1$d などを、引数で置き換える */
	function sprintf( template, values ) {
		return String( template ).replace( /%(\d+)\$[sd]/g, function ( m, n ) {
			return values[ Number( n ) - 1 ];
		} );
	}

	function tick() {
		return new Promise( function ( resolve ) {
			window.setTimeout( resolve, 0 );
		} );
	}

	var coreRegistered = false;
	function prepareBlocks() {
		if ( ! window.wp || ! wp.blocks || ! wp.apiFetch ) {
			return false;
		}
		if ( ! coreRegistered && wp.blockLibrary && wp.blockLibrary.registerCoreBlocks ) {
			try {
				wp.blockLibrary.registerCoreBlocks();
			} catch ( e ) {
				// すでに登録されている場合は、そのまま続ける
			}
			coreRegistered = true;
		}
		return true;
	}

	/** parse が出すコンソールの警告（無効なブロックごとに大量に出る）を、検査中だけ止める */
	function quietly( fn ) {
		var saved = { error: console.error, warn: console.warn, log: console.log };
		console.error = console.warn = console.log = function () {};
		try {
			return fn();
		} finally {
			console.error = saved.error;
			console.warn = saved.warn;
			console.log = saved.log;
		}
	}

	function inspectItem( item ) {
		var out = { key: item.key, kind: item.kind, title: item.title, blocks: 0, invalid: [], unregistered: [] };
		var blocks = quietly( function () {
			return wp.blocks.parse( item.content );
		} );
		( function walk( list, path ) {
			list.forEach( function ( block, index ) {
				var here = path.concat( index );
				if ( ! block.name ) {
					return;
				}
				out.blocks++;
				var missing = block.name === 'core/missing' || ! wp.blocks.getBlockType( block.name );
				if ( missing ) {
					out.unregistered.push( { name: block.attributes && block.attributes.originalName ? block.attributes.originalName : block.name, path: here.join( '>' ) } );
				} else if ( block.isValid === false ) {
					var issue = ( block.validationIssues || [] )[ 0 ];
					var message = '';
					if ( issue && issue.args && issue.args.length ) {
						message = format( issue.args[ 0 ], issue.args.slice( 1 ) );
					}
					out.invalid.push( { name: block.name, path: here.join( '>' ), message: message.slice( 0, 400 ) } );
				}
				if ( block.innerBlocks && block.innerBlocks.length ) {
					walk( block.innerBlocks, here );
				}
			} );
		} )( blocks, [] );
		return out;
	}

	function el( tag, text, attrs ) {
		var node = document.createElement( tag );
		if ( text !== undefined && text !== null ) {
			node.textContent = text;
		}
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( k ) {
				node.setAttribute( k, attrs[ k ] );
			} );
		}
		return node;
	}

	function render( results, totals ) {
		result.textContent = '';
		var problems = results.filter( function ( r ) {
			return r.invalid.length || r.unregistered.length;
		} );
		var summary = sprintf( i18n.done || '', [ results.length, totals.blocks, totals.invalid, totals.unregistered ] );
		var box = el( 'div', null, { class: 'notice inline notice-' + ( problems.length ? 'error' : 'success' ) } );
		box.appendChild( el( 'p', problems.length ? summary : summary + ' ' + ( i18n.allValid || '' ) ) );
		result.appendChild( box );
		if ( ! problems.length ) {
			return;
		}
		var table = el( 'table', null, { class: 'widefat striped itmar-btm-table', style: 'max-width:1100px' } );
		var head = el( 'tr' );
		[ i18n.item, i18n.blocks, i18n.invalid, i18n.unregistered, i18n.details ].forEach( function ( label ) {
			head.appendChild( el( 'th', label ) );
		} );
		table.appendChild( el( 'thead' ) ).appendChild( head );
		var body = el( 'tbody' );
		problems.forEach( function ( r ) {
			var row = el( 'tr' );
			row.appendChild( el( 'td', r.title + ' (' + r.key + ')' ) );
			row.appendChild( el( 'td', String( r.blocks ) ) );
			row.appendChild( el( 'td', String( r.invalid.length ) ) );
			row.appendChild( el( 'td', String( r.unregistered.length ) ) );
			var cell = el( 'td' );
			var details = el( 'details' );
			details.appendChild( el( 'summary', i18n.details ) );
			var list = el( 'ul', null, { style: 'list-style:disc;margin-left:1.5em' } );
			r.invalid.slice( 0, 30 ).forEach( function ( b ) {
				list.appendChild( el( 'li', b.name + ' [' + b.path + '] ' + b.message ) );
			} );
			r.unregistered.slice( 0, 30 ).forEach( function ( b ) {
				list.appendChild( el( 'li', ( i18n.unregistered || '' ) + ': ' + b.name + ' [' + b.path + ']' ) );
			} );
			details.appendChild( list );
			cell.appendChild( details );
			row.appendChild( cell );
			body.appendChild( row );
		} );
		table.appendChild( body );
		result.appendChild( table );
	}

	function setProgress( text, spinning ) {
		progress.textContent = '';
		if ( spinning ) {
			progress.appendChild( el( 'span', null, { class: 'itmar-btm-spin' } ) );
		}
		progress.appendChild( document.createTextNode( text ) );
	}

	async function run() {
		if ( ! prepareBlocks() ) {
			setProgress( i18n.noEditor || '', false );
			return;
		}
		runBtn.disabled = true;
		setProgress( i18n.running || '', true );
		try {
			var items = await wp.apiFetch( { path: '/itmar-btm/v1/items' } );
			var results = [];
			var totals = { blocks: 0, invalid: 0, unregistered: 0 };
			for ( var i = 0; i < items.length; i++ ) {
				setProgress( sprintf( i18n.progress || '', [ i + 1, items.length, items[ i ].key ] ), true );
				await tick();
				var r = inspectItem( items[ i ] );
				results.push( r );
				totals.blocks += r.blocks;
				totals.invalid += r.invalid.length;
				totals.unregistered += r.unregistered.length;
			}
			validation = { checkedAt: new Date().toISOString(), totals: totals, items: results };
			render( results, totals );
			setProgress( '', false );
		} catch ( e ) {
			setProgress( ( e && e.message ) || String( e ), false );
		} finally {
			runBtn.disabled = false;
		}
	}

	function download() {
		var data = { report: report, validation: validation };
		var blob = new Blob( [ JSON.stringify( data, null, 2 ) ], { type: 'application/json' } );
		var a = document.createElement( 'a' );
		a.href = URL.createObjectURL( blob );
		a.download = 'theme-migration-report-' + ( report.site && report.site.theme ? report.site.theme : 'site' ) + '.json';
		document.body.appendChild( a );
		a.click();
		document.body.removeChild( a );
		window.setTimeout( function () {
			URL.revokeObjectURL( a.href );
		}, 1000 );
	}

	runBtn.addEventListener( 'click', run );
	if ( dlBtn ) {
		dlBtn.addEventListener( 'click', download );
	}
}() );
