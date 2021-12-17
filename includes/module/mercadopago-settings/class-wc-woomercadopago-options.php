<?php

class WC_WooMercadoPago_Options {

	const CREDENTIALS_PUBLIC_KEY_PROD   = '_mp_public_key_prod';
	const CREDENTIALS_PUBLIC_KEY_TEST   = '_mp_public_key_test';
	const CREDENTIALS_ACCESS_TOKEN_PROD = '_mp_access_token_prod';
	const CREDENTIALS_ACCESS_TOKEN_TEST = '_mp_access_token_test';

	private $credentials_public_key_prod;
	private $credentials_public_key_test;
	private $credentials_access_token_prod;
	private $credentials_access_token_test;

	public function __construct() {

		$this->credentials_public_key_prod   = get_option( self::CREDENTIALS_PUBLIC_KEY_PROD, '' );
		$this->credentials_public_key_test   = get_option( self::CREDENTIALS_PUBLIC_KEY_TEST, '' );
		$this->credentials_access_token_prod = get_option( self::CREDENTIALS_ACCESS_TOKEN_PROD, '' );
		$this->credentials_access_token_test = get_option( self::CREDENTIALS_ACCESS_TOKEN_TEST, '' );
	}

	/**
	 * Get Access token and Public Key
	 *
	 * @return mixed|array
	 */
	public function get_access_token_and_public_key() {

		return array (
			'credentials_public_key_prod' => $this->credentials_public_key_prod,
			'credentials_public_key_test' => $this->credentials_public_key_test,
			'credentials_access_token_prod' => $this->credentials_access_token_prod,
			'credentials_access_token_test' => $this->credentials_access_token_test,
		);

	}
	public function get_store_activity_identifier() {
		$store_identificator = get_option( '_mp_store_identificator', 'WC-' );

		return $store_identificator;
	}

	public function get_store_name_on_invoice() {
		$store_identificator = get_option( '_mp_category_id', '' );

		return $store_identificator;
	}

	public function get_store_category() {
		$category_store = get_option( '_mp_category_id', 'other');
		return $category_store;
	}

	public function get_integrator_id() {
		$integrator_id = get_option( '_mp_integrator_id', '' );
		return $integrator_id;
	}

	public function get_mp_devsite_links(){
		$link = WC_WooMercadoPago_Module::define_link_country();
		$base_link = "https://www.mercadopago." . $link['sufix_url'] . "developers/" . $link['translate'];
		$devsite_links = array( "dev_program" => $base_link . "/developer-program",
								"notifications_ipn" => $base_link . "/guides/notifications/ipn",);
		return $devsite_links;
	}

	public function get_debug_mode() {
		$debug_mode = get_option( '_mp_debug_mode', 'yes' );
		return $debug_mode;
	}

}
