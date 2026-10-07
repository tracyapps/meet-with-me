/**
 * Meet With Me — "Meeting Type Cards" block editor script.
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
	var RangeControl      = wp.components.RangeControl;
	var TextControl       = wp.components.TextControl;
	var ToggleControl     = wp.components.ToggleControl;
	var CheckboxControl   = wp.components.CheckboxControl;
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
				enableAlpha: false,
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
	 * Toggle a slug in the selected list.
	 *
	 * @param {string[]} list    Current slugs.
	 * @param {string}   slug    Slug to toggle.
	 * @param {boolean}  checked Whether it should be selected.
	 * @return {string[]} Updated list.
	 */
	function toggleSlug( list, slug, checked ) {
		var next = list.filter( function ( item ) {
			return item !== slug;
		} );
		if ( checked ) {
			next.push( slug );
		}
		return next;
	}

	/**
	 * Parse a comma-separated slug string into an array.
	 *
	 * @param {string} value Raw input.
	 * @return {string[]} Slugs.
	 */
	function parseSlugs( value ) {
		return String( value || '' )
			.split( ',' )
			.map( function ( item ) {
				return item.trim();
			} )
			.filter( function ( item ) {
				return item !== '';
			} );
	}

	registerBlockType( 'meet-with-me/cards', {
		apiVersion: 3,
		title: __( 'Meeting Type Cards', 'meet-with-me' ),
		description: __( 'A grid of meeting type cards, each with a Book Now button.', 'meet-with-me' ),
		category: 'meet-with-me',
		icon: 'grid-view',
		keywords: [
			__( 'booking', 'meet-with-me' ),
			__( 'cards', 'meet-with-me' ),
			__( 'meeting types', 'meet-with-me' ),
		],
		attributes: {
			eventTypes: { type: 'array', items: { type: 'string' }, default: [] },
			columns: { type: 'number', default: 3 },
			showDescription: { type: 'boolean', default: true },
			accentColor: { type: 'string', default: '' },
		},
		edit: function ( props ) {
			var attributes  = props.attributes;
			var blockProps  = useBlockProps();
			var selected    = Array.isArray( attributes.eventTypes ) ? attributes.eventTypes : [];

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __( 'Cards settings', 'meet-with-me' ) },
						el( RangeControl, {
							label: __( 'Columns', 'meet-with-me' ),
							value: attributes.columns,
							min: 1,
							max: 6,
							step: 1,
							onChange: function ( value ) {
								props.setAttributes( { columns: value } );
							},
							help: __( 'Number of grid columns (1–6). Collapses to one column on small screens.', 'meet-with-me' ),
						} ),
						el( ToggleControl, {
							label: __( 'Show descriptions', 'meet-with-me' ),
							checked: !! attributes.showDescription,
							onChange: function ( value ) {
								props.setAttributes( { showDescription: value } );
							},
						} )
					),
					el(
						PanelBody,
						{ title: __( 'Meeting types', 'meet-with-me' ) },
						eventTypes.length > 0
							? eventTypes.map( function ( type ) {
									return el( CheckboxControl, {
										key: type.slug,
										label: type.label,
										checked: selected.indexOf( type.slug ) !== -1,
										onChange: function ( checked ) {
											props.setAttributes( {
												eventTypes: toggleSlug( selected, type.slug, checked ),
											} );
										},
									} );
							  } )
							: el( TextControl, {
									label: __( 'Meeting type slugs', 'meet-with-me' ),
									value: selected.join( ', ' ),
									placeholder: '30-min-intro-call, deep-dive',
									onChange: function ( value ) {
										props.setAttributes( { eventTypes: parseSlugs( value ) } );
									},
									help: __( 'No meeting types found — enter comma-separated slugs, or create them first.', 'meet-with-me' ),
							  } ),
						el(
							'p',
							{ style: { margin: '4px 0 0', color: '#757575', fontSize: '12px' } },
							__( 'Leave all unchecked to show every active meeting type.', 'meet-with-me' )
						)
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
					eventTypes.length === 0 ? emptyTypesNotice() : null,
					el( ServerSideRender, {
						block: 'meet-with-me/cards',
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
