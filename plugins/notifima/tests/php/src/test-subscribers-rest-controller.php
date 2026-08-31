<?php
/**
 * Tests for Notifima\RestAPI\Controllers\Subscribers.
 *
 * @package Notifima
 */

use Notifima\RestAPI\Controllers\Subscribers;

/**
 * Covers the `notifima/v1/subscribers` REST controller: permission gating on
 * both routes, and the subscribe/unsubscribe REST flows dispatched through
 * the real WP_REST_Server so routing + permission_callback + handler all run
 * together.
 */
class Notifima_Subscribers_Rest_Controller_Test extends WP_UnitTestCase {

    /**
     * The id of a product used across tests.
     *
     * @var int
     */
    private $product_id;

    /**
     * The controller instance under test, for direct permission-check calls.
     *
     * @var Subscribers
     */
    private $controller;

    /**
     * Create a fresh product and controller instance before each test, and
     * reset the process-wide state that `Notifima\Notifima` and
     * `Notifima\Setting` cache in memory rather than resolve per-request
     * (see `set_current_user()` and the settings reset below) - without
     * this, state from one test leaks into the next since neither cache is
     * rebuilt between tests the way the database is.
     *
     * @return void
     */
    public function set_up() {
        parent::set_up();

        $product = new WC_Product_Simple();
        $product->set_name( 'Subscribers REST Test Product' );
        $product->set_regular_price( '10.00' );
        $product->set_stock_status( 'outofstock' );
        $product->save();

        $this->product_id = $product->get_id();
        $this->controller = new Subscribers();

        $this->set_current_user( 0 );
        Notifima()->setting->update_setting( 'is_guest_subscriptions_enable', '' );
    }

    /**
     * Switch the current user for both WordPress and the Notifima plugin
     * container. `Notifima()->current_user`/`current_user_id` are captured
     * once in `Notifima::init_classes()` at boot rather than resolved fresh
     * per request - `wp_set_current_user()` alone (which is what a real
     * request relies on, since the container is rebuilt on every real page
     * load) doesn't reach them in a long-running test process, so the
     * REST controller's permission checks would keep seeing whatever user
     * was current the first time the plugin booted.
     *
     * @param int $user_id User id, or 0 for a logged-out visitor.
     * @return void
     */
    private function set_current_user( $user_id ) {
        wp_set_current_user( $user_id );

        Notifima()->current_user_id = $user_id;
        Notifima()->current_user    = $user_id ? get_userdata( $user_id ) : new WP_User( 0 );
    }

    /**
     * Build a REST request with a valid `wp_rest` nonce header, since
     * get_items()/update_item() validate the nonce themselves rather than
     * relying on the REST server's cookie-auth middleware.
     *
     * @param string $method HTTP method.
     * @param string $route  Route, relative to the notifima/v1 namespace.
     * @return WP_REST_Request
     */
    private function build_request( $method, $route ) {
        $request = new WP_REST_Request( $method, '/notifima/v1' . $route );
        $request->add_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );

