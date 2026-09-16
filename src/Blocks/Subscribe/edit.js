/**
 * Editor UI for wp-resend-newsletter/subscribe (no JSX / webpack).
 *
 * @package WpResendNewsletter
 */
( function ( wp ) {
	'use strict';

	var registerBlockType = wp.blocks.registerBlockType;
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var TextareaControl = wp.components.TextareaControl;
	var ToggleControl = wp.components.ToggleControl;
	var __ = wp.i18n.__;

	registerBlockType( 'wp-resend-newsletter/subscribe', {
		edit: function ( props ) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;
			var description = attributes.description || '';
			var showMedia =
				typeof attributes.showMedia === 'undefined'
					? true
					: !! attributes.showMedia;

			var classNames = 'wprn-subscribe wprn-subscribe--editor';
			if ( showMedia ) {
				classNames += ' wprn-subscribe--split';
			} else {
				classNames += ' wprn-subscribe--compact';
			}

			var blockProps = useBlockProps( {
				className: classNames,
			} );

			var letterUrl =
				typeof window.wprnSubscribeEditor !== 'undefined' &&
				window.wprnSubscribeEditor.letterImageUrl
					? window.wprnSubscribeEditor.letterImageUrl
					: '';

			var mediaChildren;
			if ( letterUrl ) {
				mediaChildren = el( 'img', {
					className: 'wprn-subscribe__photo',
					src: letterUrl,
					alt: __( 'Handwritten letter', 'wp-resend-newsletter' ),
					width: 1200,
					height: 675,
					decoding: 'async',
				} );
			} else {
				mediaChildren = el(
					'div',
					{
						className: 'wprn-subscribe__media--placeholder',
						'aria-hidden': 'true',
					},
					__( 'Letter photo', 'wp-resend-newsletter' )
				);
			}

			var bodyChildren = [
				el(
					'h3',
					{ className: 'wprn-subscribe__title', key: 'title' },
					attributes.title ||
						__( 'Subscribe to our newsletter', 'wp-resend-newsletter' )
				),
			];

			if ( description.trim() !== '' ) {
				bodyChildren.push(
					el(
						'p',
						{ className: 'wprn-subscribe__lead', key: 'lead' },
						description
					)
				);
			}

			bodyChildren.push(
				el(
					'div',
					{ className: 'wprn-subscribe__form', key: 'form' },
					el(
						'div',
						{ className: 'wprn-subscribe__field' },
						el(
							'label',
							{ className: 'wprn-subscribe__label' },
							__( 'Email', 'wp-resend-newsletter' )
						),
						el( 'input', {
							className: 'wprn-subscribe__input',
							type: 'email',
							disabled: true,
							placeholder: __( 'you@example.com', 'wp-resend-newsletter' ),
						} )
					),
					el(
						'button',
						{
							className: 'wprn-subscribe__button',
							type: 'button',
							disabled: true,
						},
						attributes.buttonLabel ||
							__( 'Subscribe', 'wp-resend-newsletter' )
					),
					el(
						'p',
						{
							className: 'wprn-subscribe__message',
							style: { opacity: 0.7 },
						},
						__(
							'Preview — form submits on the front end.',
							'wp-resend-newsletter'
						)
					)
				)
			);

			var children = [];
			if ( showMedia ) {
				children.push(
					el(
						'div',
						{ className: 'wprn-subscribe__media', key: 'media' },
						mediaChildren
					)
				);
			}
			children.push(
				el(
					'div',
					{ className: 'wprn-subscribe__body', key: 'body' },
					bodyChildren
				)
			);

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{
							title: __( 'Subscribe form', 'wp-resend-newsletter' ),
							initialOpen: true,
						},
						el( TextControl, {
							label: __( 'Title', 'wp-resend-newsletter' ),
							value: attributes.title || '',
							onChange: function ( value ) {
								setAttributes( { title: value } );
							},
						} ),
						el( TextControl, {
							label: __( 'Button label', 'wp-resend-newsletter' ),
							value: attributes.buttonLabel || '',
							onChange: function ( value ) {
								setAttributes( { buttonLabel: value } );
							},
						} ),
						el( TextareaControl, {
							label: __( 'Description', 'wp-resend-newsletter' ),
							help: __(
								'Optional text shown inside the card, above the email field.',
								'wp-resend-newsletter'
							),
							value: description,
							onChange: function ( value ) {
								setAttributes( { description: value } );
							},
							rows: 5,
						} ),
						el( ToggleControl, {
							label: __( 'Show letter image', 'wp-resend-newsletter' ),
							help: __(
								'When off, renders a compact card without the handwritten letter (good for sidebars).',
								'wp-resend-newsletter'
							),
							checked: showMedia,
							onChange: function ( value ) {
								setAttributes( { showMedia: !! value } );
							},
						} )
					)
				),
				el( 'div', blockProps, children )
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
