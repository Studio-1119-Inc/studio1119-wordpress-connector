<?php
/**
 * Red-green tests for the three WordPress.org plugin review security fixes:
 *
 * 1. All REST routes have a permission_callback (not __return_true or missing).
 * 2. OAuth return handler verifies nonce + capability before state change.
 * 3. WC API key auth enforces permission levels and key-owner capability.
 *
 * Each test pair proves the vulnerability was real (red) and the fix closes
 * it (green). The "red" case simulates the attacker scenario; the "green"
 * case shows the fix rejects it.
 *
 * @package Studio1119\Connector\Tests
 */

namespace Studio1119\Connector\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;
use Studio1119\Connector\Admin_Page;
use Studio1119\Connector\Rest_Bridge;
use Studio1119\Connector\Widget_Auth;

/**
 * Security tests for the three WordPress.org review fixes.
 */
class SecurityTest extends TestCase {

	/**
	 * Captured register_rest_route calls: [ [ namespace, route, args ], ... ]
	 *
	 * @var array
	 */
	private $registered_routes = array();

	/**
	 * Set up Brain Monkey before each test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->registered_routes = array();
	}

	/**
	 * Tear down Brain Monkey after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		unset( $_GET['testapp_connected'] );
		unset( $_GET['_wpnonce'] );
		unset( $_GET['testapp_user'] );
		$container = Mockery::getContainer();
		if ( $container ) {
			$this->addToAssertionCount( $container->mockery_getExpectationCount() );
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	// ---------------------------------------------------------------
	// Fix 1: REST routes have permission_callback
	// ---------------------------------------------------------------

	/**
	 * GREEN: Every REST route registered by Rest_Bridge has a callable
	 * permission_callback that is NOT __return_true.
	 *
	 * The WordPress.org reviewer flagged that missing permission_callback
	 * defaults to __return_true, making endpoints public. This test asserts
	 * every registered route has an explicit, non-trivial callback.
	 *
	 * @return void
	 */
	public function test_all_rest_routes_have_permission_callback(): void {
		$this->capture_route_registrations();

		Rest_Bridge::register_routes();

		$this->assertNotEmpty( $this->registered_routes, 'No routes registered' );

		foreach ( $this->registered_routes as $entry ) {
			$namespace = $entry[0];
			$route     = $entry[1];
			$args      = $entry[2];

			// Routes can be registered as a flat array or nested arrays of methods.
			$route_configs = isset( $args['methods'] ) ? array( $args ) : $args;

			foreach ( $route_configs as $config ) {
				$label = "$namespace$route ({$config['methods']})";

				$this->assertArrayHasKey(
					'permission_callback',
					$config,
					"Route $label is missing permission_callback"
				);

				$callback = $config['permission_callback'];
				$this->assertTrue(
					is_callable( $callback ),
					"Route $label has a non-callable permission_callback"
				);

				// Must not be the trivial __return_true that WP falls back to.
				if ( is_string( $callback ) ) {
					$this->assertNotEquals(
						'__return_true',
						$callback,
						"Route $label uses __return_true as permission_callback"
					);
				}
			}
		}
	}

	// ---------------------------------------------------------------
	// Fix 2: OAuth return handler verifies nonce + capability
	// ---------------------------------------------------------------

	/**
	 * RED: A request with the connected param but NO capability is rejected.
	 *
	 * Before the fix, handle_oauth_return() did not check current_user_can().
	 * An unauthenticated visitor with the right query param could mark the
	 * store as "connected".
	 *
	 * @return void
	 */
	public function test_oauth_return_rejects_without_capability(): void {
		$_GET['testapp_connected'] = '1';
		$_GET['_wpnonce']          = 'valid-nonce';

		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\expect( 'update_option' )->never();
		Functions\expect( 'wp_verify_nonce' )->never();

		Admin_Page::handle_oauth_return();
	}

