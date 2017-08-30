<?php

/**
 * Part of Woo Mercado Pago Module
 * Author - Mercado Pago
 * Developer - Marcelo Tomio Hama / marcelo.hama@mercadolivre.com
 * Copyright - Copyright(c) MercadoPago [https://www.mercadopago.com]
 * License - https://www.gnu.org/licenses/gpl.html GPL version 2 or higher
 */

// This include Mercado Pago library SDK
require_once dirname( __FILE__ ) . '/sdk/lib/mercadopago.php';

/**
 * Summary: Extending from WooCommerce Payment Gateway class.
 * Description: This class implements Mercado Pago custom checkout.
 * @since 3.0.0
 */
class WC_WooMercadoPago_CustomGateway extends WC_Payment_Gateway {

	public function __construct( $is_instance = false ) {

		// Mercao Pago instance.
		$this->site_data = WC_Woo_Mercado_Pago_Module::get_site_data( true );
		$this->mp = new MP(
			WC_Woo_Mercado_Pago_Module::get_module_version(),
			get_option( '_mp_access_token' )
		);
		
		// WooCommerce fields.
		$this->id = 'woo-mercado-pago-custom';
		$this->supports = array( 'products', 'refunds' );
		/*$this->icon = apply_filters(
			'woocommerce_mercadopago_icon',
			plugins_url( 'assets/images/credit_card.png', plugin_dir_path( __FILE__ ) )
		);*/

		$this->method_title = __( 'Mercado Pago - Custom Checkout', 'woo-mercado-pago-module' );
		$this->method_description = '<img width="200" height="52" src="' .
			plugins_url( 'assets/images/mplogo.png', plugin_dir_path( __FILE__ ) ) .
		'"><br><br><strong>' .
			__( 'We give you the possibility to adapt the payment experience you want to offer 100% in your website, mobile app or anywhere you want. You can build the design that best fits your business model, aiming to maximize conversion.', 'woo-mercado-pago-module' ) .
		'</strong>';

		// TODO: Verify sandbox availability.
		$this->mp->sandbox_mode( false );

		// How checkout is shown.
		$this->title              = $this->get_option( 'title' );
		$this->description        = $this->get_option( 'description' );
		// How checkout payment behaves.
		$this->coupon_mode        = $this->get_option( 'coupon_mode', 'no' );
		$this->binary_mode        = $this->get_option( 'binary_mode', 'no' );
		$this->gateway_discount   = $this->get_option( 'gateway_discount', 0 );
		
		// Logging and debug.
		$_mp_debug_mode = get_option( '_mp_debug_mode', '' );
		if ( ! empty ( $_mp_debug_mode ) ) {
			if ( class_exists( 'WC_Logger' ) ) {
				$this->log = new WC_Logger();
			} else {
				$this->log = WC_Woo_Mercado_Pago_Module::woocommerce_instance()->logger();
			}
		}

		// Render our configuration page and init/load fields.
		$this->init_form_fields();
		$this->init_settings();

		// Used by IPN to receive IPN incomings.
		add_action(
			'woocommerce_api_wc_woomercadopago_customgateway',
			array( $this, 'check_ipn_response' )
		);
		// Used by IPN to process valid incomings.
		add_action(
			'valid_mercadopago_custom_ipn_request',
			array( $this, 'successful_request' )
		);
		// Process the cancel order meta box order action.
		add_action(
			'woocommerce_order_action_cancel_order',
			array( $this, 'process_cancel_order_meta_box_actions' )
		);
		// Used in settings page to hook "save settings" action.
		add_action(
			'woocommerce_update_options_payment_gateways_' . $this->id,
			array( $this, 'custom_process_admin_options' )
		);
		// Scripts for custom checkout.
		add_action(
			'wp_enqueue_scripts',
			array( $this, 'add_checkout_scripts_custom' )
		);
		// Apply the discounts.
		/*add_action(
			'woocommerce_cart_calculate_fees',
			array( $this, 'add_discount_custom' ), 10
		);*/
		// Display discount in payment method title.
		/*add_filter(
			'woocommerce_gateway_title',
			array( $this, 'get_payment_method_title_custom' ), 10, 2
		);*/

		if ( ! empty( $this->settings['enabled'] ) && $this->settings['enabled'] == 'yes' ) {
			if ( ! $is_instance ) {
				// Scripts for order configuration.
				add_action(
					'woocommerce_after_checkout_form',
					array( $this, 'add_mp_settings_script_custom' )
				);
				// Checkout updates.
				add_action(
					'woocommerce_thankyou',
					array( $this, 'update_mp_settings_script_custom' )
				);
			}
		}

	}

