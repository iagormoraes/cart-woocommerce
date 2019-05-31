<?php

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

$GLOBALS['LIB_LOCATION'] = dirname(__FILE__);

/**
 * Class MP
 */
class MP
{

    private $version = '3.1.0';
    private $client_id;
    private $client_secret;
    private $ll_access_token;
    private $sandbox = FALSE;

    /**
     * MP constructor.
     * @throws MercadoPagoException
     */
    function __construct()
    {
        $includes_path = dirname(__FILE__);
        require_once($includes_path . '');
        require_once($includes_path . '/RestClient/AbstractRestClient.php');
        require_once($includes_path . '/RestClient/MeliRestClient.php');
        require_once($includes_path . '/RestClient/MpRestClient.php');

        $i = func_num_args();
        if ($i > 3 || $i < 2) {
            throw new MercadoPagoException('Invalid arguments. Use CLIENT_ID and CLIENT SECRET, or ACCESS_TOKEN');
        }

        if ($i == 2) {
            $this->version = func_get_arg(0);
            $this->ll_access_token = func_get_arg(1);
        }

        if ($i == 3) {
            $this->version = func_get_arg(0);
            $this->client_id = func_get_arg(1);
            $this->client_secret = func_get_arg(2);
        }

    }

    /**
     * @param $email
     */
    public function set_email($email)
    {
        MPRestClient::set_email($email);
        MeliRestClient::set_email($email);
    }

    /**
     * @param $country_code
     */
    public function set_locale($country_code)
    {
        MPRestClient::set_locale($country_code);
        MeliRestClient::set_locale($country_code);
    }

    /**
     * @param null $enable
     * @return bool
     */
    public function sandbox_mode($enable = NULL)
    {
        if (!is_null($enable)) {
            $this->sandbox = $enable === TRUE;
        }
        return $this->sandbox;
    }

    /**
     * @return mixed|null
     * @throws MercadoPagoException
     */
    public function get_access_token()
    {

        if (isset($this->ll_access_token) && !is_null($this->ll_access_token)) {
            return $this->ll_access_token;
        }

        $app_client_values = array(
            'client_id' => $this->client_id,
            'client_secret' => $this->client_secret,
            'grant_type' => 'client_credentials'
        );

        $access_data = MPRestClient::post(
            array(
                'uri' => '/oauth/token',
                'data' => $app_client_values,
                'headers' => array(
                    'content-type' => 'application/x-www-form-urlencoded'
                )
            ),
            $this->version
        );

        if ($access_data['status'] != 200) {
            return null;
        }

        $access_data = $access_data['response'];
        return $access_data['access_token'];

    }

    /**
     * @param $id
     * @return array|null
     * @throws MercadoPagoException
     */
    public function search_paymentV1($id)
    {

        $request = array(
            'uri' => '/v1/payments/' . $id,
            'params' => array('access_token' => $this->get_access_token())
        );

        $payment = MPRestClient::get($request, $this->version);
        return $payment;
    }

    //=== CUSTOMER CARDS FUNCTIONS ===

    /**
     * @param $payer_email
     * @return array|mixed
     * @throws MercadoPagoException
     */
    public function get_or_create_customer($payer_email)
    {

        $customer = $this->search_customer($payer_email);

        if ($customer['status'] == 200 && $customer['response']['paging']['total'] > 0) {
            $customer = $customer['response']['results'][0];
        } else {
            $resp = $this->create_customer($payer_email);
            $customer = $resp['response'];
        }

        return $customer;

    }

    /**
     * @param $email
     * @return array|null
     * @throws MercadoPagoException
     */
    public function create_customer($email)
    {

        $request = array(
            'uri' => '/v1/customers',
            'params' => array(
                'access_token' => $this->get_access_token()
            ),
            'data' => array(
                'email' => $email
            )
        );

        $customer = MPRestClient::post($request, $this->version);
        return $customer;

    }

