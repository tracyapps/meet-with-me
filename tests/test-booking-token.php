<?php
/**
 * Tests for MWM_Booking token generation.
 *
 * @package meet-with-me
 */

/**
 * Class MWM_Booking_Token_Test
 */
class MWM_Booking_Token_Test extends WP_UnitTestCase {

	/**
	 * Tokens are 64 character lowercase hex strings.
	 */
	public function test_generate_token_is_64_hex_chars() {
		$token = MWM_Booking::generate_token();

		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', $token );
		$this->assertSame( 64, strlen( $token ) );
	}

	/**
	 * Tokens are unique across many generations.
	 */
	public function test_generate_token_returns_unique_values() {
		$tokens = [];

		for ( $i = 0; $i < 100; $i++ ) {
			$tokens[] = MWM_Booking::generate_token();
		}

		$this->assertSame( 100, count( array_unique( $tokens ) ) );
	}

	/**
	 * Consecutive tokens never collide.
	 */
	public function test_tokens_are_not_sequential_or_identical() {
		$first  = MWM_Booking::generate_token();
		$second = MWM_Booking::generate_token();

		$this->assertNotSame( $first, $second );
	}
}
