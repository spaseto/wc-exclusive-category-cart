<?php
/**
 * Plugin Name: WC Exclusive Category Cart (Pickup Locations)
 * Description: Enforces separate carts for an exclusive category and aligns pickup methods with pickup locations.
 * Version: 1.0.0
 * Requires Plugins: woocommerce
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wc-exclusive-category-cart
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WC_ECC_VERSION', '1.0.0' );
define( 'WC_ECC_TEXT_DOMAIN', 'wc-exclusive-category-cart' );

register_activation_hook( __FILE__, 'wc_ecc_activate' );
add_action( 'plugins_loaded', 'wc_ecc_bootstrap' );

/**
 * Ensure defaults exist without overwriting existing settings.
 */
function wc_ecc_activate() {
	add_option( 'wc_ecc_exclusive_term_id', 0 );
	add_option( 'wc_ecc_pickup_method_a', '' );
	add_option( 'wc_ecc_pickup_method_b', '' );
	add_option( 'wc_ecc_autoselect_enabled', 'yes' );
	add_option( 'wc_ecc_hide_other_pickup', 'yes' );
	add_option( 'wc_ecc_test_mode', 'no' );
	add_option( 'wc_ecc_resolve_mode', 'block' );
}

/**
 * Plugin bootstrap.
 */
function wc_ecc_bootstrap() {
	add_action( 'admin_menu', 'wc_ecc_register_settings_page' );
	add_action( 'admin_init', 'wc_ecc_register_settings' );

	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'wc_ecc_admin_notice_woocommerce_inactive' );
		return;
	}

	add_filter( 'woocommerce_add_to_cart_validation', 'wc_ecc_add_to_cart_validation', 20, 5 );
	add_action( 'woocommerce_check_cart_items', 'wc_ecc_check_cart_items_safety_net' );
	add_action( 'woocommerce_cart_loaded_from_session', 'wc_ecc_sync_pickup_method_in_session', 20, 1 );
	add_action( 'woocommerce_checkout_update_order_review', 'wc_ecc_sync_pickup_method_in_session', 20, 1 );
	add_filter( 'woocommerce_package_rates', 'wc_ecc_filter_package_rates', 20, 2 );
	add_action( 'woocommerce_before_cart', 'wc_ecc_maybe_show_missing_pickup_notice' );
	add_action( 'woocommerce_before_checkout_form', 'wc_ecc_maybe_show_missing_pickup_notice' );
	add_action( 'template_redirect', 'wc_ecc_handle_clear_add_link', 1 );
	add_action( 'wp_enqueue_scripts', 'wc_ecc_enqueue_notice_styles' );
}

/**
 * Admin notice if WooCommerce is missing.
 */
function wc_ecc_admin_notice_woocommerce_inactive() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	?>
	<div class="notice notice-error">
		<p>
			<?php
			echo esc_html__(
				'WC Exclusive Category Cart (Pickup Locations) requires WooCommerce to be active.',
				WC_ECC_TEXT_DOMAIN
			);
			?>
		</p>
	</div>
	<?php
}

/**
 * Register WooCommerce submenu page.
 */
function wc_ecc_register_settings_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	add_submenu_page(
		'woocommerce',
		__( 'Pickup Cart Rules', WC_ECC_TEXT_DOMAIN ),
		__( 'Pickup Cart Rules', WC_ECC_TEXT_DOMAIN ),
		'manage_woocommerce',
		'wc-ecc-pickup-cart-rules',
		'wc_ecc_render_settings_page'
	);
}

/**
 * Register plugin options.
 */
function wc_ecc_register_settings() {
	register_setting(
		'wc_ecc_settings',
		'wc_ecc_exclusive_term_id',
		array(
			'type'              => 'integer',
			'sanitize_callback' => 'wc_ecc_sanitize_term_id',
			'default'           => 0,
		)
	);

	register_setting(
		'wc_ecc_settings',
		'wc_ecc_pickup_method_a',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'wc_ecc_sanitize_method_id',
			'default'           => '',
		)
	);

	register_setting(
		'wc_ecc_settings',
		'wc_ecc_pickup_method_b',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'wc_ecc_sanitize_method_id',
			'default'           => '',
		)
	);

	register_setting(
		'wc_ecc_settings',
		'wc_ecc_autoselect_enabled',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'wc_ecc_sanitize_checkbox',
			'default'           => 'yes',
		)
	);

	register_setting(
		'wc_ecc_settings',
		'wc_ecc_hide_other_pickup',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'wc_ecc_sanitize_checkbox',
			'default'           => 'yes',
		)
	);

	register_setting(
		'wc_ecc_settings',
		'wc_ecc_test_mode',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'wc_ecc_sanitize_checkbox',
			'default'           => 'no',
		)
	);

	register_setting(
		'wc_ecc_settings',
		'wc_ecc_resolve_mode',
		array(
			'type'              => 'string',
			'sanitize_callback' => 'wc_ecc_sanitize_resolve_mode',
			'default'           => 'block',
		)
	);
}

