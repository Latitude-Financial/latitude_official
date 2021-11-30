<?php

class latitude_officialpaymentModuleFrontController extends ModuleFrontController
{
    public $ssl = false;
    public $display_column_left = false;

    /**
     * @var string
     */
    protected $returnUrl = '/module/latitude_official/return';

    /**
     * @var boolean
     */
    protected $debug = true;

    /**
     * @var string
     */
    const DEFAULT_VALUE = 'NO_VALUE';

    /**
     * @see FrontController::initContent()
     */
    public function initContent()
    {
        parent::initContent();

        $errors = [];
        $purchaseUrl = '';
        $cart = $this->context->cart;
        $currency = $this->context->currency;

        /**
         * @todo: support the backend currency and country registration
         */
        // if (!$this->module->checkCurrency($cart))
        //     Tools::redirect('index.php?controller=order');

        try {
            $purchaseUrl = $this->getPurchaseUrl();
        } catch (Exception $e) {
            $errors[] = Tools::displayError($e->getMessage());
        }

        $this->context->smarty->assign(array(
            'errors' => $errors,
            'nbProducts' => $cart->nbProducts(),
            'cust_currency' => $cart->id_currency,
            'currencies' => $this->module->getCurrency((int)$cart->id_currency),
            'total' => $cart->getOrderTotal(true, Cart::BOTH),
            'isoCode' => $this->context->language->iso_code,
            'this_path_ssl' => Tools::getShopDomainSsl(true, true).__PS_BASE_URI__.'modules/'.$this->module->name.'/',
            'purchase_url' => $purchaseUrl,
            'payment_method' => Configuration::get(Latitude_Official::LATITUDE_FINANCE_TITLE),
            'payment_description' => Configuration::get(Latitude_Official::LATITUDE_FINANCE_DESCRIPTION),
            'currency_code' => $currency->iso_code,
            'currency_symbol' => $currency->sign,
            'splited_payment' => Tools::ps_round($cart->getOrderTotal() / 10, (int) $currency->decimals * _PS_PRICE_DISPLAY_PRECISION_),
            'payment_checkout_logo' => $this->getPaymentCheckoutLogo(),
            'current_module_uri' => $this->module->getPathUri(),
            'payment_gateway_name' => $this->module->getPaymentGatewayNameByCurrencyCode($currency->iso_code),
            'branding_color' => ($currency->iso_code == "AUD") ? "rgb(57, 112, 255)" : "rgb(49, 181, 156)",
            'doc_link' => ($currency->iso_code == "AUD") ? 'https://www.latitudepay.com/how-it-works/' : 'https://www.genoapay.com/how-it-works/',
            'amount' => $cart->getOrderTotal(),
            'images_api_url' => Tools::getValue(Latitude_Official::LATITUDE_FINANCE_IMAGES_API_URL, Latitude_Official::DEFAULT_IMAGES_API_URL),
            'full_block' => true
        ));


        $this->setTemplate('payment_execution.tpl');
    }

    /**
     * get the payment checkout logo by the current currency
     * @return string
     */
    protected function getPaymentCheckoutLogo()
    {
        $logo = '';
        $currencyCode = $this->context->currency->iso_code;
        switch ($currencyCode) {
            case 'AUD':
                $logo = 'latitudepay_checkout.svg';
                break;
            case 'NZD':
                $logo = 'genoapay_checkout.svg';
                break;
            default:
                throw new Exception('Unsupported currency code. Please change your currency code to AUD or NZD.');
        }
        return (Configuration::get('PS_SSL_ENABLED') ? _PS_BASE_URL_SSL_ : _PS_BASE_URL_) . $this->module->getPathUri() . 'logos' . DIRECTORY_SEPARATOR . $logo;
    }

