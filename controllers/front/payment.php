<?php

class latitude_officialpaymentModuleFrontController extends ModuleFrontController
{
    public $ssl = false;
    public $display_column_left = false;

    /**
     * @var string
     */
    protected $returnUrl = _PS_BASE_URL_ . '/module/latitude_official/return';

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
            // 'this_path_ssl' => Tools::getShopDomainSsl(true, true).__PS_BASE_URI__.'modules/'.$this->module->name.'/',
            'purchase_url' => $purchaseUrl,
            'payment_method' => Configuration::get(Latitude_Official::LATITUDE_FINANCE_TITLE),
            'payment_description' => Configuration::get(Latitude_Official::LATITUDE_FINANCE_DESCRIPTION),
            'currency_code' => $currency->iso_code,
            'currency_symbol' => $currency->sign,
            'splited_payment' => Tools::ps_round($cart->getOrderTotal() / 10, (int) $currency->decimals * _PS_PRICE_DISPLAY_PRECISION_),
            'payment_checkout_logo' => $this->getPaymentCheckoutLogo(),
            'current_module_uri' => $this->module->getPathUri()
        ));


        $this->setTemplate('payment_execution.tpl');
    }

    /**
     * [getPaymentCheckoutLogo description]
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
                break;
        }
        return _PS_BASE_URL_ . $this->module->getPathUri() . 'logos' . DIRECTORY_SEPARATOR . $logo;
    }

    // @todo: implment the actual logic
    public function getPurchaseUrl()
    {
        $serializeCartObject = serialize($this->context->cart);

        try {
            $cookie = $this->getCookie();
            $currency   = $this->context->currency;
            $paymentGatewayName = $this->module->getPaymentGatewayNameByCurrencyCode($currency->iso_code);
        //     $session    = $this->get_checkout_session();
            $gateway    = $this->module->getGateway($paymentGatewayName);
            $reference  = $this->getReferenceNumber();
            // Save the reference for validation when response coming back from
            $cookie->reference = $reference;

            $cart       = $this->context->cart;
            $amount     = $cart->getOrderTotal();
            $customer   = $this->context->customer;
            $address    = new Address($cart->id_address_delivery);

            $payment = array(
                BinaryPay_Variable::REFERENCE                => (string) $reference,
                BinaryPay_Variable::AMOUNT                   => $amount,
                BinaryPay_Variable::CURRENCY                 => $currency->iso_code ?: self::DEFAULT_VALUE,
                BinaryPay_Variable::RETURN_URL               => $this->returnUrl,
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

            // echo "<pre>";
            // var_dump($payment);
            // echo "</pre>";
            // die();

            $response = $gateway->purchase($payment);
            $purchaseUrl = $this->module->getConfigData('paymentUrl', $response);
        //     $purchaseUrl    = wc_latitudefinance_get_array_data('paymentUrl', $response);
        //     // Save token into the session
        //     $this->get_checkout_session()->set('purchase_token', wc_latitudefinance_get_array_data('token', $response));
        } catch (BinaryPay_Exception $e) {
            BinaryPay::log($e->getMessage(), true, 'latitude-finance.log');
            die($e->getMessage());
            // throw new Exception($e->getMessage());
        } catch (Exception $e) {
            die($e->getMessage());
        //     $message = $e->getMessage() ?: 'Something massively went wrong. Please try again. If the problem still exists, please contact us';
        //     BinaryPay::log($message, true, 'woocommerce-genoapay.log');
        //     throw new Exception($message);
        }
        return $purchaseUrl;
    }

    /**
     * @return string
     */
    protected function getReferenceNumber()
    {
        do {
            $reference = Order::generateReference();
        } while (Order::getByReference($reference)->count());
        return $reference;
    }

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
     * get_shipping_data
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
     * _getQuoteProducts
     * @return array
     */
    protected function getQuoteProducts()
    {
        $items = $this->context->cart->getProducts();
        $currencyCode = $this->context->currency->iso_code;
        // $isTaxIncluded = ($this->context->cart->getOrderTotal() == $this->context->cart->getOrderTotal(false)) ? 0 : 1;

        $products = [];
        foreach ($items as $_item) {
            $_item = (object) $_item;
            $product_line_item = [
                'name'          => $_item->name,
                'price' => [
                    'amount'    => $_item->total,
                    'currency'  => $currencyCode
                ],
                'sku'           => $_item->reference,
                'quantity'      => $_item->quantity,
                'taxIncluded'   => 0
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