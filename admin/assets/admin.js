/* Xenios KB Bot — admin scripts (vanilla JS, no jQuery) */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var container = document.getElementById( 'xkb-pairs' );
		var addBtn    = document.getElementById( 'xkb-add-pair' );
		var template  = document.getElementById( 'xkb-pair-template' );

		// "Add another Q&A pair" — pro settings page only.
		if ( container && addBtn && template ) {
			// Unique, monotonically increasing index so field names never collide,
			// even after rows are removed. Start above the server-rendered rows.
			var nextIndex = container.querySelectorAll( '[data-xkb-pair]' ).length;

			addBtn.addEventListener( 'click', function () {
				var markup  = template.innerHTML.replace( /__INDEX__/g, String( nextIndex ) );
				nextIndex++;

				var wrapper = document.createElement( 'div' );
				wrapper.innerHTML = markup.trim();
				var row = wrapper.firstElementChild;
				if ( row ) {
					container.appendChild( row );
					var firstField = row.querySelector( 'textarea' );
					if ( firstField ) {
						firstField.focus();
					}
				}
			} );
		}

		// "Remove" links — delegated so dynamically added rows work too.
		if ( container ) {
			container.addEventListener( 'click', function ( e ) {
				var link = e.target.closest( '.xkb-remove-pair' );
				if ( ! link ) {
					return;
				}
				e.preventDefault();
				var row = link.closest( '[data-xkb-pair]' );
				if ( row ) {
					row.parentNode.removeChild( row );
				}
			} );
		}
	} );
} )();