	/**
	 * Summary: Initialise Gateway Settings Form Fields.
	 * Description: Initialise Gateway settings form fields with a customized page.
	 */
	public function init_form_fields() {

		// Show message if credentials are not properly configured.
		$_site_id_v1 = get_option( '_site_id_v1', '' );
		if ( empty( $_site_id_v1 ) ) {
			$this->form_fields = array(
				'no_credentials_title' => array(
					'title' => sprintf(
						__( 'It appears that your credentials are not properly configured.<br/>Please, go to %s and configure it.', 'woo-mercado-pago-module' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=mercado-pago-settings' ) ) . '">' .
						__( 'Mercado Pago Settings', 'woo-mercado-pago-module' ) .
						'</a>'
					),
					'type' => 'title'
				),
			);
			return;
		}

		// This array draws each UI (text, selector, checkbox, label, etc).
		$this->form_fields = array(
			'enabled' => array(
				'title' => __( 'Enable/Disable', 'woo-mercado-pago-module' ),
				'type' => 'checkbox',
				'label' => __( 'Enable Custom Checkout', 'woo-mercado-pago-module' ),
				'default' => 'no'
			),
			'checkout_options_title' => array(
				'title' => __( 'Checkout Interface: How checkout is shown', 'woo-mercado-pago-module' ),
				'type' => 'title'
			),
			'title' => array(
				'title' => __( 'Title', 'woo-mercado-pago-module' ),
				'type' => 'text',
				'description' => __( 'Title shown to the client in the checkout.', 'woo-mercado-pago-module' ),
				'default' => __( 'Mercado Pago - Credit Card', 'woo-mercado-pago-module' )
			),
			'description' => array(
				'title' => __( 'Description', 'woo-mercado-pago-module' ),
				'type' => 'textarea',
				'description' => __( 'Description shown to the client in the checkout.', 'woo-mercado-pago-module' ),
				'default' => __( 'Pay with Mercado Pago', 'woo-mercado-pago-module' )
			),
			'payment_title' => array(
				'title' => __( 'Payment Options: How payment options behaves', 'woo-mercado-pago-module' ),
				'type' => 'title'
			),
			'coupon_mode' => array(
				'title' => __( 'Coupons', 'woo-mercado-pago-module' ),
				'type' => 'checkbox',
				'label' => __( 'Enable coupons of discounts', 'woo-mercado-pago-module' ),
				'default' => 'no',
				'description' => __( 'If there is a Mercado Pago campaign, allow your store to give discounts to customers.', 'woo-mercado-pago-module' )
			),
			'binary_mode' => array(
				'title' => __( 'Binary Mode', 'woo-mercado-pago-module' ),
				'type' => 'checkbox',
				'label' => __( 'Enable binary mode for checkout status', 'woo-mercado-pago-module' ),
				'default' => 'no',
				'description' =>
					__( 'When charging a credit card, only [approved] or [reject] status will be taken.', 'woo-mercado-pago-module' )
			),
			'gateway_discount' => array(
				'title' => __( 'Discount by Gateway', 'woo-mercado-pago-module' ),
				'type' => 'number',
				'description' => __( 'Give a percentual (0 to 100) discount for your customers if they use this payment gateway.', 'woo-mercado-pago-module' ),
				'default' => '0'
			)
		);

	}

	/**
	 * Processes and saves options.
	 * If there is an error thrown, will continue to save and validate fields, but will leave the
	 * erroring field out.
	 * @return bool was anything saved?
	 */
	public function custom_process_admin_options() {
		$this->init_settings();
		$post_data = $this->get_post_data();
		foreach ( $this->get_form_fields() as $key => $field ) {
			if ( 'title' !== $this->get_field_type( $field ) ) {
				$value = $this->get_field_value( $key, $field, $post_data );
				if ( $key == 'gateway_discount') {
					if ( ! is_numeric( $value ) || empty ( $value ) ) {
						$this->settings[$key] = 0;
					} else {
						if ( $value < 0 || $value >= 100 || empty ( $value ) ) {
							$this->settings[$key] = 0;
						} else {
							$this->settings[$key] = $value;
						}
					}
				} else {
					$this->settings[$key] = $this->get_field_value( $key, $field, $post_data );
				}
			}
		}
		$_site_id_v1 = get_option( '_site_id_v1', '' );
		$is_test_user = get_option( '_test_user_v1', false );
		if ( ! empty( $_site_id_v1 ) && ! $is_test_user ) {
			// Create MP instance.
			$mp = new MP(
				WC_Woo_Mercado_Pago_Module::get_module_version(),
				get_option( '_mp_access_token' )
			);
			// Analytics.
			$infra_data = WC_Woo_Mercado_Pago_Module::get_common_settings();
			$infra_data['checkout_custom_credit_card'] = ( $this->settings['enabled'] == 'yes' ? 'true' : 'false' );
			$infra_data['checkout_custom_credit_card_coupon'] = ( $this->settings['coupon_mode'] == 'yes' ? 'true' : 'false' );
			$response = $mp->analytics_save_settings( $infra_data );
		}
		// Apply updates.
		return update_option(
			$this->get_option_key(),
			apply_filters( 'woocommerce_settings_api_sanitized_fields_' . $this->id, $this->settings )
		);
	}

	/**
	 * Handles the manual order refunding in server-side.
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {

		$payments = get_post_meta( $order_id, '_Mercado_Pago_Payment_IDs', true );

		// Validate.
		if ( $this->mp == null || empty( $payments ) ) {
			$this->write_log( __FUNCTION__, 'no payments or credentials invalid' );
			return false;
		}

		// Processing data about this refund.
		$total_available = 0;
		$payment_structs = array();
		$payment_ids = explode( ', ', $payments );
		foreach ( $payment_ids as $p_id ) {
			$p = get_post_meta( $order_id, 'Mercado Pago - Payment ' . $p_id, true );
			$p = explode( '/', $p );
			$paid_arr = explode( ' ', substr( $p[2], 1, -1 ) );
			$paid = ( (float) $paid_arr[1] );
			$refund_arr = explode( ' ', substr( $p[3], 1, -1 ) );
			$refund = ( (float) $refund_arr[1] );
			$p_struct = array( 'id' => $p_id, 'available_to_refund' => $paid - $refund );
			$total_available += $paid - $refund;
			$payment_structs[] = $p_struct;
		}
		$this->write_log( __FUNCTION__,
			'refunding ' . $amount . ' because of ' . $reason . ' and payments ' .
			json_encode( $payment_structs, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE )
		);

		// Do not allow refund more than available or invalid amounts.
		if ( $amount > $total_available || $amount <= 0 ) {
			return false;
		}

		// Iteratively refunfind amount, taking in consideration multiple payments.
		$remaining_to_refund = $amount;
		foreach ( $payment_structs as $to_refund ) {
			if ( $remaining_to_refund <= $to_refund['available_to_refund'] ) {
				// We want to refund an amount that is less than the available for this payment, so we
				// can just refund and return.
				$response = $this->mp->partial_refund_payment(
					$to_refund['id'], $remaining_to_refund,
					$reason, $this->invoice_prefix . $order_id
				);
				$message = $response['response']['message'];
				$status = $response['status'];
				$this->write_log( __FUNCTION__,
					'refund payment of id ' . $p_id . ' => ' .
					( $status >= 200 && $status < 300 ? 'SUCCESS' : 'FAIL - ' . $message )
				);
				if ( $status >= 200 && $status < 300 ) {
					return true;
				} else {
					return false;
				}
			} elseif ( $to_refund['available_to_refund'] > 0 ) {
				// We want to refund an amount that exceeds the available for this payment, so we
				// totally refund this payment, and try to complete refund in other/next payments.
				$response = $this->mp->partial_refund_payment(
					$to_refund['id'], $to_refund['available_to_refund'],
					$reason, $this->invoice_prefix . $order_id
				);
				$message = $response['response']['message'];
				$status = $response['status'];
				$this->write_log( __FUNCTION__,
					'refund payment of id ' . $p_id . ' => ' .
					( $status >= 200 && $status < 300 ? 'SUCCESS' : 'FAIL - ' . $message )
				);
				if ( $status < 200 || $status >= 300 ) {
					return false;
				}
				$remaining_to_refund -= $to_refund['available_to_refund'];
			}
			if ( $remaining_to_refund == 0 )
				return true;
		}

		// Reaching here means that there we run out of payments, and there is an amount
		// remaining to be refund, which is impossible as it implies refunding more than
		// available on paid amounts.
		return false;
	}

	/**
	 * Handles the manual order cancellation in server-side.
	 */
	public function process_cancel_order_meta_box_actions( $order ) {

		$used_gateway = ( method_exists( $order, 'get_meta' ) ) ?
			$order->get_meta( '_used_gateway' ) :
			get_post_meta( $order->id, '_used_gateway', true );
		$payments = ( method_exists( $order, 'get_meta' ) ) ?
			$order->get_meta( '_Mercado_Pago_Payment_IDs' ) :
			get_post_meta( $order->id, '_Mercado_Pago_Payment_IDs',	true );

		// A watchdog to prevent operations from other gateways.
		if ( $used_gateway != 'WC_WooMercadoPago_CustomGateway' ) {
			return;
		}

		$this->write_log( __FUNCTION__, 'cancelling payments for ' . $payments );

		// Canceling the order and all of its payments.
		if ( $this->mp != null && ! empty( $payments ) ) {
			$payment_ids = explode( ', ', $payments );
			foreach ( $payment_ids as $p_id ) {
				$response = $this->mp->cancel_payment( $p_id );
				$message = $response['response']['message'];
				$status = $response['status'];
				$this->write_log( __FUNCTION__,
					'cancel payment of id ' . $p_id . ' => ' .
					( $status >= 200 && $status < 300 ? 'SUCCESS' : 'FAIL - ' . $message )
				);
			}
		} else {
			$this->write_log( __FUNCTION__, 'no payments or credentials invalid' );
		}
	}

	// Write log.
	private function write_log( $function, $message ) {
		$_mp_debug_mode = get_option( '_mp_debug_mode', '' );
		if ( ! empty ( $_mp_debug_mode ) ) {
			$this->log->add(
				$this->id,
				'[' . $function . ']: ' . $message
			);
		}
	}

	/*
	 * ========================================================================
	 * CHECKOUT BUSINESS RULES (CLIENT SIDE)
	 * ========================================================================
	 */

	public function add_mp_settings_script_custom() {

		$public_key = get_option( '_mp_public_key' );
		$is_test_user = get_option( '_test_user_v1', false );

		if ( ! empty( $public_key ) && ! $is_test_user ) {

			$w = WC_Woo_Mercado_Pago_Module::woocommerce_instance();
			$available_payments = array();
			$gateways = WC()->payment_gateways->get_available_payment_gateways();
			foreach ( $gateways as $g ) {
				$available_payments[] = $g->id;
			}
			$available_payments = str_replace( '-', '_', implode( ', ', $available_payments ) );
			if ( wp_get_current_user()->ID != 0 ) {
				$logged_user_email = wp_get_current_user()->user_email;
			} else {
				$logged_user_email = null;
			}
			?>
			<script src="https://secure.mlstatic.com/modules/javascript/analytics.js"></script>
			<script type="text/javascript">
				var MA = ModuleAnalytics;
				MA.setPublicKey( '<?php echo $public_key; ?>' );
				MA.setPlatform( 'WooCommerce' );
				MA.setPlatformVersion( '<?php echo $w->version; ?>' );
				MA.setModuleVersion( '<?php echo WC_Woo_Mercado_Pago_Module::VERSION; ?>' );
				MA.setPayerEmail( '<?php echo ( $logged_user_email != null ? $logged_user_email : "" ); ?>' );
				MA.setUserLogged( <?php echo ( empty( $logged_user_email ) ? 0 : 1 ); ?> );
				MA.setInstalledModules( '<?php echo $available_payments; ?>' );
				MA.post();
			</script>
			<?php

		}

	}

	public function update_mp_settings_script_custom( $order_id ) {
		$public_key = get_option( '_mp_public_key' );
		$is_test_user = get_option( '_test_user_v1', false );
		if ( ! empty( $public_key ) && ! $is_test_user ) {
			if ( get_post_meta( $order_id, '_used_gateway', true ) != 'WC_WooMercadoPago_CustomGateway' ) {
				return;
			}
			$this->write_log( __FUNCTION__, 'updating order of ID ' . $order_id );
			echo '<script src="https://secure.mlstatic.com/modules/javascript/analytics.js"></script>
			<script type="text/javascript">
				var MA = ModuleAnalytics;
				MA.setPublicKey( "' . $public_key . '" );
				MA.setPaymentType("credit_card");
				MA.setCheckoutType("custom");
				MA.put();
			</script>';
		}
	}

	public function add_checkout_scripts_custom() {
		if ( is_checkout() && $this->is_available() ) {
			if ( ! get_query_var( 'order-received' ) ) {
				/* TODO: separate javascript from html template
				$logged_user_email = ( wp_get_current_user()->ID != 0 ) ? wp_get_current_user()->user_email : null;
				$discount_action_url = get_site_url() . '/index.php/woo-mercado-pago-module/?wc-api=WC_WooMercadoPago_CustomGateway';
				*/
				wp_enqueue_style(
					'woocommerce-mercadopago-style',
					plugins_url( 'assets/css/custom_checkout_mercadopago.css', plugin_dir_path( __FILE__ ) )
				);
				wp_enqueue_script(
					'mercado-pago-module-js',
					'https://secure.mlstatic.com/sdk/javascript/v1/mercadopago.js'
				);
				/* TODO: separate javascript from html template
				wp_enqueue_script(
					'woo-mercado-pago-module-custom-js',
					plugins_url( 'assets/js/credit-card.js', plugin_dir_path( __FILE__ ) ),
					array( 'mercado-pago-module-js' ),
					WC_Woo_Mercado_Pago_Module::VERSION,
					true
				);
				wp_localize_script(
					'woo-mercado-pago-module-custom-js',
					'wc_mercadopago_custom_params',
					array(
						'site_id'             => get_option( '_site_id_v1' ),
						'public_key'          => get_option( '_mp_public_key' ),
						'coupon_mode'         => isset( $logged_user_email ) ? $this->coupon_mode : 'no',
						'discount_action_url' => $discount_action_url,
						'payer_email'         => $logged_user_email,
						// ===
						'apply'               => __( 'Apply', 'woo-mercado-pago-module' ),
						'remove'              => __( 'Remove', 'woo-mercado-pago-module' ),
						'coupon_empty'        => __( 'Please, inform your coupon code', 'woo-mercado-pago-module' ),
						'label_choose'        => __( 'Choose', 'woo-mercado-pago-module' ),
						'label_other_bank'    => __( 'Other Bank', 'woo-mercado-pago-module' ),
						'discount_info1'      => __( 'You will save', 'woo-mercado-pago-module' ),
						'discount_info2'      => __( 'with discount from', 'woo-mercado-pago-module' ),
						'discount_info3'      => __( 'Total of your purchase:', 'woo-mercado-pago-module' ),
						'discount_info4'      => __( 'Total of your purchase with discount:', 'woo-mercado-pago-module' ),
						'discount_info5'      => __( '*Uppon payment approval', 'woo-mercado-pago-module' ),
						'discount_info6'      => __( 'Terms and Conditions of Use', 'woo-mercado-pago-module' ),
						// ===
						'images_path'         => plugins_url( 'assets/images/', plugin_dir_path( __FILE__ ) )
					)
				);
				*/
			}
		}
	}

	public function payment_fields() {
		
		wp_enqueue_script( 'wc-credit-card-form' );

		$amount = $this->get_order_total();
		$logged_user_email = ( wp_get_current_user()->ID != 0 ) ? wp_get_current_user()->user_email : null;
		$customer = isset( $logged_user_email ) ? $this->mp->get_or_create_customer( $logged_user_email ) : null;
		$discount_action_url = get_site_url() . '/index.php/woo-mercado-pago-module/?wc-api=WC_WooMercadoPago_CustomGateway';

		$parameters = array(
			'amount'                 => $amount, // TODO: convert currency v1
			// ===
			'site_id'                => get_option( '_site_id_v1' ),
			'public_key'             => get_option( '_mp_public_key' ),
			'coupon_mode'            => isset( $logged_user_email ) ? $this->coupon_mode : 'no',
			'discount_action_url'    => $discount_action_url,
			'payer_email'            => $logged_user_email,
			// ===
			'images_path'            => plugins_url( 'assets/images/', plugin_dir_path( __FILE__ ) ),
			'banner_path'            => $this->site_data['checkout_banner_custom'],
			'customer_cards'         => isset( $customer ) ? ( isset( $customer['cards'] ) ? $customer['cards'] : array() ) : array(),
			'customerId'             => isset( $customer ) ? ( isset( $customer['id'] ) ? $customer['id'] : null ) : null,
			'currency_ratio'         => 2, // TODO: on-the-fly retrieve currency ratio
			'woocommerce_currency'   => get_woocommerce_currency(),
			'account_currency'       => $this->site_data['currency'],
			'error' => array(
				// Card number.
				'205' => __( 'Parameter cardNumber can not be null/empty', 'woo-mercado-pago-module' ),
				'E301' => __( 'Invalid Card Number', 'woo-mercado-pago-module' ),
				// Expiration date.
				'208' => __( 'Invalid Expiration Date', 'woo-mercado-pago-module' ),
				'209' => __( 'Invalid Expiration Date', 'woo-mercado-pago-module' ),
				'325' => __( 'Invalid Expiration Date', 'woo-mercado-pago-module' ),
				'326' => __( 'Invalid Expiration Date', 'woo-mercado-pago-module' ),
				// Card holder name.
				'221' => __( 'Parameter cardholderName can not be null/empty', 'woo-mercado-pago-module' ),
				'316' => __( 'Invalid Card Holder Name', 'woo-mercado-pago-module' ),
				// Security code.
				'224' => __( 'Parameter securityCode can not be null/empty', 'woo-mercado-pago-module' ),
				'E302' => __( 'Invalid Security Code', 'woo-mercado-pago-module' ),
				// Doc type.
				'212' => __( 'Parameter docType can not be null/empty', 'woo-mercado-pago-module' ),
				'322' => __( 'Invalid Document Type', 'woo-mercado-pago-module' ),
				// Doc number.
				'214' => __( 'Parameter docNumber can not be null/empty', 'woo-mercado-pago-module' ),
				'324' => __( 'Invalid Document Number', 'woo-mercado-pago-module' ),
				// Doc sub type.
				'213' => __( 'The parameter cardholder.document.subtype can not be null or empty', 'woo-mercado-pago-module' ),
				'323' => __( 'Invalid Document Sub Type', 'woo-mercado-pago-module' ),
				// Issuer.
				'220' => __( 'Parameter cardIssuerId can not be null/empty', 'woo-mercado-pago-module' )
			)
		);

		wc_get_template(
			'credit-card/payment-form.php',
			$parameters,
			'woo/mercado/pago/module/',
			WC_Woo_Mercado_Pago_Module::get_templates_path()
		);
	}

	/**
	 * Summary: Handle the payment and processing the order.
	 * Description: This function is called after we click on [place_order] button, and each field is
	 * passed to this function through $_POST variable.
	 * @return an array containing the result of the processment and the URL to redirect.
	 */
	public function process_payment( $order_id ) {

		/*if ( ! isset( $_POST['mercadopago_custom'] ) ) {
			return;
		}

		$order = wc_get_order( $order_id );
		$custom_checkout = $_POST['mercadopago_custom'];

		// WooCommerce 3.0 or later.
		if ( method_exists( $order, 'update_meta_data' ) ) {
			$order->update_meta_data( '_used_gateway', 'WC_WooMercadoPagoCustom_Gateway' );
			$order->save();
		} else {
			update_post_meta( $order_id, '_used_gateway', 'WC_WooMercadoPagoCustom_Gateway' );
		}

		// We have got parameters from checkout page, now its time to charge the card.
		if ( 'yes' == $this->debug ) {
			$this->log->add(
				$this->id,
				'[process_payment] - Received [$_POST] from customer front-end page: ' .
				json_encode( $_POST, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE )
			);
		}

		// Mexico country case.
		if ( $custom_checkout['paymentMethodId'] == '' || empty( $custom_checkout['paymentMethodId'] ) ) {
			$custom_checkout['paymentMethodId'] = $custom_checkout['paymentMethodSelector'];
		}

		if ( isset( $custom_checkout['amount'] ) && ! empty( $custom_checkout['amount'] ) &&
			isset( $custom_checkout['token'] ) && ! empty( $custom_checkout['token'] ) &&
			isset( $custom_checkout['paymentMethodId'] ) && ! empty( $custom_checkout['paymentMethodId'] ) &&
			isset( $custom_checkout['installments'] ) && ! empty( $custom_checkout['installments'] ) &&
			$custom_checkout['installments'] != -1 ) {

			$response = self::create_url( $order, $custom_checkout );

			if ( array_key_exists( 'status', $response ) ) {
				switch ( $response['status'] ) {
					case 'approved':
						WC()->cart->empty_cart();
						wc_add_notice(
							'<p>' .
								__( $this->get_order_status( 'accredited' ), 'woocommerce-mercadopago-module' ) .
							'</p>',
							'notice'
						);
						$order->add_order_note(
							'Mercado Pago: ' .
							__( 'Payment approved.', 'woocommerce-mercadopago-module' )
						);
						return array(
							'result' => 'success',
							'redirect' => $order->get_checkout_order_received_url()
						);
						break;
				case 'pending':
					// Order approved/pending, we just redirect to the thankyou page.
					return array(
							'result' => 'success',
							'redirect' => $order->get_checkout_order_received_url()
						);
						break;
				case 'in_process':
					// For pending, we don't know if the purchase will be made, so we must inform this status.
					WC()->cart->empty_cart();
						wc_add_notice(
							'<p>' .
								__( $this->get_order_status( $response['status_detail'] ), 'woocommerce-mercadopago-module' ) .
							'</p>' .
							'<p><a class="button" href="' .
								esc_url( $order->get_checkout_order_received_url() ) .
							'">' .
								__( 'Check your order resume', 'woocommerce-mercadopago-module' ) .
							'</a></p>',
							'notice'
						);
						return array(
							'result' => 'success',
							'redirect' => $order->get_checkout_payment_url( true )
						);
						break;
				case 'rejected':
						// If rejected is received, the order will not proceed until another payment try,
						// so we must inform this status.
						wc_add_notice(
							'<p>' .
								__( 'Your payment was refused. You can try again.', 'woocommerce-mercadopago-module' ) .
							'<br>' .
								__( $this->get_order_status( $response['status_detail'] ), 'woocommerce-mercadopago-module' ) .
							'</p>' .
							'<p><a class="button" href="' . esc_url( $order->get_checkout_payment_url() ) . '">' .
								__( 'Click to try again', 'woocommerce-mercadopago-module' ) .
							'</a></p>',
							'error'
						);
						return array(
							'result' => 'success',
							'redirect' => $order->get_checkout_payment_url( true )
						);
						break;
					case 'cancelled':
					case 'in_mediation':
					case 'charged-back':
						break;
					default:
						break;
				}
			}
		} else {
			// Process when fields are imcomplete.
			wc_add_notice(
				'<p>' .
					__( 'A problem was occurred when processing your payment. Are you sure you have correctly filled all information in the checkout form?', 'woocommerce-mercadopago-module' ) .
				'</p>',
				'error'
			);
			return array(
				'result' => 'fail',
				'redirect' => '',
			);
		}*/

	}

	/**
	 * Summary: Build Mercado Pago preference.
	 * Description: Create Mercado Pago preference and get init_point URL based in the order options
	 * from the cart.
	 * @return the preference object.
	 */
	/*private function build_payment_preference( $order, $custom_checkout ) {

		// A string to register items (workaround to deal with API problem that shows only first item).
		$list_of_items = array();
		$order_total = 0;
		$discount_amount_of_items = 0;

		// Here we build the array that contains ordered items, from customer cart.
		$items = array();
		if ( sizeof( $order->get_items() ) > 0 ) {
			foreach ( $order->get_items() as $item ) {
				if ( $item['qty'] ) {
					$product = new WC_product( $item['product_id'] );

					// WooCommerce 3.0 or later.
					if ( method_exists( $product, 'get_description' ) ) {
						$product_title = WC_WooMercadoPago_Module::utf8_ansi(
							$product->get_name()
						);
						$product_content = WC_WooMercadoPago_Module::utf8_ansi(
							$product->get_description()
						);
					} else {
						$product_title = WC_WooMercadoPago_Module::utf8_ansi(
							$product->post->post_title
						);
						$product_content = WC_WooMercadoPago_Module::utf8_ansi(
							$product->post->post_content
						);
					}
					
					// Calculate discount for payment method.
					$unit_price = floor( ( (float) $item['line_total'] + (float) $item['line_tax'] ) *
						( (float) $this->currency_ratio > 0 ? (float) $this->currency_ratio : 1 ) * 100 ) / 100;
					if ( is_numeric( $this->gateway_discount ) ) {
						if ( $this->gateway_discount >= 0 && $this->gateway_discount < 100 ) {
							$price_percent = $this->gateway_discount / 100;
							$discount = $unit_price * $price_percent;
							if ( $discount > 0 ) {
								$discount_amount_of_items += $discount;
							}
						}
					}

					// Remove decimals if MCO/MLC
					if ( $this->site_id == 'MCO' || $this->site_id == 'MLC' ) {
						$unit_price = floor( $unit_price );
						$discount_amount_of_items = floor( $discount_amount_of_items );
					}

					$order_total += $unit_price;

					array_push( $list_of_items, $product_title . ' x ' . $item['qty'] );
					array_push( $items, array(
						'id' => $item['product_id'],
						'title' => ( html_entity_decode( $product_title ) . ' x ' . $item['qty'] ),
						'description' => sanitize_file_name( html_entity_decode( 
							// This handles description width limit of Mercado Pago.
							( strlen( $product_content ) > 230 ?
								substr( $product_content, 0, 230 ) . '...' :
								$product_content )
						) ),
						'picture_url' => wp_get_attachment_url( $product->get_image_id() ),
						'category_id' => $this->store_categories_id[$this->category_id],
						'quantity' => 1,
						'unit_price' => $unit_price
					) );
				}
			}
		}

		// Creates the shipment cost structure.
		$ship_cost = ( (float) $order->get_total_shipping() + (float) $order->get_shipping_tax() ) *
			( (float) $this->currency_ratio > 0 ? (float) $this->currency_ratio : 1 );

		// Remove decimals if MCO/MLC
		if ( $this->site_id == 'MCO' || $this->site_id == 'MLC' ) {
			$ship_cost = floor( $ship_cost );
		}

		if ( $ship_cost > 0 ) {
			$order_total += $ship_cost;
			$item = array(
				'id' => 2147483647,
				'title' => sanitize_file_name( $order->get_shipping_to_display() ),
				'description' => __( 'Shipping service used by store', 'woocommerce-mercadopago-module' ),
				'category_id' => $this->store_categories_id[$this->category_id],
				'quantity' => 1,
				'unit_price' => floor( $ship_cost * 100 ) / 100
			);
			$items[] = $item;
		}

		// Discounts features.
		if ( isset( $custom_checkout['discount'] ) && $custom_checkout['discount'] != '' &&
			$custom_checkout['discount'] > 0 && isset( $custom_checkout['coupon_code'] ) &&
			$custom_checkout['coupon_code'] != '' &&
			WC()->session->chosen_payment_method == 'woocommerce-mercadopago-custom-module' ) {

			// Remove decimals if MCO/MLC
			if ( $this->site_id == 'MCO' || $this->site_id == 'MLC' ) {
				$custom_checkout['discount'] = floor( $custom_checkout['discount'] );
			}

			$item = array(
				'id' => 2147483646,
				'title' => __( 'Discount', 'woocommerce-mercadopago-module' ),
				'description' => __( 'Discount provided by store', 'woocommerce-mercadopago-module' ),
				'quantity' => 1,
				'category_id' => $this->store_categories_id[$this->category_id],
				'unit_price' => -( (float) $custom_checkout['discount'] )
			);
			$items[] = $item;
		}

		// Build additional information from the customer data.
		if ( method_exists( $order, 'get_id' ) ) {
			// Build additional information from the customer data.
			$payer_additional_info = array(
				'first_name' => html_entity_decode( $order->get_billing_first_name() ),
				'last_name' => html_entity_decode( $order->get_billing_last_name() ),
				//'registration_date' =>
				'phone' => array(
					//'area_code' =>
					'number' => $order->get_billing_phone(),
				),
				'address' => array(
					'zip_code' => $order->get_billing_postcode(),
					//'street_number' =>
					'street_name' => html_entity_decode(
						$order->get_billing_address_1() . ' / ' .
						$order->get_billing_city() . ' ' .
						$order->get_billing_state() . ' ' .
						$order->get_billing_country()
					)
				)
			);
			// Create the shipment address information set.
			$shipments = array(
				'receiver_address' => array(
					'zip_code' => $order->get_shipping_postcode(),
					//'street_number' =>
					'street_name' => html_entity_decode(
						$order->get_shipping_address_1() . ' ' .
						$order->get_shipping_address_2() . ' ' .
						$order->get_shipping_city() . ' ' .
						$order->get_shipping_state() . ' ' .
						$order->get_shipping_country()
					),
					//'floor' =>
					'apartment' => $order->get_shipping_address_2()
				)
			);
			// The payment preference.
			$preferences = array(
				'transaction_amount' => floor( ( (float) ( $order_total - $discount_amount_of_items ) ) * 100 ) / 100,
				'token' => $custom_checkout['token'],
				'description' => implode( ', ', $list_of_items ),
				'installments' => (int) $custom_checkout['installments'],
				'payment_method_id' => $custom_checkout['paymentMethodId'],
				'payer' => array(
					'email' => $order->get_billing_email()
				),
				'external_reference' => $this->invoice_prefix . $order->get_id(),
				'statement_descriptor' => $this->statement_descriptor,
				'binary_mode' => ( $this->binary_mode == 'yes' ),
				'additional_info' => array(
					'items' => $items,
					'payer' => $payer_additional_info,
					'shipments' => $shipments
				)
			);
		} else {
			// Build additional information from the customer data.
			$payer_additional_info = array(
				'first_name' => html_entity_decode( $order->billing_first_name ),
				'last_name' => html_entity_decode( $order->billing_last_name ),
				//'registration_date' =>
				'phone' => array(
					//'area_code' =>
					'number' => $order->billing_phone
				),
				'address' => array(
					'zip_code' => $order->billing_postcode,
					//'street_number' =>
					'street_name' => html_entity_decode(
						$order->billing_address_1 . ' / ' .
						$order->billing_city . ' ' .
						$order->billing_state . ' ' .
						$order->billing_country
					)
				)
			);
			// Create the shipment address information set.
			$shipments = array(
				'receiver_address' => array(
					'zip_code' => $order->shipping_postcode,
					//'street_number' =>
					'street_name' => html_entity_decode(
						$order->shipping_address_1 . ' ' .
						$order->shipping_address_2 . ' ' .
						$order->shipping_city . ' ' .
						$order->shipping_state . ' ' .
						$order->shipping_country
					),
					//'floor' =>
					'apartment' => $order->shipping_address_2
				)
			);
			// The payment preference.
			$preferences = array(
				'transaction_amount' => floor( ( (float) ( $order_total - $discount_amount_of_items ) ) * 100 ) / 100,
				'token' => $custom_checkout['token'],
				'description' => implode( ', ', $list_of_items ),
				'installments' => (int) $custom_checkout['installments'],
				'payment_method_id' => $custom_checkout['paymentMethodId'],
				'payer' => array(
					'email' => $order->billing_email
				),
				'external_reference' => $this->invoice_prefix . $order->id,
				'statement_descriptor' => $this->statement_descriptor,
				'binary_mode' => ( $this->binary_mode == 'yes' ),
				'additional_info' => array(
					'items' => $items,
					'payer' => $payer_additional_info,
					'shipments' => $shipments
				)
			);
		}

		// Customer's Card Feature, add only if it has issuer id.
		if ( array_key_exists( 'token', $custom_checkout ) ) {
			$preferences['metadata']['token'] = $custom_checkout['token'];
			if ( array_key_exists( 'issuer', $custom_checkout ) ) {
				if ( ! empty( $custom_checkout['issuer'] ) ) {
					$preferences['issuer_id'] = (integer) $custom_checkout['issuer'];
				}
			}
			if ( ! empty( $custom_checkout['CustomerId'] ) ) {
				$preferences['payer']['id'] = $custom_checkout['CustomerId'];
			}
		}

		// Do not set IPN url if it is a localhost.
		if ( ! strrpos( $this->domain, 'localhost' ) ) {
			$preferences['notification_url'] = WC_WooMercadoPago_Module::workaround_ampersand_bug(
				WC()->api_request_url( 'WC_WooMercadoPagoCustom_Gateway' )
			);
		}

		// Discounts features.
		if ( isset( $custom_checkout['discount'] ) && $custom_checkout['discount'] != '' &&
			$custom_checkout['discount'] > 0 && isset( $custom_checkout['coupon_code'] ) &&
			$custom_checkout['coupon_code'] != '' &&
			WC()->session->chosen_payment_method == 'woocommerce-mercadopago-custom-module' ) {

			$preferences['campaign_id'] = (int) $custom_checkout['campaign_id'];
			$preferences['coupon_amount'] = ( (float) $custom_checkout['discount'] );
			$preferences['coupon_code'] = strtoupper( $custom_checkout['coupon_code'] );
		}

		// Set sponsor ID.
		if ( ! $this->is_test_user ) {
			$preferences['sponsor_id'] = $this->country_configs['sponsor_id'];
		}

		if ( 'yes' == $this->debug ) {
			$this->log->add(
				$this->id,
				'[build_payment_preference] - returning just created [$preferences] structure: ' .
				json_encode( $preferences, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE )
			);
		}

		$preferences = apply_filters(
			'woocommerce_mercadopago_module_custom_preferences',
			$preferences, $order
		);
		return $preferences;
	}*/

	// --------------------------------------------------

	/*protected function create_url( $order, $custom_checkout ) {

		// Creates the order parameters by checking the cart configuration.
		$preferences = $this->build_payment_preference( $order, $custom_checkout );

		// Checks for sandbox mode.
		if ( 'yes' == $this->sandbox ) {
			$this->mp->sandbox_mode( true );
			if ( 'yes' == $this->debug) {
				$this->log->add(
					$this->id,
					'[create_url] - sandbox mode is enabled'
				);
			}
		} else {
			$this->mp->sandbox_mode( false );
		}

		// Create order preferences with Mercado Pago API request.
		try {
			$checkout_info = $this->mp->post( '/v1/payments', json_encode( $preferences) );
			if ( $checkout_info['status'] < 200 || $checkout_info['status'] >= 300 ) {
				// Mercado Pago trowed an error.
				if ( 'yes' == $this->debug ) {
					$this->log->add(
						$this->id,
						'[create_url] - mercado pago gave error, payment creation failed with error: ' .
						$checkout_info['response']['message'] );
				}
				return false;
			} elseif ( is_wp_error( $checkout_info ) ) {
				// WordPress throwed an error.
				if ( 'yes' == $this->debug ) {
					$this->log->add(
						$this->id,
						'[create_url] - wordpress gave error, payment creation failed with error: ' .
						$checkout_info['response']['message'] );
				}
				return false;
			} else {
				// Obtain the URL.
				if ( 'yes' == $this->debug ) {
					$this->log->add(
						$this->id,
						'[create_url] - payment link generated with success from mercado pago, with structure as follow: ' .
						json_encode( $checkout_info, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE ) );
				}
				return $checkout_info['response'];
			}
		} catch ( MercadoPagoException $e ) {
			// Something went wrong with the payment creation.
			if ( 'yes' == $this->debug ) {
				$this->log->add(
					$this->id,
					'[create_url] - payment creation failed with exception: ' .
					json_encode( array( 'status' => $e->getCode(), 'message' => $e->getMessage() ) )
				);
			}
			return false;
		}
	}*/

	/**
	 * Summary: Check if we have existing customer card, if not we create and save it.
	 * Description: Check if we have existing customer card, if not we create and save it.
	 * @return boolean true/false depending on the validation result.
	 */
	/*public function check_and_save_customer_card( $checkout_info ) {

		if ( 'yes' == $this->debug ) {
			$this->log->add(
				$this->id,
				': @[check_and_save_customer_card] - checking info to create card: ' .
				json_encode( $checkout_info, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE )
			);
		}

		$custId = null;
		$token = null;
		$issuer_id = null;
		$payment_method_id = null;

		if ( isset( $checkout_info['payer']['id'] ) && ! empty( $checkout_info['payer']['id'] ) ) {
			$custId = $checkout_info['payer']['id'];
		} else {
			return;
		}

		if ( isset( $checkout_info['metadata']['token'] ) && ! empty( $checkout_info['metadata']['token'] ) ) {
			$token = $checkout_info['metadata']['token'];
		} else {
			return;
		}

		if ( isset( $checkout_info['issuer_id'] ) && ! empty( $checkout_info['issuer_id'] ) ) {
			$issuer_id = (integer) ( $checkout_info['issuer_id'] );
		}
		if ( isset( $checkout_info['payment_method_id'] ) && ! empty( $checkout_info['payment_method_id'] ) ) {
			$payment_method_id = $checkout_info['payment_method_id'];
		}

		try {
			$this->mp->create_card_in_customer( $custId, $token, $payment_method_id, $issuer_id );
		} catch ( MercadoPagoException $e ) {
			if ( 'yes' == $this->debug ) {
				$this->log->add(
					$this->id,
					'[check_and_save_customer_card] - card creation failed: ' .
					json_encode( array( 'status' => $e->getCode(), 'message' => $e->getMessage() ) )
				);
			}
		}

	}*/

	/**
	 * Summary: Receive post data and applies a discount based in the received values.
	 * Description: Receive post data and applies a discount based in the received values.
	 */
	/*public function add_discount_custom() {

		if ( ! isset( $_POST['mercadopago_custom'] ) )
			return;

		if ( is_admin() && ! defined( 'DOING_AJAX' ) || is_cart() ) {
			return;
		}

		$mercadopago_custom = $_POST['mercadopago_custom'];
		if ( isset( $mercadopago_custom['discount'] ) && $mercadopago_custom['discount'] != '' &&
			$mercadopago_custom['discount'] > 0 && isset( $mercadopago_custom['coupon_code'] ) &&
			$mercadopago_custom['coupon_code'] != '' &&
			WC()->session->chosen_payment_method == 'woocommerce-mercadopago-custom-module' ) {

			if ( 'yes' == $this->debug ) {
				$this->log->add(
					$this->id,
					'[add_discount_custom] - custom checkout trying to apply discount...'
				);
			}

			$value = ( $mercadopago_custom['discount'] ) /
				( (float) $this->currency_ratio > 0 ? (float) $this->currency_ratio : 1 );
			global $woocommerce;
			if ( apply_filters(
				'wc_mercadopagocustom_module_apply_discount',
				0 < $value, $woocommerce->cart )
			) {
				$woocommerce->cart->add_fee( sprintf(
					__( 'Discount for %s coupon', 'woocommerce-mercadopago-module' ),
					esc_attr( $mercadopago_custom['campaign']
					) ), ( $value * -1 ), false
				);
			}
		}

	}*/

	// Display the discount in payment method title.
	/*public function get_payment_method_title_custom( $title, $id ) {

		if ( ! is_checkout() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return $title;
		}

		if ( $title != $this->title || $this->gateway_discount == 0 ) {
			return $title;
		}

		$total = (float) WC()->cart->subtotal;
		if ( is_numeric( $this->gateway_discount ) ) {
			if ( $this->gateway_discount >= 0 && $this->gateway_discount < 100 ) {
				$price_percent = $this->gateway_discount / 100;
				if ( $price_percent > 0 ) {
					$title .= ' (' . __( 'Discount Of ', 'woocommerce-mercadopago-module' ) .
						strip_tags( wc_price( $total * $price_percent ) ) . ' )';
				}
			}
		}

		return $title;
	}*/

	/*
	 * ========================================================================
	 * AUXILIARY AND FEEDBACK METHODS (SERVER SIDE)
	 * ========================================================================
	 */

	// Called automatically by WooCommerce, verify if Module is available to use.
	public function is_available() {
		if ( ! did_action( 'wp_loaded' ) ) {
			return false;
		}
		global $woocommerce;
		$w_cart = $woocommerce->cart;
		// Check if we have SSL.
		if ( empty( $_SERVER['HTTPS'] ) || $_SERVER['HTTPS'] == 'off' ) {
			return false;
		}
		// Check for recurrent product checkout.
		if ( isset( $w_cart ) ) {
			if ( WC_Woo_Mercado_Pago_Module::is_subscription( $w_cart->get_cart() ) ) {
				return false;
			}
		}
		// Check if this gateway is enabled and well configured.
		$_mp_public_key = get_option( '_mp_public_key' );
		$_mp_access_token = get_option( '_mp_access_token' );
		$_site_id_v1 = get_option( '_site_id_v1' );
		$available = ( 'yes' == $this->settings['enabled'] ) &&
			! empty( $_mp_public_key ) &&
			! empty( $_mp_access_token ) &&
			! empty( $_site_id_v1 );
		return $available;
	}

	/*public function check_ssl_absence() {
		if ( empty( $_SERVER['HTTPS'] ) || $_SERVER['HTTPS'] == 'off' ) {
			if ( 'yes' == $this->settings['enabled'] ) {
				echo '<div class="error"><p><strong>' .
					__( 'Custom Checkout is Inactive', 'woocommerce-mercadopago-module' ) .
					'</strong>: ' .
					sprintf(
						__( 'Your site appears to not have SSL certification. SSL is a pre-requisite because the payment process is made in your server.', 'woocommerce-mercadopago-module' )
					) . '</p></div>';
			}
		}
	}

	// Get the URL to admin page.
	protected function admin_url() {
		if (defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '2.1', '>=' ) ) {
			return admin_url(
				'admin.php?page=wc-settings&tab=checkout&section=wc_woomercadopagocustom_gateway'
			);
		}
		return admin_url(
			'admin.php?page=woocommerce_settings&tab=payment_gateways&section=WC_WooMercadoPagoCustom_Gateway'
		);
	}*/

	/*public function get_order_status( $status_detail ) {
		switch ( $status_detail ) {
			case 'accredited':
				return __( 'Done, your payment was accredited!', 'woocommerce-mercadopago-module' );
			case 'pending_contingency':
				return __( 'We are processing the payment. In less than an hour we will e-mail you the results.', 'woocommerce-mercadopago-module' );
			case 'pending_review_manual':
				return __( 'We are processing the payment. In less than 2 business days we will tell you by e-mail whether it has accredited or we need more information.', 'woocommerce-mercadopago-module' );
			case 'cc_rejected_bad_filled_card_number':
				return __( 'Check the card number.', 'woocommerce-mercadopago-module' );
			case 'cc_rejected_bad_filled_date':
				return __( 'Check the expiration date.', 'woocommerce-mercadopago-module' );
			case 'cc_rejected_bad_filled_other':
				return __( 'Check the information.', 'woocommerce-mercadopago-module' );
			case 'cc_rejected_bad_filled_security_code':
				return __( 'Check the security code.', 'woocommerce-mercadopago-module' );
			case 'cc_rejected_blacklist':
				return __( 'We could not process your payment.', 'woocommerce-mercadopago-module' );
			case 'cc_rejected_call_for_authorize':
				return __( 'You must authorize the payment of your orders.', 'woocommerce-mercadopago-module' );
			case 'cc_rejected_card_disabled':
				return __( 'Call your card issuer to activate your card. The phone is on the back of your card.', 'woocommerce-mercadopago-module' );
			case 'cc_rejected_card_error':
				return __( 'We could not process your payment.', 'woocommerce-mercadopago-module' );
			case 'cc_rejected_duplicated_payment':
				return __( 'You already made a payment for that amount. If you need to repay, use another card or other payment method.', 'woocommerce-mercadopago-module' );
			case 'cc_rejected_high_risk':
				return __( 'Your payment was rejected. Choose another payment method. We recommend cash.', 'woocommerce-mercadopago-module' );
			case 'cc_rejected_insufficient_amount':
				return __( 'Your payment do not have sufficient funds.', 'woocommerce-mercadopago-module' );
			case 'cc_rejected_invalid_installments':
				return __( 'Your payment does not process payments with selected installments.', 'woocommerce-mercadopago-module' );
			case 'cc_rejected_max_attempts':
				return __( 'You have reached the limit of allowed attempts. Choose another card or another payment method.', 'woocommerce-mercadopago-module' );
			case 'cc_rejected_other_reason':
				return __( 'This payment method did not process the payment.', 'woocommerce-mercadopago-module' );
			default:
				return __( 'This payment method did not process the payment.', 'woocommerce-mercadopago-module' );
		}
	}*/

	/*
	 * ========================================================================
	 * IPN MECHANICS (SERVER SIDE)
	 * ========================================================================
	 */

	/**
	 * Summary: This call checks any incoming notifications from Mercado Pago server.
	 * Description: This call checks any incoming notifications from Mercado Pago server.
	 */
	/*public function process_http_request() {
		@ob_clean();
		if ( 'yes' == $this->debug ) {
			$this->log->add(
				$this->id,
				'[process_http_request] - Received _get content: ' .
				json_encode( $_GET, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE )
			);
		}
		if ( isset( $_GET['coupon_id'] ) && $_GET['coupon_id'] != '' ) {
			// Process coupon evaluations.
			if ( isset( $_GET['payer'] ) && $_GET['payer'] != '' ) {
				$logged_user_email = $_GET['payer'];
				$coupon_id = $_GET['coupon_id'];
			if ( 'yes' == $this->sandbox )
				$this->mp->sandbox_mode( true );
			else
				$this->mp->sandbox_mode( false );
				$response = $this->mp->check_discount_campaigns(
			 	$_GET['amount'],
					$logged_user_email,
					$coupon_id
				);
				header( 'HTTP/1.1 200 OK' );
				header( 'Content-Type: application/json' );
				echo json_encode( $response );
			} else {
				$obj = new stdClass();
				$obj->status = 404;
				$obj->response = array(
					'message' =>
						__( 'Please, inform your email in billing address to use this feature', 'woocommerce-mercadopago-module' ),
					'error' => 'payer_not_found',
					'status' => 404,
					'cause' => array()
				);
				header( 'HTTP/1.1 200 OK' );
				header( 'Content-Type: application/json' );
				echo json_encode( $obj );
			}
			exit( 0 );
		} else {
			// Process IPN messages.
			$data = $this->check_ipn_request_is_valid( $_GET );
			if ( $data ) {
				header( 'HTTP/1.1 200 OK' );
				do_action( 'valid_mercadopagocustom_ipn_request', $data );
			}
		}
	}*/

	/**
	 * Summary: Get received data from IPN and checks if its a merchant_order or a payment.
	 * Description: If we have these information, we return data to be processed by
	 * successful_request function.
	 * @return boolean indicating if it was successfuly processed.
	 */
	/*public function check_ipn_request_is_valid( $data ) {

		if ( ! isset( $data['data_id'] ) || ! isset( $data['type'] ) ) {
			if ( 'yes' == $this->debug ) {
				$this->log->add(
					$this->id,
					'[check_ipn_request_is_valid] - data_id or type not set: ' .
					json_encode( $data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE )
				);
			}
			// At least, check if its a v0 ipn.
			if ( ! isset( $data['id'] ) || ! isset( $data['topic'] ) ) {
				if ( 'yes' == $this->debug ) {
					$this->log->add(
						$this->id,
						'[check_ipn_request_is_valid] - Mercado Pago Request failure: ' .
						json_encode( $_GET, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE )
					);
				}
				wp_die( __( 'Mercado Pago Request Failure', 'woocommerce-mercadopago-module' ) );
			} else {
				header( 'HTTP/1.1 200 OK' );
			}
			// No ID? No process!
			return false;
		}

		if ( 'yes' == $this->sandbox ) {
			$this->mp->sandbox_mode( true );
		} else {
			$this->mp->sandbox_mode( false );
		}

		try {
			// Get the payment reported by the IPN.
			if ( $data['type'] == 'payment' ) {
				$access_token = array( 'access_token' => $this->mp->get_access_token() );
				$payment_info = $this->mp->get(
					'/v1/payments/' . $data['data_id'], $access_token, false
				);
				if ( ! is_wp_error( $payment_info ) &&
					( $payment_info['status'] == 200 || $payment_info['status'] == 201 ) ) {
					return $payment_info['response'];
				} else {
					if ( 'yes' == $this->debug) {
						$this->log->add(
							$this->id,
							'[check_ipn_request_is_valid] - error when processing received data: ' .
							json_encode( $payment_info, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE )
						);
					}
					return false;
				}
			}
		} catch ( MercadoPagoException $e ) {
			if ( 'yes' == $this->debug ) {
				$this->log->add(
					$this->id,
					'[check_ipn_request_is_valid] - MercadoPagoException: ' .
					json_encode( array( 'status' => $e->getCode(), 'message' => $e->getMessage() ) )
				);
			}
			return false;
		}
		return true;
	}*/

	/**
	 * Summary: Properly handles each case of notification, based in payment status.
	 * Description: Properly handles each case of notification, based in payment status.
	 */
	/*public function successful_request( $data ) {

		if ( 'yes' == $this->debug ) {
			$this->log->add(
				$this->id,
				'[successful_request] - starting to process ipn update...'
			);
		}

		// Get the order and check its presence.
		$order_key = $data['external_reference'];
		if ( empty( $order_key ) ) {
			return;
		}
		$id = (int) str_replace( $this->invoice_prefix, '', $order_key );
		$order = wc_get_order( $id );

		// Check if order exists.
		if ( ! $order ) {
			return;
		}

		// WooCommerce 3.0 or later.
		if ( method_exists( $order, 'get_id' ) ) {
			$order_id = $order->get_id();
		} else {
			$order_id = $order->id;
		}

		// Check if we have the correct order.
		if ( $order_id !== $id ) {
			return;
		}

		if ( 'yes' == $this->debug ) {
			$this->log->add(
				$this->id,
				'[successful_request] - updating metadata and status with data: ' .
				json_encode( $data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE )
			);
		}

		// Here, we process the status... this is the business rules!
		// Reference: https://www.mercadopago.com.br/developers/en/api-docs/basic-checkout/ipn/payment-status/
		$status = isset( $data['status'] ) ? $data['status'] : 'pending';
		$total_paid = isset( $data['transaction_details']['total_paid_amount'] ) ? $data['transaction_details']['total_paid_amount'] : 0.00;
		$total_refund = isset( $data['transaction_amount_refunded'] ) ? $data['transaction_amount_refunded'] : 0.00;
		$total = $data['transaction_amount'];

		if ( method_exists( $order, 'update_meta_data' ) ) {
			$order->update_meta_data( '_used_gateway', 'WC_WooMercadoPagoCustom_Gateway' );

			if ( ! empty( $data['payer']['email'] ) ) {
				$order->update_meta_data( __( 'Payer email', 'woocommerce-mercadopago-module' ), $data['payer']['email'] );
			}

			if ( ! empty( $data['payment_type_id'] ) ) {
				$order->update_meta_data( __( 'Payment type', 'woocommerce-mercadopago-module' ), $data['payment_type_id'] );
			}

			$payment_id = $data['id'];

			$order->update_meta_data( 'Mercado Pago - Payment ' . $payment_id,
				'Mercado Pago - Payment ' . $payment_id,
				'[Date ' . date( 'Y-m-d H:i:s', strtotime( $data['date_created'] ) ) .
				']/[Amount ' . $total .
				']/[Paid ' . $total_paid .
				']/[Refund ' . $total_refund . ']'
			);

			$order->update_meta_data( '_Mercado_Pago_Payment_IDs', $payment_id );
			$order->save();

		} else {
			update_post_meta( $order_id, '_used_gateway', 'WC_WooMercadoPagoCustom_Gateway' );

			if ( ! empty( $data['payer']['email'] ) ) {
				update_post_meta(
					$order_id,
					__( 'Payer email', 'woocommerce-mercadopago-module' ),
					$data['payer']['email']
				);
			}
			if ( ! empty( $data['payment_type_id'] ) ) {
				update_post_meta(
					$order_id,
					__( 'Payment type', 'woocommerce-mercadopago-module' ),
					$data['payment_type_id']
				);
			}
			$payment_id = $data['id'];
			update_post_meta(
				$order_id,
				'Mercado Pago - Payment ' . $payment_id,
				'[Date ' . date( 'Y-m-d H:i:s', strtotime( $data['date_created'] ) ) .
				']/[Amount ' . $total .
				']/[Paid ' . $total_paid .
				']/[Refund ' . $total_refund . ']'
			);
			update_post_meta(
				$order_id,
				'_Mercado_Pago_Payment_IDs',
				$payment_id
			);
		}

		// Switch the status and update in WooCommerce
		switch ( $status ) {
			case 'approved':
				$order->add_order_note(
					'Mercado Pago: ' . __( 'Payment approved.', 'woocommerce-mercadopago-module' )
				);
				$this->check_and_save_customer_card( $data );
				$order->payment_complete();
				break;
			case 'pending':
				$order->add_order_note(
					'Mercado Pago: ' . __( 'Customer haven\'t paid yet.', 'woocommerce-mercadopago-module' )
				);
				break;
			case 'in_process':
				$order->update_status(
					'on-hold',
					'Mercado Pago: ' . __( 'Payment under review.', 'woocommerce-mercadopago-module' )
				);
				break;
			case 'rejected':
				$order->update_status(
					'failed',
					'Mercado Pago: ' .
						__( 'The payment was refused. The customer can try again.', 'woocommerce-mercadopago-module' )
				);
				break;
			case 'refunded':
				$order->update_status(
					'refunded',
					'Mercado Pago: ' .
						__( 'The payment was refunded to the customer.', 'woocommerce-mercadopago-module' )
				);
				break;
			case 'cancelled':
				$order->update_status(
					'cancelled',
					'Mercado Pago: ' .
						__( 'The payment was cancelled.', 'woocommerce-mercadopago-module' )
				);
				break;
			case 'in_mediation':
				$order->add_order_note(
					'Mercado Pago: ' .
						__( 'The payment is under mediation or it was charged-back.', 'woocommerce-mercadopago-module' )
				);
				break;
			case 'charged-back':
				$order->add_order_note(
					'Mercado Pago: ' .
						__( 'The payment is under mediation or it was charged-back.', 'woocommerce-mercadopago-module' )
				);
				break;
			default:
				break;
		}
	}*/

}

new WC_WooMercadoPago_CustomGateway( true );