    /**
     * Request the purchase URL by accessing the Latitude Finance API
     * @see https://api.uat.latitudepay.com/v3/api-doc/index.html#operation/createEcommerceSale
     * @return string
     */
    public function getPurchaseUrl()
    {
        /**
         * Give a default value of the purchase URL
         * @fix Notice: Undefined variable: purchaseUrl in /var/www/html/modules/latitude_official/controllers/front/payment.php on line 163 when an exception throws
         * @var string
         */
        $purchaseUrl = '';

        try {
            $cookie = $this->getCookie();
            $currency   = $this->context->currency;
            $paymentGatewayName = $this->module->getPaymentGatewayNameByCurrencyCode($currency->iso_code);
            $gateway    = $this->module->getGateway($paymentGatewayName);
            if (!$gateway) {
                $this->errors[] = "Could not get the payment URL from payment gateway";
                return false;
            }
            $reference  = $this->getReferenceNumber();
            // Save the reference for validation when response coming back from
            $cookie->reference = $reference;

            $cart       = $this->context->cart;
            $amount     = $cart->getOrderTotal();
            $customer   = $this->context->customer;
            $address    = new Address($cart->id_address_delivery);
            $cookie->cart_amount = $amount;

            $payment = array(
                BinaryPay_Variable::REFERENCE                => (string) $reference,
                BinaryPay_Variable::AMOUNT                   => $amount,
                BinaryPay_Variable::CURRENCY                 => $currency->iso_code ?: self::DEFAULT_VALUE,
                BinaryPay_Variable::RETURN_URL               => $this->getReturnUrl(),
                BinaryPay_Variable::MOBILENUMBER             => $address->phone_mobile ?: '0210123456',
                BinaryPay_Variable::EMAIL                    => $customer->email,
                BinaryPay_Variable::FIRSTNAME                => $customer->firstname ?: self::DEFAULT_VALUE,
                BinaryPay_Variable::SURNAME                  => $customer->lastname ?: self::DEFAULT_VALUE,
                BinaryPay_Variable::SHIPPING_ADDRESS         => $this->getFullAddress() ?: self::DEFAULT_VALUE,
                BinaryPay_Variable::SHIPPING_COUNTRY_CODE    => $address->country ?: self::DEFAULT_VALUE,
                BinaryPay_Variable::SHIPPING_POSTCODE        => $address->postcode ?: self::DEFAULT_VALUE,
                BinaryPay_Variable::SHIPPING_SUBURB          => $address->city ?: self::DEFAULT_VALUE,
                BinaryPay_Variable::SHIPPING_CITY            => $address->city ?: self::DEFAULT_VALUE,
                BinaryPay_Variable::BILLING_ADDRESS          => $this->getFullAddress() ?: self::DEFAULT_VALUE,
                BinaryPay_Variable::BILLING_COUNTRY_CODE     => $address->country ?: self::DEFAULT_VALUE,
                BinaryPay_Variable::BILLING_POSTCODE         => $address->postcode ?: self::DEFAULT_VALUE,
                BinaryPay_Variable::BILLING_SUBURB           => $address->city ?: self::DEFAULT_VALUE,
                BinaryPay_Variable::BILLING_CITY             => $address->city ?: self::DEFAULT_VALUE,
                BinaryPay_Variable::TAX_AMOUNT               => $cart->getOrderTotal() - $cart->getOrderTotal(false),
                BinaryPay_Variable::PRODUCTS                 => $this->getQuoteProducts(),
                BinaryPay_Variable::SHIPPING_LINES           => [
                    $this->getShippingData()
                ]
            );

            if ($this->debug) {
                PrestaShopLogger::addLog('latitude_officialpaymentModuleFrontController::getPurchaseUrl - Function called.' . json_encode($payment), 1, null, 'PaymentModule', (int)$cart->id, true);
            }

            $response = $gateway->purchase($payment);
            $cookie->payment_token = $response['token'] ?? null;
            $purchaseUrl = $this->module->getConfigData('paymentUrl', $response);
        } catch (BinaryPay_Exception $e) {
            BinaryPay::log($e->getMessage(), true, 'prestashop-latitude-finance.log');
            PrestaShopLogger::addLog($e->getMessage(), 1, null, 'PaymentModule', (int)$cart->id, true);
            $this->errors[] = $e->getMessage();
        } catch (Exception $e) {
            $message = $e->getMessage() ?: 'Something massively went wrong. Please try again. If the problem still exists, please contact us';
            PrestaShopLogger::addLog($message, 1, null, 'PaymentModule', (int)$cart->id, true);
            BinaryPay::log($message, true, 'prestashop-latitude-finance.log');
            $this->errors[] = $message;
        }
        return $purchaseUrl;
    }

    protected function getReturnUrl()
    {
        return $this->context->link->getModuleLink('latitude_official', 'return', array(), Configuration::get('PS_SSL_ENABLED'));
    }

    /**
     * This is how prestashop generate the next order referece number
     * @return string
     */
    protected function getReferenceNumber()
    {
        do {
            $reference = Order::generateReference();
        } while (Order::getByReference($reference)->count());
        return $reference;
    }

    /**
     * Since the address have two address lines so this function is made to merge them as a single string
     * @return string
     */
    protected function getFullAddress()
    {
        $addressObject    = new Address($this->context->cart->id_address_delivery);
        $address = $addressObject->address1;
        $address2 = $addressObject->address2;

        if ($address2) {
            $address .= ', ' . $address2;
        }

        return $address;
    }

    /**
     * Build the shipping line array structure base on latitude finance API documentation
     * @see  https://api.uat.latitudepay.com/v3/api-doc/index.html#operation/createEcommerceSale
     * @return array
     */
    protected function getShippingData()
    {
        // handling fee + shipping fee
        $currencyCode = $this->context->currency->iso_code;
        $id_lang = Configuration::get('PS_LANG_DEFAULT');
        $carrier = new Carrier($this->context->cart->id_carrier, $id_lang);
        // Tax rule group 0 is the "No Tax" group
        $taxIncluded = ($carrier->id_tax_rules_group === 0) ? 0 : 1;

        $shippingDetail = [
            'carrier' => $carrier->name,
            'price' => [
                'amount' => $this->context->cart->getTotalShippingCost(),
                'currency' => $currencyCode
            ],
            'taxIncluded' => $taxIncluded
        ];
        return $shippingDetail;
    }

    /**
     * Build the products array structure base on latitude finance API documentation
     * @see  https://api.uat.latitudepay.com/v3/api-doc/index.html#operation/createEcommerceSale
     * @return array
     */
    protected function getQuoteProducts()
    {
        $items = $this->context->cart->getProducts();
        $currencyCode = $this->context->currency->iso_code;
        $isTaxIncluded = ($this->context->cart->getOrderTotal() == $this->context->cart->getOrderTotal(false)) ? 0 : 1;

        $products = [];
        foreach ($items as $_item) {
            $_item = (object) $_item;
            $product_line_item = [
                'name'          => htmlspecialchars($_item->name),
                'price' => [
                    'amount'    => round($_item->total_wt, 2),
                    'currency'  => $currencyCode
                ],
                'sku'           => $_item->reference,
                'quantity'      => $_item->quantity,
                'taxIncluded'   => $isTaxIncluded
            ];
            array_push($products, $product_line_item);
        }

        return $products;
    }

    protected function getCookie()
    {
        return $this->context->cookie;
    }
}
