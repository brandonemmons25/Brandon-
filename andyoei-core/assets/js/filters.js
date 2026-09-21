/**
 * Directory filtering.
 *
 * The markup is rendered by PHP; this only re-requests it. Filter state lives
 * in the URL so a filtered view can be shared, bookmarked and returned to.
 */
( function () {
	'use strict';

	var DEBOUNCE = 300;

	function ready( fn ) {
		if ( document.readyState !== 'loading' ) {
			fn();
		} else {
			document.addEventListener( 'DOMContentLoaded', fn );
		}
	}

	function Directory( root ) {
		this.root = root;
		this.key = root.getAttribute( 'data-ao-directory' );
		this.results = root.querySelector( '.ao-results' );
		this.count = root.querySelector( '.ao-count' );
		this.chips = root.querySelector( '.ao-chips' );
		this.clear = root.querySelector( '.ao-clear' );
		this.more = root.querySelector( '.ao-more' );
		this.empty = root.querySelector( '.ao-empty' );
		this.searchInput = root.querySelector( '.ao-search-input' );
		this.sortSelect = root.querySelector( '.ao-sort-select' );
		this.drawer = root.querySelector( '.ao-drawer' );
		this.toggle = root.querySelector( '.ao-filters-toggle' );
		this.page = 1;
		this.timer = null;
		this.request = 0;

		this.bind();
		this.syncChips();
	}

	Directory.prototype.bind = function () {
		var self = this;

		if ( this.toggle && this.drawer ) {
			this.toggle.addEventListener( 'click', function () {
				var open = self.drawer.hasAttribute( 'hidden' );
				self.drawer.toggleAttribute( 'hidden', ! open );
				self.toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			} );
		}

		if ( this.searchInput ) {
			this.searchInput.addEventListener( 'input', function () {
				clearTimeout( self.timer );
				self.timer = setTimeout( function () {
					self.load( true );
				}, DEBOUNCE );
			} );
		}

		if ( this.sortSelect ) {
			this.sortSelect.addEventListener( 'change', function () {
				self.load( true );
			} );
		}

		this.root.querySelectorAll( '.ao-facet input[type="checkbox"]' ).forEach( function ( box ) {
			box.addEventListener( 'change', function () {
				self.load( true );
			} );
		} );

		if ( this.clear ) {
			this.clear.addEventListener( 'click', function () {
				self.reset();
			} );
		}

		if ( this.more ) {
			this.more.addEventListener( 'click', function () {
				self.page += 1;
				self.load( false );
			} );
		}
	};

	/** Current selections, keyed by facet name. */
	Directory.prototype.state = function () {
		var facets = {};

		this.root.querySelectorAll( '.ao-facet' ).forEach( function ( group ) {
			var name = group.getAttribute( 'data-facet' );
			var values = [];

			group.querySelectorAll( 'input[type="checkbox"]:checked' ).forEach( function ( box ) {
				values.push( box.value );
			} );

			if ( values.length ) {
				facets[ name ] = values;
			}
		} );

		return {
			facets: facets,
			search: this.searchInput ? this.searchInput.value.trim() : '',
			sort: this.sortSelect ? this.sortSelect.value : ''
		};
	};

	Directory.prototype.queryString = function ( state, page ) {
		var params = new URLSearchParams();

		Object.keys( state.facets ).forEach( function ( name ) {
			state.facets[ name ].forEach( function ( value ) {
				params.append( 'f[' + name + '][]', value );
			} );
		} );

		if ( state.search ) {
			params.set( 's', state.search );
		}

		if ( state.sort ) {
			params.set( 'sort', state.sort );
		}

		if ( page > 1 ) {
			params.set( 'page', page );
		}

		return params;
	};

	Directory.prototype.load = function ( isReset ) {
		var self = this;
		var state = this.state();

		if ( isReset ) {
			this.page = 1;
		}

		var params = this.queryString( state, this.page );

		// Continuing a set: tell the server which group header was last
		// printed so a letter is not repeated across pages.
		if ( ! isReset && this.results ) {
			params.set( 'last_group', this.results.getAttribute( 'data-last-group' ) || '' );
		}

		var ticket = ++this.request;

		this.root.classList.add( 'is-loading' );

		fetch( aoFilters.root + this.key + '?' + params.toString(), {
			headers: { 'Accept': 'application/json' }
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( data ) {
				// A slower earlier request must not overwrite a newer one.
				if ( ticket !== self.request ) {
					return;
				}

				self.apply( data, isReset, state, params );
			} )
			.catch( function () {
				self.root.classList.remove( 'is-loading' );
			} );
	};

	Directory.prototype.apply = function ( data, isReset, state, params ) {
		if ( isReset ) {
			this.results.innerHTML = data.html;
		} else {
			this.results.insertAdjacentHTML( 'beforeend', data.html );
		}

		this.results.setAttribute( 'data-last-group', data.last_group || '' );

		if ( this.count ) {
			this.count.textContent = data.count_text;
		}

		if ( this.more ) {
			this.more.toggleAttribute( 'hidden', ! data.has_more );
		}

		if ( this.empty ) {
			this.empty.toggleAttribute( 'hidden', data.total > 0 );
		}

		this.root.setAttribute( 'data-filtered', data.filtered ? '1' : '0' );
		this.root.classList.remove( 'is-loading' );

		// Curated strips step aside once the visitor starts narrowing.
		document.querySelectorAll( '[data-ao-hide-when-filtered="' + this.key + '"]' ).forEach( function ( el ) {
			el.toggleAttribute( 'hidden', !! data.filtered );
		} );

		this.syncChips();

		if ( isReset ) {
			var query = params.toString();
			window.history.replaceState( {}, '', query ? '?' + query : window.location.pathname );
		}
	};

	/** Removable chips for everything currently narrowing the set. */
	Directory.prototype.syncChips = function () {
		if ( ! this.chips ) {
			return;
		}

		var self = this;
		var boxes = this.root.querySelectorAll( '.ao-facet input[type="checkbox"]:checked' );

		this.chips.innerHTML = '';

		boxes.forEach( function ( box ) {
			var chip = document.createElement( 'button' );

			chip.type = 'button';
			chip.className = 'ao-chip';
			chip.innerHTML = '<span></span><span aria-hidden="true">&times;</span>';
			chip.firstChild.textContent = box.getAttribute( 'data-label' ) || box.value;
			chip.setAttribute( 'aria-label', 'Remove filter: ' + chip.firstChild.textContent );

			chip.addEventListener( 'click', function () {
				box.checked = false;
				self.load( true );
			} );

			self.chips.appendChild( chip );
		} );

		if ( this.clear ) {
			this.clear.toggleAttribute( 'hidden', ! boxes.length && ! ( this.searchInput && this.searchInput.value ) );
		}
	};

	Directory.prototype.reset = function () {
		this.root.querySelectorAll( '.ao-facet input[type="checkbox"]' ).forEach( function ( box ) {
			box.checked = false;
		} );

		if ( this.searchInput ) {
			this.searchInput.value = '';
		}

		this.load( true );
	};

	/**
	 * Neighborhood directory: the whole set is already on the page, so search
	 * and sort happen here rather than over the network.
	 */
	function TermDirectory( root ) {
		var search = root.querySelector( '.ao-term-search' );
		var sort = root.querySelector( '.ao-term-sort' );
		var results = root.querySelector( '.ao-results' );
		var count = root.querySelector( '.ao-count' );
		var empty = root.querySelector( '.ao-empty' );
		var cards = Array.prototype.slice.call( results.querySelectorAll( '.ao-card' ) );
		var headers = Array.prototype.slice.call( results.querySelectorAll( '.ao-group' ) );

		function render() {
			var term = search ? search.value.trim().toLowerCase() : '';
			var descending = sort && sort.value === 'desc';
			var visible = 0;

			cards.forEach( function ( card ) {
				var match = ! term || card.getAttribute( 'data-name' ).indexOf( term ) !== -1;

				card.toggleAttribute( 'hidden', ! match );

				if ( match ) {
					visible++;
				}
			} );

			// A letter heading only belongs on screen while it still has cards.
			headers.forEach( function ( header ) {
				var letter = header.getAttribute( 'data-letter' );
				var used = cards.some( function ( card ) {
					return card.getAttribute( 'data-letter' ) === letter && ! card.hasAttribute( 'hidden' );
				} );

				header.toggleAttribute( 'hidden', ! used );
			} );

			var order = headers.slice().sort( function ( a, b ) {
				var x = a.getAttribute( 'data-letter' );
				var y = b.getAttribute( 'data-letter' );

				return descending ? y.localeCompare( x ) : x.localeCompare( y );
			} );

			order.forEach( function ( header ) {
				results.appendChild( header );

				cards
					.filter( function ( card ) {
						return card.getAttribute( 'data-letter' ) === header.getAttribute( 'data-letter' );
					} )
					.sort( function ( a, b ) {
						var x = a.getAttribute( 'data-name' );
						var y = b.getAttribute( 'data-name' );

						return descending ? y.localeCompare( x ) : x.localeCompare( y );
					} )
					.forEach( function ( card ) {
						results.appendChild( card );
					} );
			} );

			if ( count ) {
				count.textContent = visible;
			}

			if ( empty ) {
				empty.toggleAttribute( 'hidden', visible > 0 );
			}
		}

		if ( search ) {
			search.addEventListener( 'input', render );
		}

		if ( sort ) {
			sort.addEventListener( 'change', render );
		}
	}

	ready( function () {
		document.querySelectorAll( '[data-ao-directory]' ).forEach( function ( root ) {
			new Directory( root );
		} );

		document.querySelectorAll( '[data-ao-terms]' ).forEach( function ( root ) {
			new TermDirectory( root );
		} );
	} );
}() );