/**
 * Sanitize product category term ID.
 *
 * @param mixed $value Posted value.
 * @return int
 */
function wc_ecc_sanitize_term_id( $value ) {
	$term_id = absint( $value );
	if ( $term_id <= 0 ) {
		return 0;
	}

	$term = get_term( $term_id, 'product_cat' );
	if ( ! $term || is_wp_error( $term ) ) {
		return 0;
	}

	return $term_id;
}

/**
 * Sanitize shipping method IDs.
 *
 * @param mixed $value Posted value.
 * @return string
 */
function wc_ecc_sanitize_method_id( $value ) {
	$value = is_scalar( $value ) ? sanitize_text_field( wp_unslash( (string) $value ) ) : '';
	return trim( $value );
}

/**
 * Sanitize yes/no checkboxes.
 *
 * @param mixed $value Posted value.
 * @return string
 */
function wc_ecc_sanitize_checkbox( $value ) {
	return in_array( $value, array( '1', 1, true, 'true', 'yes', 'on' ), true ) ? 'yes' : 'no';
}

/**
 * Sanitize resolve mode setting.
 *
 * @param mixed $value Posted value.
 * @return string
 */
function wc_ecc_sanitize_resolve_mode( $value ) {
	$value = is_scalar( $value ) ? sanitize_text_field( wp_unslash( (string) $value ) ) : '';
	return in_array( $value, array( 'block', 'clear_add' ), true ) ? $value : 'block';
}

/**
 * Render admin settings page.
 */