        return $request;
    }

    /**
     * A user without `manage_options` (or the filtered `get_subscribers`
     * capability) should not be allowed to list subscribers.
     *
     * @return void
     */
    public function test_get_items_permissions_check_denies_a_user_without_capability() {
        $this->set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

        $result = $this->controller->get_items_permissions_check( $this->build_request( 'GET', '/subscribers' ) );

        $this->assertWPError( $result );
        $this->assertSame( 403, $result->get_error_data()['status'] );
    }

    /**
     * A logged-out visitor is denied with 401, not 403, since they aren't
     * logged in at all.
     *
     * @return void
     */
    public function test_get_items_permissions_check_denies_a_logged_out_visitor_with_401() {
        $result = $this->controller->get_items_permissions_check( $this->build_request( 'GET', '/subscribers' ) );

        $this->assertWPError( $result );
        $this->assertSame( 401, $result->get_error_data()['status'] );
    }

    /**
     * An administrator should be allowed to list subscribers.
     *
     * @return void
     */
    public function test_get_items_permissions_check_allows_an_administrator() {
        $this->set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

        $result = $this->controller->get_items_permissions_check( $this->build_request( 'GET', '/subscribers' ) );

        $this->assertTrue( $result );
    }

    /**
     * Dispatching GET /subscribers without a valid nonce should fail even
     * for an otherwise-authorized administrator, since get_items() validates
     * the nonce itself before doing any work.
     *
     * @return void
     */
    public function test_get_items_rejects_a_request_with_an_invalid_nonce() {
        $this->set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

        $request = new WP_REST_Request( 'GET', '/notifima/v1/subscribers' );
        $request->add_header( 'X-WP-Nonce', 'not-a-real-nonce' );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 403, $response->get_status() );
    }

    /**
     * Dispatching GET /subscribers as an administrator should return the
     * subscribed record and report accurate per-status counts in headers.
     *
     * @return void
     */
    public function test_get_items_returns_subscribers_and_status_count_headers() {
        $this->set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

        \Notifima\Subscriber::insert_subscriber( 'active@example.com', $this->product_id );
        \Notifima\Subscriber::insert_subscriber( 'left@example.com', $this->product_id );
        \Notifima\Subscriber::remove_subscriber( $this->product_id, 'left@example.com' );

        $response = rest_get_server()->dispatch( $this->build_request( 'GET', '/subscribers' ) );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertCount( 2, $data );
        $this->assertSame( 1, $response->get_headers()['X-Subscribed'] );
        $this->assertSame( 1, $response->get_headers()['X-Unsubscribed'] );
        $this->assertSame( 2, $response->get_headers()['X-Total'] );
    }

    /**
     * A user with `manage_options` should always be allowed to update any
     * subscriber record, regardless of the requested action.
     *
     * @return void
     */
    public function test_update_item_permissions_check_allows_an_administrator() {
        $this->set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

        $request = $this->build_request( 'POST', '/subscribers/1' );
        $request->set_param( 'action', 'unsubscribe' );
        $request->set_param( 'customer_email', 'someone-else@example.com' );

        $this->assertTrue( $this->controller->update_item_permissions_check( $request ) );
    }

    /**
     * A logged-out visitor is denied when guest subscriptions are disabled
     * (the default).
     *
     * @return void
     */
    public function test_update_item_permissions_check_denies_a_guest_when_guest_subscriptions_are_disabled() {
        Notifima()->setting->update_setting( 'is_guest_subscriptions_enable', '' );

        $result = $this->controller->update_item_permissions_check( $this->build_request( 'POST', '/subscribers/1' ) );

        $this->assertWPError( $result );
        $this->assertSame( 401, $result->get_error_data()['status'] );
    }

    /**
     * A logged-out visitor is allowed once the store opts in to guest
     * subscriptions via the `is_guest_subscriptions_enable` setting.
     *
     * @return void
     */
    public function test_update_item_permissions_check_allows_a_guest_when_guest_subscriptions_are_enabled() {
        Notifima()->setting->update_setting( 'is_guest_subscriptions_enable', 'everyone' );

        $result = $this->controller->update_item_permissions_check( $this->build_request( 'POST', '/subscribers/1' ) );

        $this->assertTrue( $result );
    }

    /**
     * A logged-in user (below `manage_options`) subscribing (i.e. any
     * action other than `unsubscribe`) is always allowed - it only opts
     * their own supplied email into alerts.
     *
     * @return void
     */
    public function test_update_item_permissions_check_allows_a_logged_in_user_to_subscribe() {
        $this->set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

        $request = $this->build_request( 'POST', '/subscribers/1' );
        $request->set_param( 'action', 'subscribe' );

        $this->assertTrue( $this->controller->update_item_permissions_check( $request ) );
    }

    /**
     * A logged-in user unsubscribing their own email address is allowed.
     *
     * @return void
     */
    public function test_update_item_permissions_check_allows_a_logged_in_user_to_unsubscribe_their_own_email() {
        $user_id = self::factory()->user->create(
            array(
                'role'       => 'subscriber',
                'user_email' => 'me@example.com',
            )
        );
        $this->set_current_user( $user_id );

        $request = $this->build_request( 'POST', '/subscribers/1' );
        $request->set_param( 'action', 'unsubscribe' );
        $request->set_param( 'customer_email', 'me@example.com' );

        $this->assertTrue( $this->controller->update_item_permissions_check( $request ) );
    }

    /**
     * A logged-in user unsubscribing with no `customer_email` param is
     * allowed - the handler falls back to the requester's own email, so
     * there's nothing to own-check yet.
     *
     * @return void
     */
    public function test_update_item_permissions_check_allows_unsubscribe_with_no_email_supplied() {
        $this->set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

        $request = $this->build_request( 'POST', '/subscribers/1' );
        $request->set_param( 'action', 'unsubscribe' );

        $this->assertTrue( $this->controller->update_item_permissions_check( $request ) );
    }

    /**
     * A logged-in user unsubscribing someone else's email address is denied
     * with 403 - `unsubscribe` mutates another person's existing record.
     *
     * @return void
     */
    public function test_update_item_permissions_check_denies_a_logged_in_user_unsubscribing_another_email() {
        $user_id = self::factory()->user->create(
            array(
                'role'       => 'subscriber',
                'user_email' => 'me@example.com',
            )
        );
        $this->set_current_user( $user_id );

        $request = $this->build_request( 'POST', '/subscribers/1' );
        $request->set_param( 'action', 'unsubscribe' );
        $request->set_param( 'customer_email', 'someone-else@example.com' );

        $result = $this->controller->update_item_permissions_check( $request );

        $this->assertWPError( $result );
        $this->assertSame( 403, $result->get_error_data()['status'] );
    }

    /**
     * Dispatching PUT /subscribers/{id} with action=subscribe and a valid
     * email/product should create a subscriber row and report success.
     *
     * @return void
     */
    public function test_update_item_subscribe_creates_a_subscriber_and_reports_success() {
        $this->set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

        $request = $this->build_request( 'PUT', '/subscribers/1' );
        $request->set_param( 'action', 'subscribe' );
        $request->set_param( 'customer_email', 'shopper@example.com' );
        $request->set_param( 'product_id', $this->product_id );
        $request->set_param( 'product_title', 'Subscribers REST Test Product' );

        $response = rest_get_server()->dispatch( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertTrue( $data['status'] );
        $this->assertNotEmpty( \Notifima\Subscriber::is_already_subscribed( 'shopper@example.com', $this->product_id ) );
    }

    /**
     * Subscribing with an invalid email should report failure without
     * touching the database.
     *
     * @return void
     */
    public function test_update_item_subscribe_reports_failure_for_an_invalid_email() {
        $this->set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

        $request = $this->build_request( 'PUT', '/subscribers/1' );
        $request->set_param( 'action', 'subscribe' );
        $request->set_param( 'customer_email', 'not-an-email' );
        $request->set_param( 'product_id', $this->product_id );

        $response = rest_get_server()->dispatch( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertFalse( $data['status'] );
        $this->assertEmpty( \Notifima\Subscriber::is_already_subscribed( 'not-an-email', $this->product_id ) );
    }

    /**
     * Subscribing an email that's already subscribed to the product should
     * report failure and flag `already_subscribed` rather than duplicating
     * the row.
     *
     * @return void
     */
    public function test_update_item_subscribe_reports_already_subscribed_without_duplicating() {
        $this->set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        \Notifima\Subscriber::insert_subscriber( 'shopper@example.com', $this->product_id );

        $request = $this->build_request( 'PUT', '/subscribers/1' );
        $request->set_param( 'action', 'subscribe' );
        $request->set_param( 'customer_email', 'shopper@example.com' );
        $request->set_param( 'product_id', $this->product_id );

        $response = rest_get_server()->dispatch( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertFalse( $data['status'] );
        $this->assertTrue( $data['already_subscribed'] );
        $this->assertCount( 1, \Notifima\Subscriber::get_product_subscribers_email( $this->product_id ) );
    }

    /**
     * Subscribing to a variation should store the subscription against the
     * variation id, not the parent product id.
     *
     * @return void
     */
    public function test_update_item_subscribe_uses_the_variation_id_when_supplied() {
        $this->set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

        $request = $this->build_request( 'PUT', '/subscribers/1' );
        $request->set_param( 'action', 'subscribe' );
        $request->set_param( 'customer_email', 'shopper@example.com' );
        $request->set_param( 'product_id', $this->product_id );
        $request->set_param( 'variation_id', 999 );

        rest_get_server()->dispatch( $request );

        $this->assertNotEmpty( \Notifima\Subscriber::is_already_subscribed( 'shopper@example.com', 999 ) );
        $this->assertEmpty( \Notifima\Subscriber::is_already_subscribed( 'shopper@example.com', $this->product_id ) );
    }

    /**
     * Dispatching PUT /subscribers/{id} with action=unsubscribe for an
     * existing subscription should mark it unsubscribed and report success.
     *
     * @return void
     */
    public function test_update_item_unsubscribe_marks_the_subscriber_unsubscribed() {
        $this->set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
        \Notifima\Subscriber::insert_subscriber( 'shopper@example.com', $this->product_id );

        $request = $this->build_request( 'PUT', '/subscribers/1' );
        $request->set_param( 'action', 'unsubscribe' );
        $request->set_param( 'customer_email', 'shopper@example.com' );
        $request->set_param( 'product_id', $this->product_id );

        $response = rest_get_server()->dispatch( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertTrue( $data['status'] );
        $this->assertEmpty( \Notifima\Subscriber::is_already_subscribed( 'shopper@example.com', $this->product_id ) );
    }

    /**
     * Unsubscribing an email that was never subscribed should report
     * failure rather than a false success.
     *
     * @return void
     */
    public function test_update_item_unsubscribe_reports_failure_when_not_subscribed() {
        $this->set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

        $request = $this->build_request( 'PUT', '/subscribers/1' );
        $request->set_param( 'action', 'unsubscribe' );
        $request->set_param( 'customer_email', 'nobody@example.com' );
        $request->set_param( 'product_id', $this->product_id );

        $response = rest_get_server()->dispatch( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertFalse( $data['status'] );
    }

    /**
     * Unsubscribing with no email supplied and no logged-in user should
     * report that an email is required.
     *
     * @return void
     */
    public function test_update_item_unsubscribe_reports_failure_when_email_is_missing_and_logged_out() {
        // Guest subscriptions must be enabled or the permission check itself
        // would reject the request before the handler ever runs.
        Notifima()->setting->update_setting( 'is_guest_subscriptions_enable', 'everyone' );

        $request = $this->build_request( 'PUT', '/subscribers/1' );
        $request->set_param( 'action', 'unsubscribe' );
        $request->set_param( 'product_id', $this->product_id );

        $response = rest_get_server()->dispatch( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertFalse( $data['status'] );
    }

    /**
     * An unrecognized `action` value should resolve to a plain `false`
     * response rather than an error.
     *
     * @return void
     */
    public function test_update_item_returns_false_for_an_unrecognized_action() {
        $this->set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

        $request = $this->build_request( 'PUT', '/subscribers/1' );
        $request->set_param( 'action', 'not-a-real-action' );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertFalse( $response->get_data() );
    }
}
