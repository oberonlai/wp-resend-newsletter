/**
 * Front-end subscribe form → POST /wp-json/wprn/v1/subscribers
 *
 * @package WpResendNewsletter
 */
( function () {
	'use strict';

	function getConfig() {
		return typeof window.wprnSubscribe === 'object' && window.wprnSubscribe
			? window.wprnSubscribe
			: null;
	}

	function setMessage( el, text, kind ) {
		if ( ! el ) {
			return;
		}
		el.hidden = ! text;
		el.textContent = text || '';
		el.classList.remove(
			'wprn-subscribe__message--ok',
			'wprn-subscribe__message--err'
		);
		if ( kind ) {
			el.classList.add( 'wprn-subscribe__message--' + kind );
		}
	}

	function isValidEmail( value ) {
		return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( String( value || '' ).trim() );
	}

	function bindForm( root ) {
		var form = root.querySelector( '.wprn-subscribe__form' );
		if ( ! form || form.dataset.wprnBound === '1' ) {
			return;
		}
		form.dataset.wprnBound = '1';

		var emailInput = form.querySelector( 'input[name="email"]' );
		var button = form.querySelector( '.wprn-subscribe__button' );
		var message = form.querySelector( '.wprn-subscribe__message' );
		var cfg = getConfig();
		var defaultLabel = button ? button.textContent : '';

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			cfg = getConfig();
			if ( ! cfg || ! cfg.restUrl ) {
				setMessage(
					message,
					( cfg && cfg.i18n && cfg.i18n.error ) ||
						'Something went wrong.',
					'err'
				);
				return;
			}

			var email = emailInput ? String( emailInput.value || '' ).trim() : '';
			if ( ! isValidEmail( email ) ) {
				setMessage(
					message,
					( cfg.i18n && cfg.i18n.invalid ) || 'Invalid email.',
					'err'
				);
				if ( emailInput ) {
					emailInput.focus();
				}
				return;
			}

			if ( button ) {
				button.disabled = true;
				button.textContent =
					( cfg.i18n && cfg.i18n.submitting ) || 'Submitting…';
			}
			setMessage( message, '', null );

			var headers = {
				'Content-Type': 'application/json',
				Accept: 'application/json',
			};
			if ( cfg.nonce ) {
				headers['X-WP-Nonce'] = cfg.nonce;
			}

			fetch( cfg.restUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: headers,
				body: JSON.stringify( { email: email } ),
			} )
				.then( function ( res ) {
					return res.json().then( function ( data ) {
						return { res: res, data: data };
					} );
				} )
				.then( function ( payload ) {
					var res = payload.res;
					var data = payload.data || {};
					if ( res.ok && data.success ) {
						setMessage(
							message,
							data.message ||
								'If this email can be subscribed, a confirmation message has been sent. Please check your inbox.',
							'ok'
						);
						form.reset();
						return;
					}
					var errMsg =
						data.message ||
						( data.data && data.data.message ) ||
						( cfg.i18n && cfg.i18n.error ) ||
						'Something went wrong.';
					setMessage( message, errMsg, 'err' );
				} )
				.catch( function () {
					setMessage(
						message,
						( cfg.i18n && cfg.i18n.error ) ||
							'Something went wrong.',
						'err'
					);
				} )
				.finally( function () {
					if ( button ) {
						button.disabled = false;
						button.textContent = defaultLabel;
					}
				} );
		} );
	}

	function init() {
		document
			.querySelectorAll( '[data-wprn-subscribe]' )
			.forEach( bindForm );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