function wc_ecc_render_settings_page() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}

	$exclusive_term_id = absint( get_option( 'wc_ecc_exclusive_term_id', 0 ) );
	$pickup_method_a   = wc_ecc_get_pickup_method_a();
	$pickup_method_b   = wc_ecc_get_pickup_method_b();
	$autoselect        = wc_ecc_is_autoselect_enabled();
	$hide_other        = wc_ecc_is_hide_other_pickup_enabled();
	$test_mode         = wc_ecc_is_test_mode();
	$resolve_mode      = wc_ecc_get_resolve_mode();
	?>
	<div class="wrap">
		<h1><?php echo esc_html__( 'Pickup Cart Rules', WC_ECC_TEXT_DOMAIN ); ?></h1>

		<?php if ( empty( $pickup_method_a ) || empty( $pickup_method_b ) ) : ?>
			<div class="notice notice-warning">
				<p>
					<?php
					echo esc_html__(
						'Pickup method IDs are not fully configured. Shipping method auto-selection and rate hiding are inactive until both method IDs are set.',
						WC_ECC_TEXT_DOMAIN
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'wc_ecc_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">
						<label for="wc_ecc_exclusive_term_id">
							<?php echo esc_html__( 'Category A (exclusive)', WC_ECC_TEXT_DOMAIN ); ?>
						</label>
					</th>
					<td>
						<?php
						wp_dropdown_categories(
							array(
								'taxonomy'         => 'product_cat',
								'hide_empty'       => false,
								'name'             => 'wc_ecc_exclusive_term_id',
								'id'               => 'wc_ecc_exclusive_term_id',
								'orderby'          => 'name',
								'selected'         => $exclusive_term_id,
								'show_option_none' => __( '-- Select Category A --', WC_ECC_TEXT_DOMAIN ),
								'option_none_value'=> '0',
							)
						);
						?>
						<p class="description">
							<?php echo esc_html__( 'Children of this category are included automatically.', WC_ECC_TEXT_DOMAIN ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="wc_ecc_pickup_method_a">
							<?php echo esc_html__( 'Pickup method ID for Location A', WC_ECC_TEXT_DOMAIN ); ?>
						</label>
					</th>
					<td>
						<input
							type="text"
							class="regular-text"
							id="wc_ecc_pickup_method_a"
							name="wc_ecc_pickup_method_a"
							value="<?php echo esc_attr( $pickup_method_a ); ?>"
							placeholder="local_pickup:3"
						/>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="wc_ecc_pickup_method_b">
							<?php echo esc_html__( 'Pickup method ID for Location B', WC_ECC_TEXT_DOMAIN ); ?>
						</label>
					</th>
					<td>
						<input
							type="text"
							class="regular-text"
							id="wc_ecc_pickup_method_b"
							name="wc_ecc_pickup_method_b"
							value="<?php echo esc_attr( $pickup_method_b ); ?>"
							placeholder="local_pickup:4"
						/>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php echo esc_html__( 'Auto-select pickup method', WC_ECC_TEXT_DOMAIN ); ?></th>
					<td>
						<label for="wc_ecc_autoselect_enabled">
							<input
								type="checkbox"
								id="wc_ecc_autoselect_enabled"
								name="wc_ecc_autoselect_enabled"
								value="yes"
								<?php checked( $autoselect ); ?>
							/>
							<?php echo esc_html__( 'Automatically set the preferred pickup method on cart/checkout.', WC_ECC_TEXT_DOMAIN ); ?>
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php echo esc_html__( 'Hide the wrong pickup method', WC_ECC_TEXT_DOMAIN ); ?></th>
					<td>
						<label for="wc_ecc_hide_other_pickup">
							<input
								type="checkbox"
								id="wc_ecc_hide_other_pickup"
								name="wc_ecc_hide_other_pickup"
								value="yes"
								<?php checked( $hide_other ); ?>
							/>
							<?php echo esc_html__( 'Hide Location A method when Location B is needed, and vice versa.', WC_ECC_TEXT_DOMAIN ); ?>
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php echo esc_html__( 'Test mode', WC_ECC_TEXT_DOMAIN ); ?></th>
					<td>
						<label for="wc_ecc_test_mode">
							<input
								type="checkbox"
								id="wc_ecc_test_mode"
								name="wc_ecc_test_mode"
								value="yes"
								<?php checked( $test_mode ); ?>
							/>
							<?php echo esc_html__( 'Never block. Only warn and log decisions.', WC_ECC_TEXT_DOMAIN ); ?>
						</label>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="wc_ecc_resolve_mode">
							<?php echo esc_html__( 'Smart resolve mode', WC_ECC_TEXT_DOMAIN ); ?>
						</label>
					</th>
					<td>
						<select id="wc_ecc_resolve_mode" name="wc_ecc_resolve_mode">
							<option value="block" <?php selected( $resolve_mode, 'block' ); ?>>
								<?php echo esc_html__( 'Block', WC_ECC_TEXT_DOMAIN ); ?>
							</option>
							<option value="clear_add" <?php selected( $resolve_mode, 'clear_add' ); ?>>
								<?php echo esc_html__( 'Clear cart and add clicked item', WC_ECC_TEXT_DOMAIN ); ?>
							</option>
						</select>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>

		<h2><?php echo esc_html__( 'How to find pickup method IDs', WC_ECC_TEXT_DOMAIN ); ?></h2>
		<p>
			<?php echo esc_html__( 'Go to WooCommerce -> Settings -> Shipping -> Shipping zones. Edit a zone, open each pickup rate, and note the rate ID format like local_pickup:3.', WC_ECC_TEXT_DOMAIN ); ?>
		</p>
		<p>
			<code>local_pickup:3</code>, <code>local_pickup:4</code>
		</p>
	</div>
	<?php
}

/**
 * Add-to-cart validation: prevent mixing Category A and non-A carts.
 *
 * @param bool  $passed       Whether validation passed.
 * @param int   $product_id   Product ID.
 * @param int   $quantity     Quantity.
 * @param int   $variation_id Variation ID.
 * @param array $variations   Variation attributes.
 * @return bool
 */
function wc_ecc_add_to_cart_validation( $passed, $product_id, $quantity, $variation_id = 0, $variations = array() ) {
	if ( ! $passed ) {
		return false;
	}

	if ( ! wc_ecc_has_exclusive_category() || ! function_exists( 'WC' ) || ! WC()->cart ) {
		return $passed;
	}

	$cart_mode     = wc_ecc_get_cart_mode( WC()->cart );
	$incoming_mode = wc_ecc_is_product_exclusive( $product_id, $variation_id ) ? 'exclusive' : 'regular';

	wc_ecc_log(
		sprintf(
			'Add-to-cart check: cart_mode=%s incoming_mode=%s product_id=%d variation_id=%d',
			$cart_mode,
			$incoming_mode,
			absint( $product_id ),
			absint( $variation_id )
		)
	);

	if ( ! in_array( $cart_mode, array( 'exclusive', 'regular' ), true ) ) {
		return $passed;
	}

	if ( $cart_mode === $incoming_mode ) {
		return $passed;
	}

	$message = wc_ecc_get_mixing_notice_message( $incoming_mode );

	if ( wc_ecc_is_test_mode() ) {
		wc_ecc_add_notice_once(
			sprintf(
				/* translators: %s reason text. */
				__( 'Test mode: %s', WC_ECC_TEXT_DOMAIN ),
				$message
			),
			'notice'
		);
		wc_ecc_log( 'Test mode enabled. Allowed mixed add-to-cart request.', 'warning' );
		return true;
	}

	if ( 'clear_add' === wc_ecc_get_resolve_mode() ) {
		$url = wc_ecc_get_clear_add_url( $product_id, $variation_id, $quantity, $variations );
		wc_ecc_add_notice_once(
			wp_kses_post(
				sprintf(
					/* translators: 1: reason text, 2: action URL. */
					__( '%1$s <a href="%2$s">Clear cart and add this item</a>.', WC_ECC_TEXT_DOMAIN ),
					esc_html( $message ),
					esc_url( $url )
				)
			),
			'error'
		);
		wc_ecc_log( 'Blocked mixed add-to-cart. Offered clear-and-add link.', 'warning' );
		return false;
	}

	wc_ecc_add_notice_once( $message, 'error' );
	wc_ecc_log( 'Blocked mixed add-to-cart in block mode.', 'warning' );
	return false;
}

/**
 * Safety net validation for direct checkout/cart access.
 */
function wc_ecc_check_cart_items_safety_net() {
	if ( ! wc_ecc_has_exclusive_category() || ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
		return;
	}

	if ( 'mixed' !== wc_ecc_get_cart_mode( WC()->cart ) ) {
		return;
	}

	$message = __(
		'Your cart contains products for both pickup locations. Please place separate orders for Location A and Location B pickups.',
		WC_ECC_TEXT_DOMAIN
	);

	if ( wc_ecc_is_test_mode() ) {
		wc_ecc_add_notice_once(
			sprintf(
				/* translators: %s reason text. */
				__( 'Test mode: %s', WC_ECC_TEXT_DOMAIN ),
				$message
			),
			'notice'
		);
		wc_ecc_log( 'Safety net detected mixed cart (test mode, not blocking).', 'warning' );
		return;
	}

	wc_ecc_add_notice_once( $message, 'error' );
	wc_ecc_log( 'Safety net blocked mixed cart.', 'warning' );
}

/**
 * Auto-select desired pickup method when possible.
 *
 * @param mixed $context Optional action payload.
 */
function wc_ecc_sync_pickup_method_in_session( $context = null ) {
	if ( is_admin() && ! wp_doing_ajax() ) {
		return;
	}

	if ( ! function_exists( 'WC' ) || ! WC()->session || ! WC()->cart || WC()->cart->is_empty() ) {
		return;
	}

	if ( ! wc_ecc_pickup_ids_configured() ) {
		wc_ecc_log( 'Pickup IDs are not configured. Shipping method enforcement skipped.', 'warning' );
		return;
	}

	$desired_method = wc_ecc_get_desired_pickup_method( WC()->cart );
	if ( empty( $desired_method ) ) {
		return;
	}

	wc_ecc_log(
		sprintf(
			'Sync pickup method: cart_mode=%s desired=%s',
			wc_ecc_get_cart_mode( WC()->cart ),
			$desired_method
		)
	);

	WC()->session->set( 'wc_ecc_missing_desired_rate', 'no' );

	if ( wc_ecc_is_test_mode() ) {
		wc_ecc_log( 'Test mode: skipped forcing chosen shipping methods.', 'info' );
		return;
	}

	if ( ! wc_ecc_is_autoselect_enabled() ) {
		return;
	}

	$packages = WC()->shipping()->get_packages();
	$chosen   = (array) WC()->session->get( 'chosen_shipping_methods', array() );
	$changed  = false;

	if ( empty( $packages ) ) {
		if ( ! isset( $chosen[0] ) || $desired_method !== $chosen[0] ) {
			$chosen[0] = $desired_method;
			$changed   = true;
			wc_ecc_log( sprintf( 'Forced chosen shipping method for package 0 to %s.', $desired_method ) );
		}
	} else {
		foreach ( $packages as $package_index => $package ) {
			$rates = isset( $package['rates'] ) && is_array( $package['rates'] ) ? $package['rates'] : array();
			if ( ! empty( $rates ) && ! array_key_exists( $desired_method, $rates ) ) {
				WC()->session->set( 'wc_ecc_missing_desired_rate', 'yes' );
				wc_ecc_log(
					sprintf(
						'Desired pickup method %s is missing in package %d rates; skipped forcing.',
						$desired_method,
						absint( $package_index )
					),
					'warning'
				);
				continue;
			}

			if ( ! isset( $chosen[ $package_index ] ) || $desired_method !== $chosen[ $package_index ] ) {
				$chosen[ $package_index ] = $desired_method;
				$changed                  = true;
				wc_ecc_log(
					sprintf(
						'Forced chosen shipping method for package %d to %s.',
						absint( $package_index ),
						$desired_method
					)
				);
			}
		}
	}

	if ( $changed ) {
		WC()->session->set( 'chosen_shipping_methods', $chosen );
	}
}

/**
 * Hide incompatible pickup rate when desired method exists.
 *
 * @param array $rates   Package rates.
 * @param array $package Shipping package.
 * @return array
 */
function wc_ecc_filter_package_rates( $rates, $package ) {
	if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
		return $rates;
	}

	if ( ! wc_ecc_pickup_ids_configured() ) {
		return $rates;
	}

	$desired_method = wc_ecc_get_desired_pickup_method( WC()->cart );
	if ( empty( $desired_method ) ) {
		return $rates;
	}

	if ( ! array_key_exists( $desired_method, $rates ) ) {
		if ( WC()->session ) {
			WC()->session->set( 'wc_ecc_missing_desired_rate', 'yes' );
		}
		wc_ecc_log(
			sprintf(
				'Desired pickup method %s missing in package rates: %s',
				$desired_method,
				implode( ', ', array_keys( $rates ) )
			),
			'warning'
		);
		return $rates;
	}

	if ( ! wc_ecc_is_hide_other_pickup_enabled() ) {
		return $rates;
	}

	$method_a = wc_ecc_get_pickup_method_a();
	$method_b = wc_ecc_get_pickup_method_b();
	$wrong    = ( $desired_method === $method_a ) ? $method_b : $method_a;

	if ( empty( $wrong ) || ! array_key_exists( $wrong, $rates ) ) {
		return $rates;
	}

	if ( wc_ecc_is_test_mode() ) {
		wc_ecc_log(
			sprintf(
				'Test mode: would hide shipping rate %s while desired method is %s.',
				$wrong,
				$desired_method
			),
			'info'
		);
		return $rates;
	}

	unset( $rates[ $wrong ] );
	wc_ecc_log(
		sprintf(
			'Hidden shipping rate %s while desired method is %s.',
			$wrong,
			$desired_method
		)
	);

	return $rates;
}

