<?php
/**
 * Tests for the settings page tab routing.
 *
 * @package meet-with-me
 */

/**
 * Class MWM_Admin_Routing_Test
 */
class MWM_Admin_Routing_Test extends WP_UnitTestCase {

	/**
	 * The Help & Setup tab renders its static view. 0.3.0 omitted 'help'
	 * from the controller whitelist, so the tab silently fell back to
	 * General.
	 */
	public function test_settings_tab_help_renders_help_view() {
		require_once MWM_PLUGIN_DIR . 'admin/class-mwm-admin.php';

		$_GET['page'] = 'mwm-settings';
		$_GET['tab']  = 'help';

		$admin = new MWM_Admin();

		ob_start();
		$admin->page_settings();
		$html = ob_get_clean();

		unset( $_GET['page'], $_GET['tab'] );

		$this->assertStringContainsString( 'id="mwm-help-quickstart"', $html );
		$this->assertStringContainsString( 'tab=help', $html );
	}

	/**
	 * The help tab has no write actions: the early action router must
	 * resolve it to a null controller without touching a page controller.
	 */
	public function test_settings_tab_help_has_no_actions_controller() {
		require_once MWM_PLUGIN_DIR . 'admin/class-mwm-admin.php';

		$_GET['page'] = 'mwm-settings';
		$_GET['tab']  = 'help';

		$method = new ReflectionMethod( 'MWM_Admin', 'settings_controller' );
		$method->setAccessible( true );

		$this->assertNull( $method->invoke( new MWM_Admin() ) );

		unset( $_GET['page'], $_GET['tab'] );
	}
}
