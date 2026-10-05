<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gutenberg block integration for Meet With Me.
 *
 * Registers three dynamic blocks — meet-with-me/booking-form, meet-with-me/button
 * and meet-with-me/cards — that reuse the shortcode renderers on MWM_Public so
 * both embedding paths output identical markup. Also registers the "Meet With Me"
 * block category, the shared front-end style handle, and the sanitized editor
 * data payload used by the block editor scripts.
 *
 * No build step: block.json (apiVersion 3) + hand-written editor JS + asset.php.
 */
class MWM_Blocks {

	/**
	 * Block folder => render callback map.
	 *
	 * @return array<string, callable>
	 */
	private static function block_map(): array {
		return [
			'booking-form' => [ __CLASS__, 'render_booking_form' ],
			'button'       => [ __CLASS__, 'render_button' ],
			'cards'        => [ __CLASS__, 'render_cards' ],
		];
	}

	/**
	 * Hook everything up. Runs in both admin and front-end contexts because
	 * block registration must happen everywhere.
	 */
	public static function init(): void {
		add_action( 'init', [ __CLASS__, 'register_blocks' ] );
		add_filter( 'block_categories_all', [ __CLASS__, 'register_category' ], 10, 2 );
	}

	/**
	 * Register the blocks, the shared style handle, and the editor data.
	 */
	public static function register_blocks(): void {
		self::load_renderer();

		// The shared front-end stylesheet is referenced from every block.json
		// "style" field (plain handle), so the editor previews are styled and
		// the front end can load it on demand when a block renders.
		MWM_Public::register_front_style();

		foreach ( self::block_map() as $folder => $callback ) {
			$block_type = register_block_type_from_metadata(
				MWM_PLUGIN_DIR . 'blocks/' . $folder,
				[ 'render_callback' => $callback ]
			);

			if ( $block_type instanceof WP_Block_Type && ! empty( $block_type->editor_script_handles ) && is_admin() ) {
				wp_localize_script(
					$block_type->editor_script_handles[0],
					'mwmBlockEditorData',
					self::get_editor_data()
				);
			}
		}
	}

	/**
	 * Add the "Meet With Me" block category.
	 *
	 * @param array $categories           Existing block categories.
	 * @param mixed $block_editor_context Editor context (unused).
	 * @return array
	 */
	public static function register_category( $categories, $block_editor_context ): array {
		array_unshift(
			$categories,
			[
				'slug'  => 'meet-with-me',
				'title' => __( 'Meet With Me', 'meet-with-me' ),
				'icon'  => null,
			]
		);

		return $categories;
	}

	/**
	 * Render: meet-with-me/booking-form (same output as [mwm_booking_form]).
	 *
	 * @param array $attributes Block attributes (schema-validated, not sanitized).
	 * @return string
	 */
	public static function render_booking_form( $attributes ): string {
		$slug   = isset( $attributes['eventType'] ) ? sanitize_key( (string) $attributes['eventType'] ) : '';
		$accent = isset( $attributes['accentColor'] ) ? ( sanitize_hex_color( (string) $attributes['accentColor'] ) ?: '' ) : '';

		MWM_Public::ensure_assets();

		$inner = self::renderer()->shortcode_booking_form(
			[
				'event_type' => $slug,
				'accent'     => $accent,
			]
		);

		return '<div ' . get_block_wrapper_attributes( [ 'class' => 'mwm-block mwm-block--booking-form' ] ) . '>'
			. $inner
			. '</div>';
	}