/**
 * Show missing-desired-pickup notice on cart and checkout.
 */
function wc_ecc_maybe_show_missing_pickup_notice() {
	if ( ! function_exists( 'WC' ) || ! WC()->session || ! WC()->cart || WC()->cart->is_empty() ) {
		return;
	}

	if ( ! wc_ecc_pickup_ids_configured() ) {
		return;
	}

	if ( 'yes' !== WC()->session->get( 'wc_ecc_missing_desired_rate', 'no' ) ) {
		return;
	}

	wc_ecc_add_notice_once(
		__(
			'Pickup option for this cart is not available. Please check shipping zones and pickup configuration.',
			WC_ECC_TEXT_DOMAIN
		),
		'error'
	);
}

/**
 * Handles secure clear-cart-and-add links.
 */
function wc_ecc_handle_clear_add_link() {
	if ( is_admin() || ! function_exists( 'WC' ) || ! WC()->cart ) {
		return;
	}

	$action = isset( $_GET['wc_ecc_action'] ) ? sanitize_text_field( wp_unslash( $_GET['wc_ecc_action'] ) ) : '';
	if ( 'clear_add' !== $action ) {
		return;
	}

	$product_id   = isset( $_GET['product_id'] ) ? absint( wp_unslash( $_GET['product_id'] ) ) : 0;
	$variation_id = isset( $_GET['variation_id'] ) ? absint( wp_unslash( $_GET['variation_id'] ) ) : 0;
	$quantity     = isset( $_GET['quantity'] ) ? wc_stock_amount( wp_unslash( $_GET['quantity'] ) ) : 1;
	$nonce        = isset( $_GET['_wc_ecc_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wc_ecc_nonce'] ) ) : '';
	$variations   = array();

	if ( isset( $_GET['variations'] ) ) {
		$raw_variations = wp_unslash( (string) $_GET['variations'] );
		$decoded        = base64_decode( $raw_variations, true );
		if ( false !== $decoded ) {
			$parsed = json_decode( $decoded, true );
			if ( is_array( $parsed ) ) {
				$variations = wc_ecc_normalize_variations( $parsed );
			}
		}
	}

	$payload      = wc_ecc_build_clear_add_payload( $product_id, $variation_id, $quantity, $variations );
	$nonce_action = 'wc_ecc_clear_add_' . md5( wp_json_encode( $payload ) );

	if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, $nonce_action ) ) {
		wc_add_notice( __( 'The secure action link has expired. Please try again.', WC_ECC_TEXT_DOMAIN ), 'error' );
		wp_safe_redirect( wc_get_cart_url() );
		exit;
	}

	if ( $product_id <= 0 || $quantity <= 0 ) {
		wc_add_notice( __( 'Unable to process the selected product.', WC_ECC_TEXT_DOMAIN ), 'error' );
		wp_safe_redirect( wc_get_cart_url() );
		exit;
	}

	WC()->cart->empty_cart();
	$added = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variations );

	if ( ! $added && $variation_id > 0 && empty( $variations ) ) {
		$variation_product = wc_get_product( $variation_id );
		if ( $variation_product && $variation_product->is_type( 'variation' ) ) {
			$attrs = $variation_product->get_variation_attributes();
			$added = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $attrs );
		}
	}

	if ( $added ) {
		wc_add_notice( __( 'Cart cleared and the selected item was added.', WC_ECC_TEXT_DOMAIN ), 'success' );
		wc_ecc_log( 'Clear-and-add link used successfully.', 'info', true );
	} else {
		wc_add_notice( __( 'Cart was cleared, but the item could not be added.', WC_ECC_TEXT_DOMAIN ), 'error' );
		wc_ecc_log( 'Clear-and-add link failed to add product.', 'warning', true );
	}

	wp_safe_redirect( wc_get_cart_url() );
	exit;
}