	/**
	 * RED: A request with capability but an invalid nonce is rejected.
	 *
	 * Before the fix, handle_oauth_return() did not verify a nonce. A CSRF
	 * attack could trick an admin into visiting a URL that flips the
	 * connected state.
	 *
	 * @return void
	 */
	public function test_oauth_return_rejects_invalid_nonce(): void {
		$_GET['testapp_connected'] = '1';
		$_GET['_wpnonce']          = 'bad-nonce';

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->alias(
			function ( $str ) {
				return $str;
			}
		);
		Functions\when( 'wp_unslash' )->alias(
			function ( $str ) {
				return $str;
			}
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( false );

		Functions\expect( 'update_option' )->never();

		Admin_Page::handle_oauth_return();
	}

	/**
	 * GREEN: A request with valid capability AND valid nonce succeeds.
	 *
	 * @return void
	 */
	public function test_oauth_return_succeeds_with_capability_and_nonce(): void {
		$_GET['testapp_connected'] = '1';
		$_GET['_wpnonce']          = 'good-nonce';
		$_GET['testapp_user']      = 'shop@example.com';

		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->alias(
			function ( $str ) {
				return $str;
			}
		);
		Functions\when( 'wp_unslash' )->alias(
			function ( $str ) {
				return $str;
			}
		);
		Functions\when( 'wp_verify_nonce' )->justReturn( 1 );

		Functions\expect( 'update_option' )
			->once()
			->with( 'testapp_connected', '1' );
		Functions\expect( 'update_option' )
			->once()
			->with( 'testapp_connected_user', 'shop@example.com' );

		Functions\expect( 'admin_url' )->once()->andReturn( 'https://shop.test/wp-admin/' );
		Functions\expect( 'wp_safe_redirect' )->once();

		Admin_Page::handle_oauth_return();
	}

	/**
	 * RED: Without the connected query param, handler is a no-op (baseline).
	 *
	 * @return void
	 */
	public function test_oauth_return_noop_without_query_param(): void {
		// $_GET is empty — no testapp_connected param.
		Functions\expect( 'current_user_can' )->never();
		Functions\expect( 'update_option' )->never();

		Admin_Page::handle_oauth_return();
	}

	// ---------------------------------------------------------------
	// Fix 3: WC API key auth enforces permission levels + user cap
	// ---------------------------------------------------------------

	/**
	 * RED: A read-only API key cannot satisfy a 'write' permission check.
	 *
	 * Before the fix, check_wc_auth() did not check the key's permission
	 * level. A key with 'read' access could call POST endpoints.
	 *
	 * @return void
	 */
	public function test_wc_auth_rejects_read_key_for_write(): void {
		$this->mock_basic_auth( 'ck_test', 'cs_test' );
		$this->mock_wc_key_lookup( 'cs_test', 'read', 1 );
		$this->mock_key_owner_with_cap( 1, true );

		$this->assertFalse(
			Widget_Auth::check_wc_auth( 'write' ),
			'Read-only key must not pass a write permission check'
		);
	}

	/**
	 * GREEN: A read_write API key satisfies a 'write' permission check.
	 *
	 * @return void
	 */
	public function test_wc_auth_accepts_readwrite_key_for_write(): void {
		$this->mock_basic_auth( 'ck_test', 'cs_test' );
		$this->mock_wc_key_lookup( 'cs_test', 'read_write', 1 );
		$this->mock_key_owner_with_cap( 1, true );

		$this->assertTrue(
			Widget_Auth::check_wc_auth( 'write' ),
			'read_write key should pass a write permission check'
		);
	}

	/**
	 * GREEN: A read-only key passes a 'read' permission check.
	 *
	 * @return void
	 */
	public function test_wc_auth_accepts_read_key_for_read(): void {
		$this->mock_basic_auth( 'ck_test', 'cs_test' );
		$this->mock_wc_key_lookup( 'cs_test', 'read', 1 );
		$this->mock_key_owner_with_cap( 1, true );

		$this->assertTrue(
			Widget_Auth::check_wc_auth( 'read' ),
			'Read-only key should pass a read permission check'
		);
	}

	/**
	 * RED: A valid key whose owner lacks manage_woocommerce is rejected.
	 *
	 * Before the fix, check_wc_auth() did not verify the key owner's
	 * WordPress capabilities. A key created by a demoted user still worked.
	 *
	 * @return void
	 */
	public function test_wc_auth_rejects_key_owner_without_capability(): void {
		$this->mock_basic_auth( 'ck_test', 'cs_test' );
		$this->mock_wc_key_lookup( 'cs_test', 'read_write', 1 );
		$this->mock_key_owner_with_cap( 1, false );

		$this->assertFalse(
			Widget_Auth::check_wc_auth( 'read' ),
			'Key owner without manage_woocommerce must be rejected'
		);
	}

	/**
	 * RED: A wrong consumer secret is rejected (baseline -- pre-existing).
	 *
	 * @return void
	 */
	public function test_wc_auth_rejects_wrong_secret(): void {
		$this->mock_basic_auth( 'ck_test', 'wrong_secret' );
		$this->mock_wc_key_lookup( 'cs_correct', 'read_write', 1 );

		$this->assertFalse(
			Widget_Auth::check_wc_auth( 'read' ),
			'Wrong consumer secret must be rejected'
		);
	}

	/**
	 * RED: Missing Authorization header is rejected (baseline).
	 *
	 * @return void
	 */
	public function test_wc_auth_rejects_missing_auth_header(): void {
		unset( $_SERVER['HTTP_AUTHORIZATION'] );

		$this->assertFalse(
			Widget_Auth::check_wc_auth( 'read' ),
			'Missing auth header must be rejected'
		);
	}

	/**
	 * GREEN: check_permission passes 'read' for GET, 'write' for POST.
	 *
	 * @return void
	 */
	public function test_check_permission_maps_http_method_to_permission_level(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		// GET request -- should require 'read'.
		$get_request = Mockery::mock( 'WP_REST_Request' );
		$get_request->shouldReceive( 'get_method' )->andReturn( 'GET' );

		$this->mock_basic_auth( 'ck_test', 'cs_test' );
		$this->mock_wc_key_lookup( 'cs_test', 'read', 1 );
		$this->mock_key_owner_with_cap( 1, true );

		$this->assertTrue(
			Rest_Bridge::check_permission( $get_request ),
			'GET request with read key should be allowed'
		);
	}

	/**
	 * RED: POST with a read-only key is rejected at the permission layer.
	 *
	 * @return void
	 */
	public function test_check_permission_rejects_post_with_read_key(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$post_request = Mockery::mock( 'WP_REST_Request' );
		$post_request->shouldReceive( 'get_method' )->andReturn( 'POST' );

		$this->mock_basic_auth( 'ck_test', 'cs_test' );
		$this->mock_wc_key_lookup( 'cs_test', 'read', 1 );
		$this->mock_key_owner_with_cap( 1, true );

		$this->assertFalse(
			Rest_Bridge::check_permission( $post_request ),
			'POST request with read-only key must be rejected'
		);
	}

	// ---------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------

	/**
	 * Capture register_rest_route calls for route inspection.
	 *
	 * @return void
	 */
	private function capture_route_registrations(): void {
		$routes = &$this->registered_routes;
		Functions\when( 'register_rest_route' )->alias(
			function ( $namespace, $route, $args ) use ( &$routes ) {
				$routes[] = array( $namespace, $route, $args );
			}
		);
	}

	/**
	 * Set up a Basic Auth header in $_SERVER.
	 *
	 * @param string $key    Consumer key.
	 * @param string $secret Consumer secret.
	 * @return void
	 */
	private function mock_basic_auth( $key, $secret ): void {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode( "$key:$secret" );

		Functions\when( 'sanitize_text_field' )->alias(
			function ( $str ) {
				return $str;
			}
		);
		Functions\when( 'wp_unslash' )->alias(
			function ( $str ) {
				return $str;
			}
		);
	}

	/**
	 * Mock the $wpdb lookup for a WC API key.
	 *
	 * @param string $stored_secret The consumer_secret stored in the DB.
	 * @param string $permissions   'read', 'write', or 'read_write'.
	 * @param int    $user_id       WordPress user ID who owns the key.
	 * @return void
	 */
	private function mock_wc_key_lookup( $stored_secret, $permissions, $user_id ): void {
		Functions\when( 'wc_api_hash' )->alias(
			function ( $key ) {
				return hash( 'sha256', $key );
			}
		);

		$row              = new \stdClass();
		$row->consumer_secret = $stored_secret;
		$row->permissions     = $permissions;
		$row->user_id         = $user_id;

		$wpdb         = Mockery::mock( 'wpdb' );
		$wpdb->prefix = 'wp_';
		$wpdb->shouldReceive( 'prepare' )->andReturnUsing(
			function () {
				return 'prepared_query';
			}
		);
		$wpdb->shouldReceive( 'get_row' )->andReturn( $row );

		$GLOBALS['wpdb'] = $wpdb;
	}

	/**
	 * Mock get_userdata to return a user with or without manage_woocommerce.
	 *
	 * @param int  $user_id WordPress user ID.
	 * @param bool $has_cap Whether the user has manage_woocommerce.
	 * @return void
	 */
	private function mock_key_owner_with_cap( $user_id, $has_cap ): void {
		$user = Mockery::mock( 'WP_User' );
		$user->shouldReceive( 'has_cap' )
			->with( 'manage_woocommerce' )
			->andReturn( $has_cap );

		Functions\when( 'get_userdata' )->alias(
			function ( $id ) use ( $user_id, $user ) {
				return ( $id === $user_id ) ? $user : false;
			}
		);
	}
}
