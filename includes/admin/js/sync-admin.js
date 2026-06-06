jQuery( document ).ready( function ( $ ) {
	var data = FreemKitSync || {};
	var $form        = $( '#freemkit-sync-form' );
	var $progress    = $( '#freemkit-sync-progress' );
	var $bar         = $( '#freemkit-progress-bar-inner' );
	var $statusText  = $( '#freemkit-progress-text' );
	var $results     = $( '#freemkit-sync-results' );
	var $tbody       = $( '#freemkit-results-tbody' );
	var $summary     = $( '#freemkit-sync-summary' );
	var $submitBtn   = $( '#freemkit_sync_submit' );
	var $cancelBtn   = $( '#freemkit-sync-cancel' );
	var $kitFields   = $( '#freemkit-kit-fields' );

	var isCancelled  = false;
	var totalKnown   = 0;
	var totalFetched = 0;

	// Toggle Kit-specific fields when destination changes.
	$( 'input[name="sync_destination"]' ).on( 'change', function () {
		var dest = $( 'input[name="sync_destination"]:checked' ).val();
		if ( 'local' === dest ) {
			$kitFields.find( 'tr' ).hide();
		} else {
			$kitFields.find( 'tr' ).show();
		}
	} );

	$form.on( 'submit', function ( e ) {
		e.preventDefault();

		isCancelled  = false;
		totalKnown   = 0;
		totalFetched = 0;

		var source          = $( 'input[name="sync_source"]:checked' ).val() || 'freemius';
		var destination     = $( 'input[name="sync_destination"]:checked' ).val() || 'both';
		var pluginId        = $( '#freemkit-plugin-id' ).val() || '';
		var overrideFormIds = ( 'local' !== destination ) ? getInputValue( '#freemkit-override-form-ids' ) : '';
		var overrideTagIds  = ( 'local' !== destination ) ? getInputValue( '#freemkit-override-tag-ids' ) : '';
		var userTypes       = [];

		$( 'input[name="sync_user_types[]"]:checked' ).each( function () {
			userTypes.push( $( this ).val() );
		} );

		// Reset UI.
		$progress.show();
		$bar.css( 'width', '0%' );
		$statusText.text( data.strings.fetching );
		$results.show();
		$tbody.empty();
		$summary.hide().text( '' );
		$submitBtn.prop( 'disabled', true );
		$cancelBtn.show();

		var context = {
			source:          source,
			destination:     destination,
			pluginId:        pluginId,
			userTypes:       userTypes,
			overrideFormIds: overrideFormIds,
			overrideTagIds:  overrideTagIds
		};

		var counts = { processed: 0, synced: 0, updated: 0, skipped: 0, errors: 0 };

		fetchPage( context, 0, 0, counts );
	} );

	$cancelBtn.on( 'click', function () {
		isCancelled = true;
		$statusText.text( data.strings.cancelled );
		$cancelBtn.hide();
		$submitBtn.prop( 'disabled', false );
	} );

	/**
	 * Read the current value from a text input or tom-select instance.
	 * Tom Select stores the comma-joined value back on the original input.
	 */
	function getInputValue( selector ) {
		var $el = $( selector );
		if ( $el.length && $el[0].tomselect ) {
			return $el[0].tomselect.getValue();
		}
		return $el.val() || '';
	}

	/**
	 * Phase 1 (repeated): fetch one page of tasks then kick off processQueue.
	 *
	 * @param {object} context      Sync options (source, destination, …).
	 * @param {number} offset       Pagination offset within the current plugin.
	 * @param {number} pluginIndex  Plugin index used when source=freemius and plugin_id is empty.
	 * @param {object} counts       Running tallies.
	 */
	function fetchPage( context, offset, pluginIndex, counts ) {
		if ( isCancelled ) {
			return;
		}

		$.ajax( {
			url:  data.ajax_url,
			type: 'POST',
			data: {
				action:       'freemkit_sync_list_users',
				nonce:        data.nonce,
				source:       context.source,
				plugin_id:    context.pluginId,
				plugin_index: pluginIndex,
				user_types:   context.userTypes,
				offset:       offset,
				count:        50
			},
			success: function ( response ) {
				if ( ! response || ! response.success ) {
					showError( ( response && response.data && response.data.message ) || data.strings.fetch_error );
					return;
				}

				var result          = response.data;
				var tasks           = result.tasks || [];
				var hasMore         = result.has_more || false;
				var nextOffset      = ( undefined !== result.offset ) ? result.offset : ( offset + tasks.length );
				var nextPluginIndex = ( undefined !== result.next_plugin_index ) ? result.next_plugin_index : 0;

				if ( 0 === offset && 0 === counts.processed && 0 === tasks.length && ! hasMore ) {
					showError( data.strings.no_users );
					return;
				}

				// For local source, the server returns a known total.
				if ( result.total && result.total > 0 ) {
					totalKnown = result.total;
				}

				totalFetched += tasks.length;

				// Attach form-level options so process_one can use them.
				tasks = tasks.map( function ( task ) {
					task.destination       = context.destination;
					task.override_form_ids = context.overrideFormIds;
					task.override_tag_ids  = context.overrideTagIds;
					task.user_types        = context.userTypes;
					// plugin_name comes from the server; ensure it's present.
					task.plugin_name       = task.plugin_name || task.plugin_id || '';
					return task;
				} );

				processQueue( tasks, hasMore, nextOffset, nextPluginIndex, counts, context );
			},
			error: function () {
				showError( data.strings.fetch_error );
			}
		} );
	}

	/**
	 * Phase 2: process the whole batch in one AJAX call, then fetch the next page.
	 */
	function processQueue( tasks, hasMore, nextOffset, nextPluginIndex, counts, context ) {
		if ( isCancelled ) {
			return;
		}

		if ( tasks.length === 0 ) {
			if ( hasMore ) {
				$statusText.text( data.strings.fetching_more );
				fetchPage( context, nextOffset, nextPluginIndex, counts );
			} else {
				finishSync( counts );
			}
			return;
		}

		if ( totalKnown > 0 ) {
			var pct = Math.round( ( counts.processed / totalKnown ) * 100 );
			$bar.css( 'width', pct + '%' );
		}

		$statusText.text(
			( data.strings.processing || 'Processing' ) + ' ' +
			( counts.processed + 1 ) + ' - ' + ( counts.processed + tasks.length ) +
			( totalKnown > 0 ? ' / ' + totalKnown : '' )
		);

		$.ajax( {
			url:  data.ajax_url,
			type: 'POST',
			data: {
				action: 'freemkit_sync_process_batch',
				nonce:  data.nonce,
				tasks:  tasks
			},
			success: function ( response ) {
				if ( response && response.success && response.data && response.data.results ) {
					response.data.results.forEach( function ( result ) {
						counts.processed++;
						appendRow( result );
						if ( 'synced' === result.action )       { counts.synced++; }
						else if ( 'updated' === result.action ) { counts.updated++; }
						else if ( 'skipped' === result.action )   { counts.skipped++; }
						else if ( 'error' === result.action )     { counts.errors++; }
					} );
				} else {
					var msg = ( response && response.data && response.data.message ) || data.strings.process_error;
					tasks.forEach( function ( task ) {
						counts.processed++;
						appendErrorRow( task.email, msg );
						counts.errors++;
					} );
				}

				if ( hasMore ) {
					$statusText.text( data.strings.fetching_more );
					fetchPage( context, nextOffset, nextPluginIndex, counts );
				} else {
					finishSync( counts );
				}
			},
			error: function () {
				tasks.forEach( function ( task ) {
					counts.processed++;
					counts.errors++;
					appendErrorRow( task.email, data.strings.request_failed );
				} );

				if ( hasMore ) {
					$statusText.text( data.strings.fetching_more );
					fetchPage( context, nextOffset, nextPluginIndex, counts );
				} else {
					finishSync( counts );
				}
			}
		} );
	}

	function finishSync( counts ) {
		$bar.css( 'width', '100%' );
		$statusText.text( data.strings.done );
		$cancelBtn.hide();
		$submitBtn.prop( 'disabled', false );

		var summaryText = ( data.strings.summary || 'Processed: {processed} • Synced: {synced} • Updated: {updated} • Skipped: {skipped} • Errors: {errors}' )
			.replace( '{processed}', counts.processed )
			.replace( '{synced}',    counts.synced )
			.replace( '{updated}',   counts.updated )
			.replace( '{skipped}',   counts.skipped )
			.replace( '{errors}',    counts.errors );

		$summary.show().text( summaryText );
	}

	function appendRow( result ) {
		var $row  = $( '<tr></tr>' );
		var notes = result.error || '';

		if ( 'error' === result.action ) {
			$row.css( 'background-color', '#fce8e8' );
		} else if ( 'skipped' === result.action ) {
			$row.css( 'background-color', '#fff3cd' );
		}

		var notesCell = notes
			? escHtml( notes )
			: '<span style="color:#008a20;font-weight:600;">OK</span>';

		var destLabel = result.destination || '';
		var destCell  = 'local' === destLabel
			? 'Local DB only'
			: ( 'Local + Kit' + ( result.forms ? ': ' + escHtml( result.forms ) : '' ) );

		$row.append( '<td>' + escHtml( result.action || '' ) + '</td>' );
		$row.append( '<td>' + escHtml( result.email || '' ) + '</td>' );
		$row.append( '<td>' + escHtml( trim( ( result.first_name || '' ) + ' ' + ( result.last_name || '' ) ) ) + '</td>' );
		$row.append( '<td>' + escHtml( result.user_type || '' ) + '</td>' );
		$row.append( '<td>' + escHtml( result.plugin_name || '' ) + '</td>' );
		$row.append( '<td>' + destCell + '</td>' );
		$row.append( '<td>' + notesCell + '</td>' );
		$tbody.append( $row );
	}

	function appendErrorRow( email, message ) {
		var $row = $( '<tr></tr>' ).css( 'background-color', '#fce8e8' );
		$row.append( '<td>error</td>' );
		$row.append( '<td>' + escHtml( email ) + '</td>' );
		$row.append( '<td></td><td></td><td></td><td></td>' );
		$row.append( '<td>' + escHtml( message ) + '</td>' );
		$tbody.append( $row );
	}

	function showError( message ) {
		$statusText.text( message );
		$submitBtn.prop( 'disabled', false );
		$cancelBtn.hide();
	}

	function trim( str ) {
		return String( str ).replace( /^\s+|\s+$/g, '' );
	}

	function escHtml( str ) {
		return String( str )
			.replace( /&/g, '&amp;' )
			.replace( /</g, '&lt;' )
			.replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' );
	}
} );