/**
 * Build secure clear-add action URL.
 *
 * @param int   $product_id   Product ID.
 * @param int   $variation_id Variation ID.
 * @param int   $quantity     Quantity.
 * @param array $variations   Variation attributes.
 * @return string
 */
function wc_ecc_get_clear_add_url( $product_id, $variation_id, $quantity, $variations ) {
	$payload      = wc_ecc_build_clear_add_payload( $product_id, $variation_id, $quantity, $variations );
	$nonce_action = 'wc_ecc_clear_add_' . md5( wp_json_encode( $payload ) );

	$query_args = array(
		'wc_ecc_action' => 'clear_add',
		'product_id'    => $payload['product_id'],
		'variation_id'  => $payload['variation_id'],
		'quantity'      => $payload['quantity'],
		'variations'    => base64_encode( wp_json_encode( $payload['variations'] ) ),
		'_wc_ecc_nonce' => wp_create_nonce( $nonce_action ),
	);

	return add_query_arg( $query_args, wc_get_cart_url() );
}

/**
 * Create canonical payload for secure clear-add links.
 *
 * @param int   $product_id   Product ID.
 * @param int   $variation_id Variation ID.
 * @param int   $quantity     Quantity.
 * @param array $variations   Variation attributes.
 * @return array
 */
function wc_ecc_build_clear_add_payload( $product_id, $variation_id, $quantity, $variations ) {
	return array(
		'product_id'   => absint( $product_id ),
		'variation_id' => absint( $variation_id ),
		'quantity'     => max( 1, absint( $quantity ) ),
		'variations'   => wc_ecc_normalize_variations( $variations ),
	);
}

