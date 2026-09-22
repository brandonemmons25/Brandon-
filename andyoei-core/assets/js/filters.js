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
		this.clear = root.querySelector( '.ao-clear' );
		this.more = root.querySelector( '.ao-more' );
		this.empty = root.querySelector( '.ao-empty' );
		this.searchInput = root.querySelector( '.ao-search-input' );
		this.sortSelect = root.querySelector( '.ao-sort-select' );
		this.pills = Array.prototype.slice.call( root.querySelectorAll( '.ao-pill--facet, .ao-pill--panel' ) );
		this.groups = Array.prototype.slice.call( root.querySelectorAll( '[data-facet]' ) );
		this.page = 1;
		this.timer = null;
		this.request = 0;

		this.bind();
		this.syncPills();
	}

	Directory.prototype.bind = function () {
		var self = this;

		this.pills.forEach( function ( pill ) {
			var button = pill.querySelector( '.ao-pill-button' );
			var menu = pill.querySelector( '.ao-menu' );
			var clear = pill.querySelector( '.ao-menu-clear' );

			if ( ! button || ! menu ) {
				return;
			}

			button.addEventListener( 'click', function ( event ) {
				event.stopPropagation();

				var open = menu.hasAttribute( 'hidden' );

				// Only one menu at a time.
				self.closeMenus();

				if ( open ) {
					menu.removeAttribute( 'hidden' );
					button.setAttribute( 'aria-expanded', 'true' );
				}
			} );

			menu.addEventListener( 'click', function ( event ) {
				event.stopPropagation();
			} );

			menu.querySelectorAll( 'input' ).forEach( function ( input ) {
				input.addEventListener( 'change', function () {
					// The "All" row clears its own group rather than filtering.
					if ( input.value === '' && input.checked ) {
						var group = input.closest( '[data-facet]' );

						group.querySelectorAll( 'input' ).forEach( function ( other ) {
							other.checked = other === input;
						} );
					}

					self.load( true );

					// A single-choice menu has nothing more to offer.
					if ( input.type === 'radio' ) {
						self.closeMenus();
					}
				} );
			} );

			if ( clear ) {
				clear.addEventListener( 'click', function () {
					menu.querySelectorAll( 'input' ).forEach( function ( input ) {
						input.checked = input.value === '';
					} );

					self.load( true );
				} );
			}
		} );

		document.addEventListener( 'click', function () {
			self.closeMenus();
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' ) {
				self.closeMenus();
			}
		} );

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

	Directory.prototype.closeMenus = function () {
		this.pills.forEach( function ( pill ) {
			var menu = pill.querySelector( '.ao-menu' );
			var button = pill.querySelector( '.ao-pill-button' );

			if ( menu ) {
				menu.setAttribute( 'hidden', '' );
			}

			if ( button ) {
				button.setAttribute( 'aria-expanded', 'false' );
			}
		} );
	};

	/** Current selections, keyed by facet name. */
	Directory.prototype.state = function () {
		var facets = {};

		this.groups.forEach( function ( group ) {
			var name = group.getAttribute( 'data-facet' );
			var values = [];

			group.querySelectorAll( 'input:checked' ).forEach( function ( input ) {
				// The "All" row carries no value.
				if ( input.value ) {
					values.push( input.value );
				}
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

				self.apply( data, isReset, params );
			} )
			.catch( function () {
				self.root.classList.remove( 'is-loading' );
			} );
	};

	Directory.prototype.apply = function ( data, isReset, params ) {
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

		if ( this.clear ) {
			this.clear.toggleAttribute( 'hidden', ! data.filtered );
		}

		this.root.setAttribute( 'data-filtered', data.filtered ? '1' : '0' );
		this.root.classList.remove( 'is-loading' );

		// Curated strips step aside once the visitor starts narrowing.
		document.querySelectorAll( '[data-ao-hide-when-filtered="' + this.key + '"]' ).forEach( function ( el ) {
			el.toggleAttribute( 'hidden', !! data.filtered );
		} );

		this.syncPills();

		if ( isReset ) {
			var query = params.toString();
			window.history.replaceState( {}, '', query ? '?' + query : window.location.pathname );
		}
	};

	/**
	 * Each closed pill reads "All", the single chosen term, or a count.
	 */
	Directory.prototype.syncPills = function () {
		this.pills.forEach( function ( pill ) {
			var chosen = [];

			pill.querySelectorAll( 'input:checked' ).forEach( function ( input ) {
				if ( input.value ) {
					chosen.push( input );
				}
			} );

			var value = pill.querySelector( '.ao-pill-value' );
			var count = pill.querySelector( '.ao-pill-count' );

			if ( value ) {
				var group = pill.querySelector( '[data-facet]' );
				var all = group ? group.getAttribute( 'data-all' ) : 'All';

				if ( ! chosen.length ) {
					value.textContent = all;
				} else if ( chosen.length === 1 ) {
					value.textContent = chosen[ 0 ].getAttribute( 'data-label' ) || chosen[ 0 ].value;
				} else {
					value.textContent = chosen.length + ' selected';
				}
			}

			// The Filters button shows how many of its filters are on.
			if ( count ) {
				count.textContent = chosen.length;
				count.toggleAttribute( 'hidden', ! chosen.length );
			}

			pill.classList.toggle( 'is-active', !! chosen.length );
		} );
	};

	Directory.prototype.reset = function () {
		this.groups.forEach( function ( group ) {
			group.querySelectorAll( 'input' ).forEach( function ( input ) {
				input.checked = input.value === '';
			} );
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
		var key = root.getAttribute( 'data-ao-terms' );
		var search = root.querySelector( '.ao-term-search' );
		var sort = root.querySelector( '.ao-term-sort' );
		var results = root.querySelector( '.ao-results' );
		var count = root.querySelector( '.ao-count' );
		var empty = root.querySelector( '.ao-empty' );
		var cards = Array.prototype.slice.call( results.querySelectorAll( '.ao-card' ) );

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

			// The grid runs continuously, so sorting just reorders the cards.
			cards
				.slice()
				.sort( function ( a, b ) {
					var x = a.getAttribute( 'data-name' );
					var y = b.getAttribute( 'data-name' );

					return descending ? y.localeCompare( x ) : x.localeCompare( y );
				} )
				.forEach( function ( card ) {
					results.appendChild( card );
				} );

			if ( count ) {
				count.textContent = count.textContent.replace( /^\d+/, visible );
			}

			if ( empty ) {
				empty.toggleAttribute( 'hidden', visible > 0 );
			}

			// The curated strip steps aside on search or an alternate sort.
			var narrowed = !! term || descending;

			document.querySelectorAll( '[data-ao-hide-when-filtered="' + key + '"]' ).forEach( function ( el ) {
				el.toggleAttribute( 'hidden', narrowed );
			} );
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