    /**
     * @param $email
     * @return array|null
     * @throws MercadoPagoException
     */
    public function search_customer($email)
    {

        $request = array(
            'uri' => '/v1/customers/search',
            'params' => array(
                'access_token' => $this->get_access_token(),
                'email' => $email
            )
        );

        $customer = MPRestClient::get($request, $this->version);
        return $customer;

    }

    /**
     * @param $customer_id
     * @param $token
     * @param null $payment_method_id
     * @param null $issuer_id
     * @return array|null
     * @throws MercadoPagoException
     */
    public function create_card_in_customer($customer_id, $token, $payment_method_id = null,
                                            $issuer_id = null)
    {

        $request = array(
            'uri' => '/v1/customers/' . $customer_id . '/cards',
            'params' => array(
                'access_token' => $this->get_access_token()
            ),
            'data' => array(
                'token' => $token,
                'issuer_id' => $issuer_id,
                'payment_method_id' => $payment_method_id
            )
        );

        $card = MPRestClient::post($request, $this->version);
        return $card;

    }

    /**
     * @param $customer_id
     * @param $token
     * @return array|null
     * @throws MercadoPagoException
     */
    public function get_all_customer_cards($customer_id, $token)
    {

        $request = array(
            'uri' => '/v1/customers/' . $customer_id . '/cards',
            'params' => array(
                'access_token' => $this->get_access_token()
            )
        );

        $cards = MPRestClient::get($request, $this->version);
        return $cards;

    }

    //=== COUPOM AND DISCOUNTS FUNCTIONS ===

    /**
     * @param $transaction_amount
     * @param $payer_email
     * @param $coupon_code
     * @return array|null
     * @throws MercadoPagoException
     */
    public function check_discount_campaigns($transaction_amount, $payer_email, $coupon_code)
    {

        $request = array(
            'uri' => '/discount_campaigns',
            'params' => array(
                'access_token' => $this->get_access_token(),
                'transaction_amount' => $transaction_amount,
                'payer_email' => $payer_email,
                'coupon_code' => $coupon_code
            )
        );

        $discount_info = MPRestClient::get($request, $this->version);
        return $discount_info;

    }

    //=== ACCOUNT SETTINGS FUNCTIONS ===

    /**
     * @return string
     * @throws MercadoPagoException
     */
    public function check_two_cards()
    {

        $request = array(
            'uri' => '/account/settings?access_token=' . $this->get_access_token()
        );

        $two_cards_info = MPRestClient::get($request, $this->version);
        if ($two_cards_info['status'] == 200)
            return $two_cards_info['response']['two_cards'];
        else {
            return 'inactive';
        }

    }

    /**
     * @param $mode
     * @return array|null
     * @throws MercadoPagoException
     */
    public function set_two_cards_mode($mode)
    {

        $request = array(
            'uri' => '/account/settings?access_token=' . $this->get_access_token(),
            'data' => array(
                'two_cards' => $mode
            ),
            'headers' => array(
                'content-type' => 'application/json'
            )
        );

        $two_cards_info = MPRestClient::put($request, $this->version);
        return $two_cards_info;

    }

    //=== CHECKOUT AUXILIARY FUNCTIONS ===

    /**
     * @param $id
     * @return array|null
     * @throws MercadoPagoException
     */
    public function get_authorized_payment($id)
    {

        $request = array(
            'uri' => '/authorized_payments/{$id}',
            'params' => array(
                'access_token' => $this->get_access_token()
            )
        );

        $authorized_payment_info = MPRestClient::get($request, $this->version);
        return $authorized_payment_info;

    }

    /**
     * @param $preference
     * @return array|null
     * @throws MercadoPagoException
     */
    public function create_preference($preference)
    {

        $request = array(
            'uri' => '/checkout/preferences',
            'params' => array(
                'access_token' => $this->get_access_token()
            ),
            'headers' => array(
                'user-agent' => 'platform:desktop,type:woocommerce,so:' . $this->version
            ),
            'data' => $preference
        );

        $preference_result = MPRestClient::post($request, $this->version);
        return $preference_result;

    }

