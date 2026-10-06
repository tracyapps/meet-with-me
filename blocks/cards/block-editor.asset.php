<?php
/**
 * Dependencies for the Meeting Type Cards block editor script (no build step).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'dependencies' => array(
		'wp-blocks',
		'wp-element',
		'wp-components',
		'wp-block-editor',
		'wp-i18n',
		'wp-server-side-render',
	),
	'version'      => defined( 'MWM_VERSION' ) ? MWM_VERSION : '0.3.0',
);
