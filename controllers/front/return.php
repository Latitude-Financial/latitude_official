<?php
class latitude_officialreturnModuleFrontController extends ModuleFrontController
{
    public $display_column_left = false;

    /**
     * @var integer - Order state
     */
    const PAYMENT_ACCEPECTED = 2;
    const PAYMENT_ERROR = 8;

    const PAYMENT_SUCCESS_STATES = [
        'COMPLETED'
    ];

    const PAYMENT_FAILED_STATES = [
        'UNKNOWN',
        'FAILED'
    ];

    /**
     * [initContent description]
     * @return [type]
     */
    public function initContent()
    {
        parent::initContent();
        // Add the validation
        $reference = Tools::getValue('reference');
        $currencyCode = $this->context->currency->iso_code;
        $gatewayName = $this->module->getPaymentGatewayNameByCurrencyCode($currencyCode);

        if (!$this->context->cookie->reference || $this->context->cookie->reference !== $reference) {
            Tools::redirect(Context::getContext()->shop->getBaseURL(true));
        }

        $cart = $this->context->cart;
        $response = Tools::getAllValues();
        $responseState = Tools::getValue('result');

        // Verify payment token
        $token = $this->context->cookie->__get('payment_token');
        $this->context->cookie->__unset('payment_token');

        if ($token !== Tools::getValue('token')) {
            $this->context->cookie->__set('latitude_finance_redirect_error', $this->translateErrorMessage("Invalid payment token."));
            Tools::redirect('index.php?controller=order&step=1');
        }

        // Verify signature
        if (!$this->validateSignature($gatewayName)) {
            $this->context->cookie->__set('latitude_finance_redirect_error', $this->translateErrorMessage("Invalid response signature."));
            Tools::redirect('index.php?controller=order&step=1');
        }

        // success
        if (in_array($responseState, self::PAYMENT_SUCCESS_STATES)) {
            $verifyCartAmount = floatval($this->context->cookie->__get('cart_amount'));
            if (!$verifyCartAmount) {
                $this->context->cookie->__set('latitude_finance_redirect_error', $this->translateErrorMessage("Your order was placed already."));
                Tools::redirect('index.php?controller=order&step=1');
            }
            $this->context->cookie->__unset('cart_amount');
            $orderAmount = $cart->getOrderTotal();
            $this->module->validateOrder(
                $cart->id,
                $verifyCartAmount< $orderAmount ? self::PAYMENT_ERROR : self::PAYMENT_ACCEPECTED,
                $verifyCartAmount,
                $gatewayName,
                $verifyCartAmount < $orderAmount ?
                    sprintf(
                        'Invalid payment amount detected! Correct amount: %s, paid: %s, token: %s',
                        $verifyCartAmount,
                        $orderAmount,
                        $token
                    ) : '',
                [ 'transaction_id' => Tools::getValue('token') ]
            );
        } else {
            $message = (is_array($response)) ? json_encode($response) : 'Error response from Latitude Financial services API. The response data cannot be recorded.';
            // record all the FAILED status order
            // just in case we lose the response messages and transaction token ID
            BinaryPay::log($message, true, 'prestashop-latitude-finance.log');
            $this->context->cookie->latitude_finance_redirect_error = $this->translateErrorMessage(Tools::getValue('message'));
            Tools::redirect('index.php?controller=order&step=1');
        }

        $customer = new Customer($cart->id_customer);

        if (!Validate::isLoadedObject($customer))
            Tools::redirect('index.php?controller=order&step=1');

        Tools::redirect('index.php?controller=order-confirmation&id_cart='. (int)$cart->id. '&id_module=' . (int)$this->module->id . '&id_order=' . $this->module->currentOrder. '&key=' . $customer->secure_key);
    }

    /**
     * translateErrorMessage
     * @param  string $message
     * @return string
     */
    protected function translateErrorMessage($message)
    {
        switch ($message) {
            case 'The customer cancelled the purchase.':
                $message =  'Your purchase order has been cancelled.';
                break;
            default:
                // do nothing
                break;
        }
        return $message;
    }

    /**
     * Check if response signature is valid
     * @param $gatewayName
     * @param $request
     * @return bool
     */
    private function validateSignature($gatewayName)
    {
        /**
         * @var BinaryPay $gateway
         */
        $gateway = $this->module->getGateway($gatewayName);
        $gluedString = $gateway->recursiveImplode(
            array(
                'token' => Tools::getValue('token'),
                'reference' => $this->context->cookie->reference,
                'message' => Tools::getValue('message'),
                'result' => Tools::getValue('result'),
            ),
            '',
            true
        );
        $signature = hash_hmac( 'sha256', base64_encode( $gluedString ), $gateway->getConfig( 'password' ) );
        return $signature === Tools::getValue('signature');
    }
}
