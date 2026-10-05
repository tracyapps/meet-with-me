/**
 * Meet With Me — "Booking Form" block editor script.
 *
 * Hand-written, no build step: uses only the wp.* globals provided by the
 * block editor and mirrors the attribute schema in block.json.
 */
( function ( wp ) {
	'use strict';

	var el                = wp.element.createElement;
	var Fragment          = wp.element.Fragment;
	var __                = wp.i18n.__;
	var registerBlockType = wp.blocks.registerBlockType;
	var ServerSideRender  = wp.serverSideRender;
	var useBlockProps     = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var PanelBody         = wp.components.PanelBody;
	var SelectControl     = wp.components.SelectControl;
	var TextControl       = wp.components.TextControl;
	var ColorPicker       = wp.components.ColorPicker;
	var Button            = wp.components.Button;
	var Notice            = wp.components.Notice;

	var data       = window.mwmBlockEditorData || {};
	var eventTypes = Array.isArray( data.eventTypes ) ? data.eventTypes : [];

	/**
	 * Normalize a ColorPicker value, which may arrive as a string or an object.
	 *
	 * @param {*} color Picker value.
	 * @return {string} Hex string or ''.
	 */
	function colorToHex( color ) {
		if ( ! color ) {
			return '';
		}
		if ( typeof color === 'string' ) {
			return color;
		}
		return color.hex || '';
	}

	/**
	 * Accent color panel used by all three blocks.
	 */
	function AccentPanel( props ) {
		return el(
			PanelBody,
			{ title: __( 'Accent color', 'meet-with-me' ), initialOpen: false },
			el( ColorPicker, {
				color: props.value || '#2563eb',
				disableAlpha: true,
				onChange: function ( color ) {
					props.onChange( colorToHex( color ) );
				}
			} ),
			props.value
				? el(
						Button,
						{
							variant: 'secondary',
							onClick: function () {
								props.onChange( '' );
							}
						},
						__( 'Use the site-wide accent', 'meet-with-me' )
				  )
				: el(
						'p',
						{ style: { margin: '4px 0 0', color: '#757575', fontSize: '12px' } },
						__( 'No override — the accent from Settings → Style is used.', 'meet-with-me' )
				  )
		);
	}

	/**
	 * "No meeting types yet" placeholder shown above the preview.
	 */
	function emptyTypesNotice() {
		return el(
			'div',
			null,
			el(
				Notice,
				{ status: 'warning', isDismissible: false },
				__( 'No meeting types exist yet. Create one under Meeting Types first.', 'meet-with-me' )
			),
			data.newTypeUrl
				? el(
						'p',
						null,
						el(
							Button,
							{ variant: 'secondary', href: data.newTypeUrl },
							__( 'Open Meeting Types', 'meet-with-me' )
						)
				  )
				: null
		);
	}

	/**
	 * Small editor-only hint for the live preview: the wizard is mounted by the
	 * front-end script, so the server-rendered preview can look like an empty
	 * container once meeting types exist.
	 */
	function previewHint() {
		return el(
			'p',
			{ style: { margin: '0 0 8px', color: '#757575', fontSize: '12px' } },
			__( 'The booking wizard renders on the published page — visitors pick a time and confirm their details.', 'meet-with-me' )
		);
	}

	registerBlockType( 'meet-with-me/booking-form', {
		apiVersion: 3,
		title: __( 'Booking Form', 'meet-with-me' ),
		description: __( 'The full booking wizard, rendered inline.', 'meet-with-me' ),
		category: 'meet-with-me',
		icon: 'calendar-alt',
		keywords: [
			__( 'booking', 'meet-with-me' ),
			__( 'appointment', 'meet-with-me' ),
			__( 'calendar', 'meet-with-me' ),
		],
		attributes: {
			eventType: { type: 'string', default: '' },
			accentColor: { type: 'string', default: '' },
		},
		edit: function ( props ) {
			var attributes = props.attributes;
			var blockProps = useBlockProps();

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Booking form settings', 'meet-with-me' ) },
						eventTypes.length > 0
							? el( SelectControl, {
									label: __( 'Meeting type', 'meet-with-me' ),
									value: attributes.eventType,
									options: [
										{ value: '', label: __( 'Let the visitor choose', 'meet-with-me' ) },
									].concat(
										eventTypes.map( function ( type ) {
											return { value: type.slug, label: type.label };
										} )
									),
									onChange: function ( value ) {
										props.setAttributes( { eventType: value } );
									},
									help: __( 'Pre-select a meeting type, or let the visitor pick one.', 'meet-with-me' ),
							  } )
							: el( TextControl, {
									label: __( 'Meeting type slug', 'meet-with-me' ),
									value: attributes.eventType,
									placeholder: '30-min-intro-call',
									onChange: function ( value ) {
										props.setAttributes( { eventType: value } );
									},
									help: __( 'No meeting types found — enter a slug manually, or create one first.', 'meet-with-me' ),
							  } )
					),
					el( AccentPanel, {
						value: attributes.accentColor,
						onChange: function ( value ) {
							props.setAttributes( { accentColor: value } );
						},
					} )
				),
				el(
					'div',
					blockProps,
					eventTypes.length === 0 ? emptyTypesNotice() : previewHint(),
					el( ServerSideRender, {
						block: 'meet-with-me/booking-form',
						attributes: attributes,
					} )
				)
			);
		},
		save: function () {
			return null;
		},
	} );
} )( window.wp );