/**
 * Normalize variation attributes.
 *
 * @param array $variations Variation attributes.
 * @return array
 */
function wc_ecc_normalize_variations( $variations ) {
	if ( ! is_array( $variations ) ) {
		return array();
	}

	$normalized = array();
	foreach ( $variations as $attribute => $value ) {
		if ( ! is_scalar( $value ) ) {
			continue;
		}
		$normalized[ sanitize_key( (string) $attribute ) ] = wc_clean( (string) $value );
	}

	ksort( $normalized );
	return $normalized;
}

/**
 * Enqueue notice styles on cart and checkout only.
 */
function wc_ecc_enqueue_notice_styles() {
	if ( ! function_exists( 'is_cart' ) || ! function_exists( 'is_checkout' ) ) {
		return;
	}

	if ( ! is_cart() && ! is_checkout() ) {
		return;
	}

	wp_enqueue_style(
		'wc-ecc-notices',
		plugin_dir_url( __FILE__ ) . 'assets/css/notices.css',
		array(),
		WC_ECC_VERSION
	);
}

/**
 * Get Category A term IDs including descendants.
 *
 * @return int[]
 */
function wc_ecc_get_exclusive_term_ids() {
	static $cache = null;

	if ( null !== $cache ) {
		return $cache;
	}

	$root_term_id = absint( get_option( 'wc_ecc_exclusive_term_id', 0 ) );
	if ( $root_term_id <= 0 ) {
		$cache = array();
		return $cache;
	}

	$ids      = array( $root_term_id );
	$children = get_term_children( $root_term_id, 'product_cat' );
	if ( ! is_wp_error( $children ) && ! empty( $children ) ) {
		$ids = array_merge( $ids, $children );
	}

	$ids   = array_map( 'absint', $ids );
	$ids   = array_filter( $ids );
	$cache = array_values( array_unique( $ids ) );

	return $cache;
}

