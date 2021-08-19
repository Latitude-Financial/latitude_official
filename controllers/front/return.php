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

        if (Configuration::get(Latitude_Official::LATITUDE_FINANCE_DEBUG_MODE)) {
            $logMessage = "======CALLBACK INFO STARTS======\n";
            $logMessage .= json_encode(Tools::getAllValues(), JSON_PRETTY_PRINT);
            $logMessage .= "\n======CALLBACK INFO ENDS======";
            BinaryPay::log($logMessage, true, 'latitudepay-finance-' . date('Y-m-d') . '.log');
        }

        if (!$this->context->cookie->reference || $this->context->cookie->reference !== $reference) {
            Tools::redirect(Context::getContext()->shop->getBaseURL(true));
        }

        $cart = $this->context->cart;
        $response = Tools::getAllValues();

        // validate the request and place the order or return to shopping cart page
        // base on the response
        // Array
        // (
        //     [token] => ed354b1f-dad0-40e0-b70b-45792440cfc7
        //     [reference] => 100012
        //     [message] => The payment was approved.
        //     [result] => COMPLETED
        //     [signature] => d8045ef4061dca7dbe986d30df1fedd9a59fb90a5c3711cb898e8e4246f8b104
        //     [module] => latitude_official
        //     [controller] => return
        //     [fc] => module
        // )
        // print_r(Tools::getValue('result'));
        $responseState = Tools::getValue('result');
        // success
        if (in_array($responseState, self::PAYMENT_SUCCESS_STATES)) {
            $currencyCode = $this->context->currency->iso_code;
            $gatewayName = $this->module->getPaymentGatewayNameByCurrencyCode($currencyCode);
            $this->module->validateOrder(
                $cart->id,
                self::PAYMENT_ACCEPECTED,
                $cart->getOrderTotal(),
                $gatewayName,
                '',
                array(
                    'transaction_id' => Tools::getValue('token')
                )
            );
        } else {
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
}