    /**
     * @param $id
     * @param $preference
     * @return array|null
     * @throws MercadoPagoException
     */
    public function update_preference($id, $preference)
    {

        $request = array(
            'uri' => '/checkout/preferences/{$id}',
            'params' => array(
                'access_token' => $this->get_access_token()
            ),
            'data' => $preference
        );

        $preference_result = MPRestClient::put($request, $this->version);
        return $preference_result;

    }

    /**
     * @param $id
     * @return array|null
     * @throws MercadoPagoException
     */
    public function get_preference($id)
    {

        $request = array(
            'uri' => '/checkout/preferences/{$id}',
            'params' => array(
                'access_token' => $this->get_access_token()
            )
        );

        $preference_result = MPRestClient::get($request, $this->version);
        return $preference_result;

    }

    /**
     * @param $preference
     * @return array|null
     * @throws MercadoPagoException
     */
    public function create_payment($preference)
    {

        $request = array(
            'uri' => '/v1/payments',
            'params' => array(
                'access_token' => $this->get_access_token()
            ),
            'headers' => array(
                'X-Tracking-Id' => 'platform:v1-whitelabel,type:woocommerce,so:' . $this->version
            ),
            'data' => $preference
        );

        $payment = MPRestClient::post($request, $this->version);
        return $payment;
    }

    /**
     * @param $preapproval_payment
     * @return array|null
     * @throws MercadoPagoException
     */
    public function create_preapproval_payment($preapproval_payment)
    {

        $request = array(
            'uri' => '/preapproval',
            'params' => array(
                'access_token' => $this->get_access_token()
            ),
            'data' => $preapproval_payment
        );

        $preapproval_payment_result = MPRestClient::post($request, $this->version);
        return $preapproval_payment_result;

    }

    /**
     * @param $id
     * @return array|null
     * @throws MercadoPagoException
     */
    public function get_preapproval_payment($id)
    {

        $request = array(
            'uri' => '/preapproval/' . $id,
            'params' => array(
                'access_token' => $this->get_access_token()
            )
        );

        $preapproval_payment_result = MPRestClient::get($request, $this->version);
        return $preapproval_payment_result;

    }

    /**
     * @param $id
     * @param $preapproval_payment
     * @return array|null
     * @throws MercadoPagoException
     */
    public function update_preapproval_payment($id, $preapproval_payment)
    {

        $request = array(
            'uri' => '/preapproval/' . $id,
            'params' => array(
                'access_token' => $this->get_access_token()
            ),
            'data' => $preapproval_payment
        );

        $preapproval_payment_result = MPRestClient::put($request, $this->version);
        return $preapproval_payment_result;

    }

    /**
     * @param $id
     * @return array|null
     * @throws MercadoPagoException
     */
    public function cancel_preapproval_payment($id)
    {

        $request = array(
            'uri' => '/preapproval/' . $id,
            'params' => array(
                'access_token' => $this->get_access_token()
            ),
            'data' => array(
                'status' => 'cancelled'
            )
        );

        $response = MPRestClient::put($request, $this->version);
        return $response;

    }

    //=== REFUND AND CANCELING FLOW FUNCTIONS ===

    /**
     * @param $id
     * @return array|null
     * @throws MercadoPagoException
     */
    public function refund_payment($id)
    {

        $request = array(
            'uri' => '/v1/payments/' . $id . '/refunds',
            'params' => array(
                'access_token' => $this->get_access_token()
            )
        );

        $response = MPRestClient::post($request, $this->version);
        return $response;

    }

    /**
     * @param $id
     * @param $amount
     * @param $reason
     * @param $external_reference
     * @return array|null
     * @throws MercadoPagoException
     */
    public function partial_refund_payment($id, $amount, $reason, $external_reference)
    {

        $request = array(
            'uri' => '/v1/payments/' . $id . '/refunds?access_token=' . $this->get_access_token(),
            'data' => array(
                'amount' => $amount,
                'metadata' => array(
                    'metadata' => $reason,
                    'external_reference' => $external_reference
                )
            )
        );

        $response = MPRestClient::post($request, $this->version);
        return $response;

    }