	/**
	 * Render: meet-with-me/button (same output as [mwm_button]).
	 *
	 * @param array $attributes Block attributes (schema-validated, not sanitized).
	 * @return string
	 */
	public static function render_button( $attributes ): string {
		$slug   = isset( $attributes['eventType'] ) ? sanitize_key( (string) $attributes['eventType'] ) : '';
		$accent = isset( $attributes['accentColor'] ) ? ( sanitize_hex_color( (string) $attributes['accentColor'] ) ?: '' ) : '';
		$label  = isset( $attributes['label'] ) ? sanitize_text_field( (string) $attributes['label'] ) : '';

		MWM_Public::ensure_assets();

		$atts = [
			'event_type' => $slug,
			'accent'     => $accent,
		];

		// Let the shortcode renderer apply its default label when none is set.
		if ( '' !== $label ) {
			$atts['label'] = $label;
		}

		return '<div ' . get_block_wrapper_attributes( [ 'class' => 'mwm-block mwm-block--button' ] ) . '>'
			. self::renderer()->shortcode_button( $atts )
			. '</div>';
	}

	/**
	 * Render: meet-with-me/cards (same output as [mwm_cards]).
	 *
	 * @param array $attributes Block attributes (schema-validated, not sanitized).
	 * @return string
	 */
	public static function render_cards( $attributes ): string {
		$types = [];
		if ( isset( $attributes['eventTypes'] ) && is_array( $attributes['eventTypes'] ) ) {
			foreach ( $attributes['eventTypes'] as $slug ) {
				if ( is_string( $slug ) && '' !== $slug ) {
					$types[] = sanitize_key( $slug );
				}
			}
		}

		$columns = isset( $attributes['columns'] ) ? max( 1, min( 6, (int) $attributes['columns'] ) ) : 3;
		$show    = ! isset( $attributes['showDescription'] ) || (bool) $attributes['showDescription'];
		$accent  = isset( $attributes['accentColor'] ) ? ( sanitize_hex_color( (string) $attributes['accentColor'] ) ?: '' ) : '';

		MWM_Public::ensure_assets();

		$inner = self::renderer()->shortcode_cards(
			[
				'event_types'      => implode( ',', $types ),
				'columns'          => (string) $columns,
				'show_description' => $show ? 'true' : 'false',
				'accent'           => $accent,
			]
		);

		return '<div ' . get_block_wrapper_attributes( [ 'class' => 'mwm-block mwm-block--cards' ] ) . '>'
			. $inner
			. '</div>';
	}

	/**
	 * Sanitized data for the block editor scripts (attribute pickers).
	 *
	 * Only fetched in admin/editor requests; cached per request.
	 *
	 * @return array
	 */
	private static function get_editor_data(): array {
		static $data = null;
		if ( null !== $data ) {
			return $data;
		}

		$event_types = [];
		foreach ( MWM_Event_Type::get_all( true ) as $event_type ) {
			$label = $event_type['name'];

			if ( ! empty( $event_type['duration_minutes'] ) ) {
				$label = sprintf(
					/* translators: 1: meeting type name, 2: duration in minutes */
					__( '%1$s (%2$d min)', 'meet-with-me' ),
					$event_type['name'],
					(int) $event_type['duration_minutes']
				);
			}

			$event_types[] = [
				'id'    => (int) $event_type['id'],
				'slug'  => sanitize_key( (string) $event_type['slug'] ),
				'name'  => sanitize_text_field( (string) $event_type['name'] ),
				'label' => sanitize_text_field( $label ),
			];
		}

		$data = [
			'eventTypes' => $event_types,
			'newTypeUrl' => esc_url_raw( admin_url( 'admin.php?page=mwm-event-types' ) ),
		];

		return $data;
	}

	/**
	 * Ensure the MWM_Public renderer class is loaded. Admin and REST requests do
	 * not load it through MWM_Plugin::load_dependencies().
	 */
	private static function load_renderer(): void {
		if ( ! class_exists( 'MWM_Public' ) ) {
			require_once MWM_PLUGIN_DIR . 'public/class-mwm-public.php';
		}
	}

	/**
	 * Fresh renderer instance; the shortcode renderers are stateless.
	 *
	 * @return MWM_Public
	 */
	private static function renderer(): MWM_Public {
		self::load_renderer();
		return new MWM_Public();
	}
}