/**
 * Return true if category rule is configured.
 *
 * @return bool
 */
function wc_ecc_has_exclusive_category() {
	return absint( get_option( 'wc_ecc_exclusive_term_id', 0 ) ) > 0;
}

/**
 * Check if pickup IDs are configured.
 *
 * @return bool
 */
function wc_ecc_pickup_ids_configured() {
	return '' !== wc_ecc_get_pickup_method_a() && '' !== wc_ecc_get_pickup_method_b();
}

/**
 * Determine if a product belongs to the exclusive category set.
 *
 * @param int $product_id Product ID.
 * @param int $variation_id Variation ID.
 * @return bool
 */
function wc_ecc_is_product_exclusive( $product_id, $variation_id = 0 ) {
	$term_ids = wc_ecc_get_exclusive_term_ids();
	if ( empty( $term_ids ) ) {
		return false;
	}

	$check_product_id = absint( $product_id );
	if ( $variation_id > 0 ) {
		$parent_id = wp_get_post_parent_id( $variation_id );
		if ( $parent_id > 0 ) {
			$check_product_id = absint( $parent_id );
		}
	}

	return has_term( $term_ids, 'product_cat', $check_product_id );
}

/**
 * Collect current cart category state.
 *
 * @param WC_Cart|null $cart Cart object.
 * @return array
 */
