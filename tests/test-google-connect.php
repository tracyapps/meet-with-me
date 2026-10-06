<?php
/**
 * Tests for MWM_Google_Connect state encoding (the relay handshake payload).
 *
 * @package meet-with-me
 */

/**
 * Class MWM_Google_Connect_Test
 */
class MWM_Google_Connect_Test extends WP_UnitTestCase {

	/**
	 * A freshly encoded state decodes back to its return URL and ticket.
	 */
	public function test_state_roundtrip() {
		$state  = MWM_Google_Connect::encode_state( 'https://example.com/wp-admin/admin.php?mwm_relay_cb=1', 'TICKET_123', time() + 500 );
		$parsed = MWM_Google_Connect::decode_state( $state );

		$this->assertNotNull( $parsed );
		$this->assertSame( 'https://example.com/wp-admin/admin.php?mwm_relay_cb=1', $parsed['return'] );
		$this->assertSame( 'TICKET_123', $parsed['ticket'] );
	}

	/**
	 * Expired states are rejected.
	 */
	public function test_expired_state_rejected() {
		$state = MWM_Google_Connect::encode_state( 'https://example.com/cb', 'T', time() - 10 );
		$this->assertNull( MWM_Google_Connect::decode_state( $state ) );
	}

	/**
	 * States with implausible future timestamps are rejected.
	 */
	public function test_far_future_state_rejected() {
		$state = MWM_Google_Connect::encode_state( 'https://example.com/cb', 'T', time() + 100000 );
		$this->assertNull( MWM_Google_Connect::decode_state( $state ) );
	}

	/**
	 * Garbage payloads are rejected without warnings.
	 */
	public function test_garbage_state_rejected() {
		$this->assertNull( MWM_Google_Connect::decode_state( 'not!!a!!state' ) );
		$this->assertNull( MWM_Google_Connect::decode_state( '' ) );
	}

	/**
	 * States missing their ticket are rejected.
	 */
	public function test_state_without_ticket_rejected() {
		$encoded = rtrim( strtr( base64_encode( wp_json_encode( array( 'r' => 'https://example.com/cb', 'x' => time() + 500 ) ) ), '+/', '-_' ), '=' );
		$this->assertNull( MWM_Google_Connect::decode_state( $encoded ) );
	}

	/**
	 * The shared OAuth client id ships in the plugin so one-click connect
	 * works on fresh installs (0.3.0 shipped it empty and every new install
	 * fell back to the own-credentials flow).
	 */
	public function test_shipped_client_id_makes_relay_available() {
		MWM_Settings::flush_cache( 'google' );
		delete_option( 'mwm_google' );

		$this->assertSame( '', MWM_Settings::get( 'relay_client_id', 'google' ) );
		$this->assertStringEndsWith( '.apps.googleusercontent.com', MWM_Google_Connect::client_id() );
		$this->assertTrue( MWM_Google_Connect::is_available() );
	}

	/**
	 * A stored relay_client_id setting overrides the shipped default.
	 */
	public function test_stored_client_id_overrides_default() {
		MWM_Settings::set( 'relay_client_id', 'stored-id.apps.googleusercontent.com', 'google' );
		$this->assertSame( 'stored-id.apps.googleusercontent.com', MWM_Google_Connect::client_id() );
	}
}