    /**
     * @param $id
     * @return array|null
     * @throws MercadoPagoException
     */
    public function cancel_payment($id)
    {

        $request = array(
            'uri' => '/v1/payments/' . $id,
            'params' => array(
                'access_token' => $this->get_access_token()
            ),
            'data' => '{"status":"cancelled"}'
        );

        $response = MPRestClient::put($request, $this->version);
        return $response;

    }

    /**
     * @return array|null
     * @throws MercadoPagoException
     */
    public function get_payment_methods()
    {
        $request = array(
            'uri' => '/v1/payment_methods',
            'params' => array(
                'access_token' => $this->get_access_token()
            )
        );

        $response = MPRestClient::get($request, $this->version);

        return $response;
    }

    //=== GENERIC RESOURCE CALL METHODS ===

    /**
     * @param $request
     * @param null $params
     * @param bool $authenticate
     * @return array|null
     * @throws MercadoPagoException
     */
    public function get($request, $params = null, $authenticate = true)
    {

        if (is_string($request)) {
            $request = array(
                'uri' => $request,
                'params' => $params,
                'authenticate' => $authenticate
            );
        }

        $request['params'] = isset($request['params']) && is_array($request['params']) ?
            $request['params'] :
            array();

        if (!isset($request['authenticate']) || $request['authenticate'] !== false) {
            $request['params']['access_token'] = $this->get_access_token();
        }

        $result = MPRestClient::get($request, $this->version);
        return $result;

    }

    /**
     * @param $request
     * @param null $data
     * @param null $params
     * @return array|null
     * @throws MercadoPagoException
     */
    public function post($request, $data = null, $params = null)
    {

        if (is_string($request)) {
            $request = array(
                'uri' => $request,
                'data' => $data,
                'params' => $params
            );
        }

        $request['params'] = isset($request['params']) && is_array($request['params']) ?
            $request["params"] :
            array();

        if (!isset ($request['authenticate']) || $request['authenticate'] !== false) {
            $request['params']['access_token'] = $this->get_access_token();
        }

        $result = MPRestClient::post($request, $this->version);
        return $result;

    }

    /**
     * @param $request
     * @param null $data
     * @param null $params
     * @return array|null
     * @throws MercadoPagoException
     */
    public function put($request, $data = null, $params = null)
    {

        if (is_string($request)) {
            $request = array(
                'uri' => $request,
                'data' => $data,
                'params' => $params
            );
        }

        $request['params'] = isset($request['params']) && is_array($request['params']) ?
            $request['params'] :
            array();

        if (!isset ($request['authenticate']) || $request['authenticate'] !== false) {
            $request['params']['access_token'] = $this->get_access_token();
        }

        $result = MPRestClient::put($request, $this->version);
        return $result;

    }

    /**
     * @param $request
     * @param null $params
     * @return array|null
     * @throws MercadoPagoException
     */
    public function delete($request, $params = null)
    {

        if (is_string($request)) {
            $request = array(
                'uri' => $request,
                'params' => $params
            );
        }

        $request['params'] = isset($request['params']) && is_array($request['params']) ?
            $request['params'] :
            array();

        if (!isset($request['authenticate']) || $request['authenticate'] !== false) {
            $request['params']['access_token'] = $this->get_access_token();
        }

        $result = MPRestClient::delete($request, $this->version);
        return $result;

    }

    //=== MODULE ANALYTICS FUNCTIONS ===

    /**
     * @param $module_info
     * @return array|null
     * @throws MercadoPagoException
     */
    public function analytics_save_settings($module_info)
    {

        $request = array(
            'uri' => '/modules/tracking/settings?access_token=' . $this->get_access_token(),
            'data' => $module_info
        );

        $result = MPRestClient::post($request, $this->version);
        return $result;

    }

}






