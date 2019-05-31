<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class WC_WooMercadoPago_Notification_Custom
 */
class WC_WooMercadoPago_Notification_Custom extends WC_WooMercadoPago_Notification_Abstract
{
    /**
     * @var null
     */
    private static $instance = null;

    /**
     * WC_WooMercadoPago_Notification_Custom constructor.
     * @throws MercadoPagoException
     */
    public function __construct()
    {
        parent::__construct();
        add_action('woocommerce_api_wc_woomercadopago_customgateway', array($this, 'check_ipn_response'));
        add_action('valid_mercadopago_custom_ipn_request', array($this, 'successful_request'));
        add_action('woocommerce_order_action_cancel_order', array($this, 'process_cancel_order_meta_box_actions'));

        $this->log->setId('WooMercadoPago_Notification_Custom');
    }

    /**
     * @return WC_WooMercadoPago_Notification_Custom|null
     * @throws MercadoPagoException
     */
    public static function getNotificationCustomInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self;
        }
        return self::$instance;
    }

    /**
     * Notification Custom
     */
    public function check_ipn_response()
    {
        parent::check_ipn_response();
        $data = $_GET;
        if (isset($data['coupon_id']) && !empty($data['coupon_id'])) {
            if (isset($data['payer']) && !empty($data['payer'])) {
                $response = $this->mp->check_discount_campaigns($data['amount'], $data['payer'], $data['coupon_id']);
                header('HTTP/1.1 200 OK');
                header('Content-Type: application/json');
                echo json_encode($response);
            } else {
                $obj = new stdClass();
                $obj->status = 404;
                $obj->response = array(
                    'message' => __('Please, inform your email in billing address to use this feature', 'woocommerce-mercadopago'),
                    'error' => 'payer_not_found',
                    'status' => 404,
                    'cause' => array()
                );
                header('HTTP/1.1 200 OK');
                header('Content-Type: application/json');
                echo json_encode($obj);
            }
            exit(0);
        } else if (!isset($data['data_id']) || !isset($data['type'])) {
            $this->log->write_log(__FUNCTION__, 'data_id or type not set: ' . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            if (!isset($data['id']) || !isset($data['topic'])) {
                $this->log->write_log(__FUNCTION__, 'Mercado Pago Request failure: ' . json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                wp_die(__('Mercado Pago Request Failure', 'woocommerce-mercadopago'));
            } else {
                header('HTTP/1.1 200 OK');
            }
        } else {
            if ($data['type'] == 'payment') {
                $access_token = array('access_token' => $this->mp->get_access_token());
                $payment_info = $this->mp->get('/v1/payments/' . $data['data_id'], $access_token, false);
                if (!is_wp_error($payment_info) && ($payment_info['status'] == 200 || $payment_info['status'] == 201)) {
                    if ($payment_info['response']) {
                        header('HTTP/1.1 200 OK');
                        do_action('valid_mercadopago_custom_ipn_request', $payment_info['response']);
                    }
                } else {
                    $this->log->write_log(__FUNCTION__, 'error when processing received data: ' . json_encode($payment_info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                }
            }
        }
    }

    /**
     * @param $data
     */
    public function successful_request($data)
    {
        $order = parent::successful_request($data);

        $status = isset($data['status']) ? $data['status'] : 'pending';
        $total_paid = isset($data['transaction_details']['total_paid_amount']) ? $data['transaction_details']['total_paid_amount'] : 0.00;
        $total_refund = isset($data['transaction_amount_refunded']) ? $data['transaction_amount_refunded'] : 0.00;

        // WooCommerce 3.0 or later.
        if (method_exists($order, 'update_meta_data')) {
            // Updates the type of gateway.
            $order->update_meta_data('_used_gateway', 'WC_WooMercadoPago_CustomGateway');
            if (!empty($data['payer']['email'])) {
                $order->update_meta_data(__('Payer email', 'woocommerce-mercadopago'), $data['payer']['email']);
            }
            if (!empty($data['payment_type_id'])) {
                $order->update_meta_data(__('Payment type', 'woocommerce-mercadopago'), $data['payment_type_id']);
            }
            $order->update_meta_data(
                'Mercado Pago - Payment ' . $data['id'],
                '[Date ' . date('Y-m-d H:i:s', strtotime($data['date_created'])) .
                ']/[Amount ' . $data['transaction_amount'] .
                ']/[Paid ' . $total_paid .
                ']/[Refund ' . $total_refund . ']'
            );
            $order->update_meta_data('_Mercado_Pago_Payment_IDs', $data['id']);
            $order->save();
        } else {
            // Updates the type of gateway.
            update_post_meta($order->id, '_used_gateway', 'WC_WooMercadoPago_CustomGateway');
            if (!empty($data['payer']['email'])) {
                update_post_meta($order->id, __('Payer email', 'woocommerce-mercadopago'), $data['payer']['email']);
            }
            if (!empty($data['payment_type_id'])) {
                update_post_meta($order->id, __('Payment type', 'woocommerce-mercadopago'), $data['payment_type_id']);
            }
            update_post_meta(
                $order->id,
                'Mercado Pago - Payment ' . $data['id'],
                '[Date ' . date('Y-m-d H:i:s', strtotime($data['date_created'])) .
                ']/[Amount ' . $data['transaction_amount'] .
                ']/[Paid ' . $total_paid .
                ']/[Refund ' . $total_refund . ']'
            );
            update_post_meta($order->id, '_Mercado_Pago_Payment_IDs', $data['id']);
        }
        // Switch the status and update in WooCommerce.
        $this->log->write_log(__FUNCTION__, 'Changing order status to: ' . parent::get_wc_status_for_mp_status(str_replace('_', '', $status)));

        $this->proccessStatus($status, $data, $order);

    }

    /**
     * @param $order
     * @throws MercadoPagoException
     */
    public function process_cancel_order_meta_box_actions($order)
    {

        $used_gateway = (method_exists($order, 'get_meta')) ? $order->get_meta('_used_gateway') : get_post_meta($order->id, '_used_gateway', true);
        $payments = (method_exists($order, 'get_meta')) ? $order->get_meta('_Mercado_Pago_Payment_IDs') : get_post_meta($order->id, '_Mercado_Pago_Payment_IDs', true);

        if ($used_gateway != 'WC_WooMercadoPago_CustomGateway') {
            return;
        }
        $this->log->write_log(__FUNCTION__, 'cancelling payments for ' . $payments);
        // Canceling the order and all of its payments.
        if ($this->mp != null && !empty($payments)) {
            $payment_ids = explode(', ', $payments);
            foreach ($payment_ids as $p_id) {
                $response = $this->mp->cancel_payment($p_id);
                $message = $response['response']['message'];
                $status = $response['status'];
                $this->log->write_log(__FUNCTION__, 'cancel payment of id ' . $p_id . ' => ' . ($status >= 200 && $status < 300 ? 'SUCCESS' : 'FAIL - ' . $message));
            }
        } else {
            $this->log->write_log(__FUNCTION__, 'no payments or credentials invalid');
        }
    }

    /**
     * @param $checkout_info
     */
    public function check_and_save_customer_card($checkout_info)
    {
        $this->log->write_log(__FUNCTION__, 'checking info to create card: ' . json_encode($checkout_info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $custId = null;
        $token = null;
        $issuer_id = null;
        $payment_method_id = null;
        if (isset($checkout_info['payer']['id']) && !empty($checkout_info['payer']['id'])) {
            $custId = $checkout_info['payer']['id'];
        } else {
            return;
        }
        if (isset($checkout_info['metadata']['token']) && !empty($checkout_info['metadata']['token'])) {
            $token = $checkout_info['metadata']['token'];
        } else {
            return;
        }
        if (isset($checkout_info['issuer_id']) && !empty($checkout_info['issuer_id'])) {
            $issuer_id = (integer)($checkout_info['issuer_id']);
        }
        if (isset($checkout_info['payment_method_id']) && !empty($checkout_info['payment_method_id'])) {
            $payment_method_id = $checkout_info['payment_method_id'];
        }
        try {
            $this->mp->create_card_in_customer($custId, $token, $payment_method_id, $issuer_id);
        } catch (MercadoPagoException $ex) {
            $this->log->write_log(__FUNCTION__, 'card creation failed: ' . json_encode($ex, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * @param $data
     * @param $order
     * @return string
     */
    public function process_status_mp_business($data, $order)
    {
        $status = isset($data['status']) ? $data['status'] : 'pending';
        $total_paid = isset($data['transaction_details']['total_paid_amount']) ? $data['transaction_details']['total_paid_amount'] : 0.00;
        $total_refund = isset($data['transaction_amount_refunded']) ? $data['transaction_amount_refunded'] : 0.00;
        // WooCommerce 3.0 or later.
        if (method_exists($order, 'update_meta_data')) {
            // Updates the type of gateway.
            $order->update_meta_data('_used_gateway', get_class($this));
            if (!empty($data['payer']['email'])) {
                $order->update_meta_data(__('Payer email', 'woocommerce-mercadopago'), $data['payer']['email']);
            }
            if (!empty($data['payment_type_id'])) {
                $order->update_meta_data(__('Payment type', 'woocommerce-mercadopago'), $data['payment_type_id']);
            }
            $order->update_meta_data(
                'Mercado Pago - Payment ' . $data['id'],
                '[Date ' . date('Y-m-d H:i:s', strtotime($data['date_created'])) .
                ']/[Amount ' . $data['transaction_amount'] .
                ']/[Paid ' . $total_paid .
                ']/[Refund ' . $total_refund . ']'
            );
            $order->update_meta_data('_Mercado_Pago_Payment_IDs', $data['id']);
            $order->save();
        } else {
            // Updates the type of gateway.
            update_post_meta($order->id, '_used_gateway', get_class($this));
            if (!empty($data['payer']['email'])) {
                update_post_meta($order->id, __('Payer email', 'woocommerce-mercadopago'), $data['payer']['email']);
            }
            if (!empty($data['payment_type_id'])) {
                update_post_meta($order->id, __('Payment type', 'woocommerce-mercadopago'), $data['payment_type_id']);
            }
            update_post_meta(
                $order->id,
                'Mercado Pago - Payment ' . $data['id'],
                '[Date ' . date('Y-m-d H:i:s', strtotime($data['date_created'])) .
                ']/[Amount ' . $data['transaction_amount'] .
                ']/[Paid ' . $total_paid .
                ']/[Refund ' . $total_refund . ']'
            );
            update_post_meta($order->id, '_Mercado_Pago_Payment_IDs', $data['id']);
        }
        return $status;
    }
}