function wc_ecc_get_cart_category_state( $cart = null ) {
	$cart = $cart ? $cart : ( function_exists( 'WC' ) ? WC()->cart : null );
	$data = array(
		'has_exclusive' => false,
		'has_regular'   => false,
	);

	if ( ! $cart || $cart->is_empty() ) {
		return $data;
	}

	foreach ( $cart->get_cart() as $cart_item ) {
		$product_id   = isset( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0;
		$variation_id = isset( $cart_item['variation_id'] ) ? absint( $cart_item['variation_id'] ) : 0;

		if ( $product_id <= 0 ) {
			continue;
		}

		if ( wc_ecc_is_product_exclusive( $product_id, $variation_id ) ) {
			$data['has_exclusive'] = true;
		} else {
			$data['has_regular'] = true;
		}

		if ( $data['has_exclusive'] && $data['has_regular'] ) {
			break;
		}
	}

	return $data;
}

/**
 * Return cart mode.
 *
 * @param WC_Cart|null $cart Cart object.
 * @return string empty|exclusive|regular|mixed
 */
function wc_ecc_get_cart_mode( $cart = null ) {
	$state = wc_ecc_get_cart_category_state( $cart );

	if ( $state['has_exclusive'] && $state['has_regular'] ) {
		return 'mixed';
	}
	if ( $state['has_exclusive'] ) {
		return 'exclusive';
	}
	if ( $state['has_regular'] ) {
		return 'regular';
	}
	return 'empty';
}

/**
 * Decide desired pickup method by cart composition.
 *
 * @param WC_Cart|null $cart Cart object.
 * @return string
 */
function wc_ecc_get_desired_pickup_method( $cart = null ) {
	if ( ! wc_ecc_pickup_ids_configured() ) {
		return '';
	}

	$state = wc_ecc_get_cart_category_state( $cart );
	if ( $state['has_exclusive'] ) {
		return wc_ecc_get_pickup_method_a();
	}
	if ( $state['has_regular'] ) {
		return wc_ecc_get_pickup_method_b();
	}
	return '';
}

/**
 * Get default pickup method A.
 *
 * @return string
 */
function wc_ecc_get_pickup_method_a() {
	return wc_ecc_sanitize_method_id( get_option( 'wc_ecc_pickup_method_a', '' ) );
}

/**
 * Get default pickup method B.
 *
 * @return string
 */
function wc_ecc_get_pickup_method_b() {
	return wc_ecc_sanitize_method_id( get_option( 'wc_ecc_pickup_method_b', '' ) );
}

/**
 * Resolve mode.
 *
 * @return string
 */
function wc_ecc_get_resolve_mode() {
	return wc_ecc_sanitize_resolve_mode( get_option( 'wc_ecc_resolve_mode', 'block' ) );
}

/**
 * Is auto-select enabled.
 *
 * @return bool
 */
function wc_ecc_is_autoselect_enabled() {
	return 'yes' === get_option( 'wc_ecc_autoselect_enabled', 'yes' );
}

/**
 * Is hide-other-pickup enabled.
 *
 * @return bool
 */
function wc_ecc_is_hide_other_pickup_enabled() {
	return 'yes' === get_option( 'wc_ecc_hide_other_pickup', 'yes' );
}

/**
 * Is test mode enabled.
 *
 * @return bool
 */
function wc_ecc_is_test_mode() {
	return 'yes' === get_option( 'wc_ecc_test_mode', 'no' );
}

/**
 * Unified mixed-cart notice text.
 *
 * @param string $incoming_mode Incoming product mode.
 * @return string
 */
function wc_ecc_get_mixing_notice_message( $incoming_mode ) {
	if ( 'exclusive' === $incoming_mode ) {
		return __(
			'This product is configured for Location A pickup, but your cart currently contains Location B pickup products. Please place separate orders.',
			WC_ECC_TEXT_DOMAIN
		);
	}

	return __(
		'This product is configured for Location B pickup, but your cart currently contains Location A pickup products. Please place separate orders.',
		WC_ECC_TEXT_DOMAIN
	);
}

/**
 * Add a WooCommerce notice once.
 *
 * @param string $message Message.
 * @param string $type    error|success|notice
 */
function wc_ecc_add_notice_once( $message, $type = 'error' ) {
	if ( function_exists( 'wc_has_notice' ) && wc_has_notice( $message, $type ) ) {
		return;
	}

	wc_add_notice( $message, $type );
}

/**
 * Logger wrapper.
 *
 * @param string $message Message.
 * @param string $level   error|warning|notice|info|debug
 * @param bool   $always  Log even when test mode is off.
 */
function wc_ecc_log( $message, $level = 'info', $always = false ) {
	if ( ! $always && ! wc_ecc_is_test_mode() ) {
		return;
	}

	if ( ! function_exists( 'wc_get_logger' ) ) {
		return;
	}

	wc_get_logger()->log(
		$level,
		$message,
		array(
			'source' => 'wc-ecc',
		)
	);
}
