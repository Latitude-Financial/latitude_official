<?php
/**
 * This checks for the existence of an always-existing PrestaShop constant (its version number),
 * and if it does not exist, it stops the module from loading.
 * The sole purpose of this is to prevent malicious visitors to load this file directly.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once(__DIR__ . '/includes/autoload.php');
require_once (__DIR__.'/helpers/OrderHelper.php');
require_once (__DIR__.'/models/LatitudeRefundTransaction.php');

class Latitude_Official extends PaymentModule
{
    /**
     * @var string
     */
    const GENOAPAY_PAYMENT_METHOD_CODE = 'Genoapay';

    /**
     * @var string
     */
    const LATITUDE_PAYMENT_METHOD_CODE = 'Latitudepay';

    /**
     * @var array
     */
    const ALLOWED_PAYMENT_GATEWAYS= [self::GENOAPAY_PAYMENT_METHOD_CODE, self::LATITUDE_PAYMENT_METHOD_CODE];

    /**
     * @var string
     */
    const ENVIRONMENT_DEVELOPMENT = 'development';

    /**
     * @var string
     */
    const ENVIRONMENT_SANDBOX = 'sandbox';

    /**
     * @var string
     */
    const ENVIRONMENT_PRODUCTION = 'production';

    /**
     * @var string
     */
    const ENVIRONMENT = 'LATITUDE_FINANCE_ENVIRONMENT';

    /**
     * @var string - The data would be fetch from the API
     */
    const LATITUDE_FINANCE_TITLE = 'LATITUDE_FINANCE_TITLE';

    /**
     * @var string
     */
    const LATITUDE_FINANCE_DESCRIPTION = 'LATITUDE_FINANCE_DESCRIPTION';

    /**
     * @var string
     */
    const LATITUDE_FINANCE_MIN_ORDER_TOTAL = 'LATITUDE_FINANCE_MIN_ORDER_TOTAL';

    /**
     * @var string
     */
    const LATITUDE_FINANCE_MAX_ORDER_TOTAL = 'LATITUDE_FINANCE_MAX_ORDER_TOTAL';

    /**
     * @var boolean
     */
    const LATITUDE_FINANCE_DEBUG_MODE = 'LATITUDE_FINANCE_DEBUG_MODE';

    /**
     * @var string
     */
    const LATITUDE_FINANCE_PUBLIC_KEY = 'LATITUDE_FINANCE_PUBLIC_KEY';

    /**
     * @var string
     */
    const LATITUDE_FINANCE_PRIVATE_KEY = 'LATITUDE_FINANCE_PRIVATE_KEY';

    /**
     * @var string
     */
    const LATITUDE_FINANCE_SANDBOX_PUBLIC_KEY = 'LATITUDE_FINANCE_SANDBOX_PUBLIC_KEY';

    /**
     * @var string
     */
    const LATITUDE_FINANCE_SANDBOX_PRIVATE_KEY = 'LATITUDE_FINANCE_SANDBOX_PRIVATE_KEY';

    /**
     * @var string
     */
    const LATITUDE_FINANCE_IMAGES_API_URL = 'LATITUDE_FINANCE_IMAGES_API_URL';

    /**
     * @var string
     */
    const DEFAULT_IMAGES_API_URL = 'https://images.latitudepayapps.com/';

    /**
     * @var string
     */
    protected $_html = '';

    /**
     * @var string
     */
    public $gatewayName = '';

    /**
     * @var object
     */
    public $gateway = '';

    /**
    * List of hooks needed in this module
    * @var array
    */
    public $hooks = array(
        'header',
        'backOfficeHeader',
        'payment',
        'displayProductButtons',
        'displayPaymentReturn',
        'displayTop',
        'displayAdminOrderContentOrder',
        'actionOrderSlipAdd'
    );

    /**
     * Latitude_Official constructor.
     */
    public function __construct()
    {
        /**
         * The value MUST be the name of the module's folder.
         * @var string
         */
        $this->name = 'latitude_official';

        /**
         * The title for the section that shall contain this module in PrestaShop's back office modules list.
         * payments_gateways => Payments & Gateways
         * @var string
         */
        $this->tab = 'payments_gateways';

        $this->version = '1.2';
        $this->author = 'Latitude Financial Services';

        /**
         * Indicates whether to load the module's class when displaying the "Modules" page in the back office.
         * If set at 0, the module will not be loaded, and therefore will spend less resources to generate the "Modules" page.
         * If your module needs to display a warning message in the "Modules" page, then you must set this attribute to 1.
         * @var integer
         */
        $this->need_instance = 0;

        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => '1.6.99.99');

        /**
         * Indicates that the module's template files have been built with PrestaShop 1.6's bootstrap tools in mind
         * PrestaShop should not try to wrap the template code for the configuration screen
         * (if there is one) with helper tags.
         * @var boolean
         */
        $this->bootstrap = true;

        $this->currencies = true;
        $this->currencies_mode = 'checkbox';

        $this->configuration = [];

        // Calling the parent constuctor method must be done after the creation of the $this->name variable and before any use of the $this->l() translation method.
        parent::__construct();
        // If the module is not enabled or installed then do not initialize
        if (!Module::isInstalled('latitude_official') || !Module::isEnabled('latitude_official')) {
            return;
        }

        if (!count(Currency::checkPaymentCurrencies($this->id))) {
            $this->warning = $this->l('No currency has been set for this module.');
        }
        if (Tools::getValue(self::ENVIRONMENT, Configuration::get(self::ENVIRONMENT))) {
            $this->_setPaymentGateway();
        }

        $this->displayName = $this->l('Latitude Finance Payment Module');
        $this->description = $this->l('Available to NZ and OZ residents who are 18 years old and over and have a valid debit or credit card.');
        $this->confirmUninstall = $this->l('Are you sure you to uninstall the module?');

        /**
         * Check cURL extension
         */
        if (is_callable('curl_init') === false) {
            $this->errors[] = $this->l('To be able to use this module, please activate cURL (PHP extension).');
        }
    }

    /**
     * Install this module and register the following Hooks:
     *
     * @return bool
     */
    public function install()
    {
        if (!parent::install() || !$this->registerHooks() || !$this->updateDatabase()) {
            return false;
        }

        return true;
    }

    /**
     * Register a list of hooks
     * @return bool
     */
    protected function registerHooks()
    {
        foreach ($this->hooks as $hook) {
            if (!$this->registerHook($hook)) {
                return false;
            }
        }
        return true;
    }

    /**
     * For displaying error after the redirection from Latitude Finance
     */
    public function hookDisplayTop($params)
    {
        $errorMessage = $params['cookie']->latitude_finance_redirect_error;

        // No Error found, do nothing
        if (!$errorMessage) {
            return;
        }

        $this->context->smarty->assign(array(
            'error_message' => $errorMessage
        ));

        // remove the old error message
        $this->context->cookie->__unset('latitude_finance_redirect_error');

        return $this->display(__FILE__, 'errors_alert.tpl');
    }

    /**
     * Uninstall this module and reset all the existing configuration data
     *
     * @return bool
     */
    public function uninstall()
    {
        $result = parent::uninstall();
        return $result
            && Configuration::deleteByName(self::LATITUDE_FINANCE_SANDBOX_PUBLIC_KEY)
            && Configuration::deleteByName(self::LATITUDE_FINANCE_SANDBOX_PRIVATE_KEY)
            && Configuration::deleteByName(self::LATITUDE_FINANCE_PUBLIC_KEY)
            && Configuration::deleteByName(self::LATITUDE_FINANCE_PRIVATE_KEY)
            && Configuration::deleteByName(self::LATITUDE_FINANCE_TITLE)
            && Configuration::deleteByName(self::LATITUDE_FINANCE_DESCRIPTION)
            && Configuration::deleteByName(self::LATITUDE_FINANCE_MIN_ORDER_TOTAL)
            && Configuration::deleteByName(self::LATITUDE_FINANCE_MAX_ORDER_TOTAL)
            && Configuration::deleteByName(self::ENVIRONMENT)
            && Configuration::deleteByName(self::LATITUDE_FINANCE_DEBUG_MODE)
            && Configuration::deleteByName(self::LATITUDE_FINANCE_IMAGES_API_URL);
    }

    /**
     * Append stylesheet files into template header
     * @param $params
     */
    public function hookHeader($params)
    {
         $this->context->controller->addCSS($this->_path . '/views/css/genoapay.css');
         $this->context->controller->addCSS($this->_path . '/views/css/latitudepay.css');
    }

    /**
     * Add refund script to backend template
     * @return null
     */
    public function hookBackOfficeHeader()
    {
        if (Tools::getValue('controller') == "AdminOrders" && Tools::getValue('id_order')) {
            $order = new Order((int) Tools::getValue('id_order'));
            $paymentGatewayName = $this->_getGatewayNameByPaymentMethod($order);
            $customRefund = $this->isCustomRefundNeeded(Tools::getValue('id_order'), $paymentGatewayName);
            if ($customRefund) {
                Media::addJsDefL('latitude_refund_js', $this->l('Refund '.$paymentGatewayName));
                $this->context->controller->addJS(_PS_MODULE_DIR_ . $this->name . '/views/js/latitude_order.js');
            }
        }

        return null;
    }

    /**
     * Display order return block
     * @param $params
     * @return mixed
     */
    public function hookDisplayPaymentReturn($params)
    {
        $this->context->smarty->assign(array(
            'currency_code' => Context::getContext()->currency->iso_code,
            'order_total_amount' => round($params['total_to_pay'], 2),
            'payment_method' => $params['objOrder']->payment,
            'email' => $params['cookie']->email,
            'invoice_date' => $params['objOrder']->invoice_date,
            'order_id' => Order::getUniqReferenceOf(Order::getOrderByCartId($params['objOrder']->id_cart))
        ));

        return $this->display(__FILE__, 'payment_return.tpl');
    }

    /**
     * Display refund block inside order detail page
     * @param $params
     * @return mixed
     */
    public function hookDisplayAdminOrderContentOrder($params)
    {
        $id_order = Tools::getValue('id_order');
        $order = new Order((int) $id_order);
        $paymentGatewayName = $this->_getGatewayNameByPaymentMethod($order);
        $customRefund = $this->isCustomRefundNeeded($id_order, $paymentGatewayName);

        $data = array(
            '_id' => $params['cookie']->id_employee,
            '_secert' => $params['cookie']->passwd,
            'order_id' => $id_order
        );

        $url = $this->context->link->getModuleLink($this->name, 'refund', array(), Configuration::get('PS_SSL_ENABLED'));
        $this->context->smarty->assign(array(
            'paymenet_gateway_name' => $paymentGatewayName,
            'refund_url' => $url,
            'custom_refund' => $customRefund,
            'query_data' => http_build_query($data),
            'available_amount' => $this->getAvailableRefundAmount($id_order),
            'total_paid' => $order->getTotalPaid()
        ));
        return $this->display(__FILE__, 'admin-refund.tpl');
    }

    /**
     * Check if partial refund for current method is selected
     * @param $params
     * @throws BinaryPay_Exception
     */
    public function hookActionOrderSlipAdd($params)
    {
        if (Tools::isSubmit('doPartialRefundLatitude')) {
            $order = $params['order'];
            $amount = floatval(Tools::getValue('partialRefundShippingCost', ''));
            foreach ($params['productList'] as $product) {
                $amount += $product['amount'];
            }

            $this->_makeRefund($order, $amount);
        }
    }

    /**
     * Check if refund option is needed
     * @param $id_order
     * @param $paymentGatewayName
     * @return bool
     */
    public function isCustomRefundNeeded($id_order, $paymentGatewayName)
    {
        /** @var OrderCore $order */
        $order = new Order($id_order);
        $order_payment = null;
        $payments = OrderPayment::getByOrderId($id_order);

        if (count($payments) > 0) {
            $order_payment = $payments[0];
        }

        if (!$order_payment || $order->getCurrentState() === _PS_OS_REFUND_) {
            return false;
        }

        $refundedAmount = OrderHelper::getTotalRefundedAmount($id_order);
        if ($refundedAmount === $order->getTotalPaid()) {
            return false;
        }

        return $order_payment->payment_method === $paymentGatewayName;
    }

    /**
     * Check if the Latitude Finance API is avaliable for the web server
     * @param  string $publicKey
     * @param  string $privateKey
     * @return boolean
     */
    public function checkApiConnection($publicKey = null, $privateKey = null)
    {
        try {
            $configuration = $this->getConfiguration();
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            return false;
        }

        if (empty($configuration)) {
            return false;
        }

        return true;
    }

    /**
     * Fetch the configuration from the Latitude Finance API
     * @see  https://api.uat.latitudepay.com/v3/api-doc/index.html#operation/getConfiguration
     * @return array
     * @throws Exception
     */
    public function getConfiguration()
    {
        // initialize payment gateway
        $this->gateway = $gateway = $this->getGateway();

        if (!$gateway) {
            throw new Exception('The payment gateway cannot been initialized.');
        }

        if (empty($this->configuration)) {
            $this->configuration = $gateway->configuration();
        }

        return $this->configuration;
    }

    /**
     * retrieve PostPassword from database
     *
     * @param int $storeId
     *
     * @return array|bool
     */
    public function getCredentials()
    {
        $environment = Tools::getValue(self::ENVIRONMENT, Configuration::get(self::ENVIRONMENT));
        switch ($environment) {
            case self::ENVIRONMENT_SANDBOX:
            case self::ENVIRONMENT_DEVELOPMENT:
                /**
                 * retrieve the correct configuration base on the current public and private key pair
                 */
                $publicKey = Tools::getValue(self::LATITUDE_FINANCE_SANDBOX_PUBLIC_KEY, Configuration::get(self::LATITUDE_FINANCE_SANDBOX_PUBLIC_KEY));
                $privateKey = Tools::getValue(self::LATITUDE_FINANCE_SANDBOX_PRIVATE_KEY, Configuration::get(self::LATITUDE_FINANCE_SANDBOX_PRIVATE_KEY));
                break;
            case self::ENVIRONMENT_PRODUCTION:
                $publicKey = Tools::getValue(self::LATITUDE_FINANCE_PUBLIC_KEY, Configuration::get(self::LATITUDE_FINANCE_PUBLIC_KEY));
                $privateKey = Tools::getValue(self::LATITUDE_FINANCE_PRIVATE_KEY, Configuration::get(self::LATITUDE_FINANCE_PRIVATE_KEY));
                break;
            default:
                $this->warning = 'Failed to get credentials because the environment value is not correct.';
                return false;
        }

        return array(
            'username'      => $publicKey,
            'password'      => $privateKey,
            'environment'   => $environment,
            'accountId'     => ''
        );
    }

    private function isDebugEnabled() {
        return Tools::getValue(self::LATITUDE_FINANCE_DEBUG_MODE, Configuration::get(self::LATITUDE_FINANCE_DEBUG_MODE));
    }

    /**
     * Get payment gateway name base on currency code
     * @param null $currencyCode
     * @return string
     * @throws Exception
     */
    public function getPaymentGatewayNameByCurrencyCode($currencyCode = null)
    {
        $countryToCurrencyCode = [
            'NZ' => 'NZD',
            'AU' => 'AUD',
        ];

        /**
         * If the currency object still not initialized then use the country object as the default setting
         */
        if (!$currencyCode) {
            $countryCode = $this->context->country->iso_code;
            if (!isset($countryToCurrencyCode[$countryCode])) {
                throw new Expcetion(sprintf("The country code: %s cannot to map with a supported currency code.", $countryCode));
            }

            $currencyCode = $countryToCurrencyCode[$countryCode];
        }

        $this->gatewayName = $gatewayName = '';
        switch ($currencyCode) {
            case 'AUD':
                $this->gatewayName = $gatewayName = self::LATITUDE_PAYMENT_METHOD_CODE;
                break;
            case 'NZD':
                $this->gatewayName = $gatewayName = self::GENOAPAY_PAYMENT_METHOD_CODE;
                break;
            default:
                throw new Exception("The extension does not support for the current currency for the shop.");
        }

        return $gatewayName;
    }

    /**
     * Get gateway instance
     * @param null $gatewayName
     * @return BinaryPay|false|mixed|object|string|void
     */
    public function getGateway($gatewayName = null)
    {
        $message = '';

        if ($this->gateway instanceof BinaryPay && null === $gatewayName) {
            return $this->gateway;
        }

        if (!$gatewayName) {
            $gatewayName = $this->gatewayName;
        }

        try {
            $className = (isset(explode('_', $gatewayName)[1])) ? ucfirst(explode('_', $gatewayName)[1]) : ucfirst($gatewayName);
            // @todo: validate credentials coming back from the account
            if ($className && $credentials = $this->getCredentials()) {
                $this->gateway = BinaryPay::getGateway($className, $credentials);
            }
        } catch (BinaryPay_Exception $e) {
            $message = $e->getMessage();
            $this->errors[] =  $this->l($className .': '. $message);
        } catch (Exception $e) {
            $message = $e->getMessage();
            $this->errors[] = $this->l($className . ': ' . $message);
        }

        if (!$this->gateway) {
            $messagePrefix = "Message: ";
            $message = $message && $message !== $messagePrefix ? $message : 'The gateway object did not initialized correctly.';
            BinaryPay::log($message, false, 'latitudepay-finance-' . date('Y-m-d') . '.log');
            return false;
        }

        if (Configuration::get(self::LATITUDE_FINANCE_DEBUG_MODE)) {
            $this->gateway->setConfig(['debug' => true]);
        }

        return $this->gateway;
    }

    /**
     * Triggered when user enter the payment step in frontend
     */
    public function hookPayment($params)
    {
        $cartAmount = $params['cart']->getOrderTotal();
        $currency = new Currency($params['cart']->id_currency);
        $this->gatewayName = $this->getPaymentGatewayNameByCurrencyCode($currency->iso_code);

        if (!$this->active || !$this->isOrderAmountAvailable($cartAmount)) {
            return;
        }

        if (!$this->checkApiConnection()) {
            $this->context->smarty->assign(array(
                'latitudeError' => $this->l(
                    'No credentials have been provided for Latitude Finance. Please contact the owner of the website.',
                    $this->name
                )
            ));
        }

        /**
         * @todo: support the backend currency and country registration
         */
        // if (!$this->checkCurrency($params['cart'])) {
        //     return;
        // }

        $this->smarty->assign(array(
            'this_path' => $this->_path,
            'logo' => $this->getPaymentLogo(),
            'payment_name' => $this->gatewayName,
            'splited_payment' => $currency->sign . Tools::ps_round($cartAmount / 10, (int) $currency->decimals * _PS_PRICE_DISPLAY_PRECISION_),
            'this_path_ssl' => Tools::getShopDomain(true, true) . __PS_BASE_URI__ . 'modules/'.$this->name.'/',
        ));

        return $this->display(__FILE__, 'payment.tpl');
    }

    /**
     * Get payment method logo URL
     * @return string
     * @throws Exception
     */
    protected function getPaymentLogo()
    {
        switch ($this->gatewayName) {
            case self::GENOAPAY_PAYMENT_METHOD_CODE:
                $paymentLogo =  (Configuration::get('PS_SSL_ENABLED') ? _PS_BASE_URL_SSL_ : _PS_BASE_URL_) . $this->_path . 'logos/genoapay.svg';
                break;
            case self::LATITUDE_PAYMENT_METHOD_CODE:
                $paymentLogo =  (Configuration::get('PS_SSL_ENABLED') ? _PS_BASE_URL_SSL_ : _PS_BASE_URL_) . $this->_path . 'logos/latitudepay.svg';
                break;
            default:
                throw new Exception("Failed to get the payment logo from the current gateway name.");
        }

        return $paymentLogo;
    }

    /**
     * Display payment snippet inside product detail page
     * @param $params
     * @return mixed
     * @throws Exception
     */
    public function hookDisplayProductButtons($params)
    {
        $currency = $this->context->currency;
        $price = Tools::ps_round($params['product']->getPrice(), (int) $currency->decimals * _PS_PRICE_DISPLAY_PRECISION_);
        $currencyCode = $this->context->currency->iso_code;
        $gatewayName = $this->getPaymentGatewayNameByCurrencyCode($currencyCode);

        $containerClass = "wc-latitudefinance-" . strtolower($gatewayName) . "-container";
        $paymentInfo = $this->l("Available now.");

        if ($price >= 20 && $price <= 1500) {
           $weekly = round($price / 10, 2);
           $paymentInfo = "10 weekly payments of <strong>$${weekly}</strong>";
        }

        $color = ($gatewayName == "Latitudepay") ? "rgb(57, 112, 255)" : "rgb(49, 181, 156)";

        $this->smarty->assign(array(
            'container_class' => $containerClass,
            'color' => $color,
            'gateway_name' => strtolower($gatewayName),
            'payment_info' => $paymentInfo,
            'currency_code' => $currencyCode,
            'payment_logo' => $this->getPaymentLogo(),
            'base_dir' => Configuration::get('PS_SSL_ENABLED') ? _PS_BASE_URL_SSL_ : _PS_BASE_URL_ . __PS_BASE_URI__,
            'current_module_uri' => $this->_path,
            'images_api_url' => Tools::getValue(self::LATITUDE_FINANCE_IMAGES_API_URL, self::DEFAULT_IMAGES_API_URL),
            'full_block' => false,
            'amount' => $price
        ));

        return $this->display(__FILE__, 'product_latitude_finance.tpl');
    }

    /**
     * Check if order currency is matched with cart currency
     * @param $cart
     * @return bool
     */
    public function checkCurrency($cart)
    {
        $currency_order = new Currency($cart->id_currency);
        $currencies_module = $this->getCurrency($cart->id_currency);

        if (is_array($currencies_module)) {
            foreach ($currencies_module as $currency_module) {
                if ($currency_order->id == $currency_module['id_currency']) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Returns a string containing the HTML necessary to
     * generate a configuration screen on the admin
     *
     * @return string
     */
    public function getContent()
    {
        /* Check if SSL is enabled */
        if (!Configuration::get('PS_SSL_ENABLED')) {
            $this->warning = $this->l(
                'You must enable SSL on the store if you want to use this module in production.',
                $this->name
            );
        }

        $output = '';
        $output .= $this->postProcess();
        $output .= $this->renderSettingsForm();
        return $output;
    }

    /**
     * Render backend configuration form
     * @return mixed
     */
    public function renderSettingsForm()
    {
         $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Settings'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->l('Production API Key'),
                        'desc'  => $this->l('The Public Key for your Genoapay or Latitudepay account.'),
                        'name' => self::LATITUDE_FINANCE_PUBLIC_KEY,
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Production API Secret'),
                        'desc'  => $this->l('The Private Key for your Genoapay or Latitudepay account.'),
                        'name' => self::LATITUDE_FINANCE_PRIVATE_KEY,
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Sandbox API Key'),
                        'desc'  => $this->l('The Public Key for your Genoapay or Latitudepay sandbox account.'),
                        'name' => self::LATITUDE_FINANCE_SANDBOX_PUBLIC_KEY,
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Sandbox API Secret'),
                        'desc'  => $this->l('The Private Key for your Genoapay or Latitudepay sandbox account.'),
                        'name' => self::LATITUDE_FINANCE_SANDBOX_PRIVATE_KEY,
                    ),
                    array(
                        'type' => 'select',
                        'label' => $this->l('Environment'),
                        'name' => self::ENVIRONMENT,
                        'col' => 4,
                        'desc' => $this->l('Sandbox and development are for testing purpose only.'),
                        'options' => array(
                            'query' => $this->getEnvironments(),
                            'id' => 'id_option',
                            'name' => 'environment',
                        )
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Debug Mode'),
                        'hint' => $this->l('Show Detailed Error Messages and API requests/responses in the log file.'),
                        'name' => self::LATITUDE_FINANCE_DEBUG_MODE,
                        'is_bool' => true,
                        'desc' => $this->l('Turn on the debug mode to record every request and response'),
                        'values' => array(
                            array(
                                'id' => 'LATITUDE_FINANCE_DEBUG_MODE_ON',
                                'value' => 1,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'LATITUDE_FINANCE_DEBUG_MODE_OFF',
                                'value' => 0,
                                'label' => $this->l('Disabled')
                            )
                        )
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Title'),
                        'readonly' => true,
                        'desc'  => $this->l('This controls the title which the user sees during checkout.'),
                        'name' => self::LATITUDE_FINANCE_TITLE,
                    ),
                    array(
                        'type' => 'textarea',
                        'label' => $this->l('Description'),
                        'readonly' => true,
                        'desc' => $this->l('This option can be set from your account portal. When the Save Changes button is clicked, this option will update automatically.'),
                        'name' => 'LATITUDE_FINANCE_DESCRIPTION',
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Minimum Order Total'),
                        'desc'  => $this->l('This option can be set from your account portal. When the Save Changes button is clicked, this option will update automatically.'),
                        'readonly' => true,
                        'name' => self::LATITUDE_FINANCE_MIN_ORDER_TOTAL,
                    ),
                    array(
                        'type' => 'hidden',
                        'label' => $this->l('Maximum Order Total'),
                        'desc'  => $this->l('This option can be set from your account portal. When the Save Changes button is clicked, this option will update automatically.'),
                        'readonly' => true,
                        'name' => self::LATITUDE_FINANCE_MAX_ORDER_TOTAL,
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Images API URL'),
                        'desc'  => $this->l('The URL of Images API that will be used for display payment snippets and modal.'),
                        'readonly' => false,
                        'name' => self::LATITUDE_FINANCE_IMAGES_API_URL,
                    ),
                ),
                'submit' => array(
                    'name' => 'submitSave',
                    'title' => $this->l('Save'),
                ),
            ),
        );

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $lang = new Language((int)Configuration::get('PS_LANG_DEFAULT'));
        $helper->default_form_language = $lang->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $this->fields_form = array();
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitSave';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFieldsValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($fields_form));
    }

    /**
     * Get available environments
     * @return string[][]
     */
    public function getEnvironments()
    {
        return array(
            array(
                'id_option' => self::ENVIRONMENT_SANDBOX,
                'environment' => 'Sandbox'
            ),
            array(
                'id_option' => self::ENVIRONMENT_PRODUCTION,
                'environment' => 'Production'
            )
        );
    }

    /**
     * Get module configurations
     * @return array
     */
    public function getConfigFieldsValues()
    {
        return array(
            self::LATITUDE_FINANCE_TITLE => Configuration::get(self::LATITUDE_FINANCE_TITLE),
            self::LATITUDE_FINANCE_DESCRIPTION => Configuration::get(self::LATITUDE_FINANCE_DESCRIPTION),
            self::LATITUDE_FINANCE_DEBUG_MODE => Tools::getValue(self::LATITUDE_FINANCE_DEBUG_MODE, Configuration::get(self::LATITUDE_FINANCE_DEBUG_MODE)),
            self::ENVIRONMENT => Tools::getValue(self::ENVIRONMENT, Configuration::get(self::ENVIRONMENT)),
            self::LATITUDE_FINANCE_MIN_ORDER_TOTAL => Configuration::get(self::LATITUDE_FINANCE_MIN_ORDER_TOTAL),
            self::LATITUDE_FINANCE_MAX_ORDER_TOTAL => Configuration::get(self::LATITUDE_FINANCE_MAX_ORDER_TOTAL),
            self::LATITUDE_FINANCE_PUBLIC_KEY => Tools::getValue(self::LATITUDE_FINANCE_PUBLIC_KEY, Configuration::get(self::LATITUDE_FINANCE_PUBLIC_KEY)),
            self::LATITUDE_FINANCE_PRIVATE_KEY => Tools::getValue(self::LATITUDE_FINANCE_PRIVATE_KEY, Configuration::get(self::LATITUDE_FINANCE_PRIVATE_KEY)),
            self::LATITUDE_FINANCE_SANDBOX_PUBLIC_KEY => Tools::getValue(self::LATITUDE_FINANCE_SANDBOX_PUBLIC_KEY, Configuration::get(self::LATITUDE_FINANCE_SANDBOX_PUBLIC_KEY)),
            self::LATITUDE_FINANCE_SANDBOX_PRIVATE_KEY => Tools::getValue(self::LATITUDE_FINANCE_SANDBOX_PRIVATE_KEY, Configuration::get(self::LATITUDE_FINANCE_SANDBOX_PRIVATE_KEY)),
            self::LATITUDE_FINANCE_IMAGES_API_URL => Tools::getValue(self::LATITUDE_FINANCE_IMAGES_API_URL, self::DEFAULT_IMAGES_API_URL),
        );
    }

    /**
     * Store user settings
     * @return mixed
     */
    protected function postProcess()
    {
        try {
            $configuration = $this->getConfiguration();
        } catch (Exception $e) {
            return $this->displayError($e->getMessage());
        }

        if (Tools::isSubmit('submitSave')) {
            // The data fetched from Latitude Finance API
            Configuration::updateValue(self::LATITUDE_FINANCE_TITLE, $this->getConfigData('name', $configuration));
            Configuration::updateValue(self::LATITUDE_FINANCE_DESCRIPTION, $this->getConfigData('description', $configuration));
            Configuration::updateValue(self::LATITUDE_FINANCE_MIN_ORDER_TOTAL, $this->getConfigData('minimumAmount', $configuration, 20));
            // Increase the max order total significantly
            Configuration::updateValue(self::LATITUDE_FINANCE_MAX_ORDER_TOTAL, $this->getConfigData('maximumAmount', $configuration, 1500) * 1000);

            // The values set by the shop owner
            Configuration::updateValue(self::LATITUDE_FINANCE_DEBUG_MODE, Tools::getValue(self::LATITUDE_FINANCE_DEBUG_MODE));
            Configuration::updateValue(self::ENVIRONMENT, Tools::getValue(self::ENVIRONMENT));
            Configuration::updateValue(self::LATITUDE_FINANCE_PUBLIC_KEY, Tools::getValue(self::LATITUDE_FINANCE_PUBLIC_KEY));
            Configuration::updateValue(self::LATITUDE_FINANCE_PRIVATE_KEY, Tools::getValue(self::LATITUDE_FINANCE_PRIVATE_KEY));
            Configuration::updateValue(self::LATITUDE_FINANCE_SANDBOX_PUBLIC_KEY, Tools::getValue(self::LATITUDE_FINANCE_SANDBOX_PUBLIC_KEY));
            Configuration::updateValue(self::LATITUDE_FINANCE_SANDBOX_PRIVATE_KEY, Tools::getValue(self::LATITUDE_FINANCE_SANDBOX_PRIVATE_KEY));

            if (Configuration::updateValue('latitude_offical', (int)Tools::getValue('latitude_offical'))) {
                return $this->displayConfirmation($this->l('Settings updated'));
            } else {
                return $this->displayError($this->l('Confirmation button') . ': ' . $this->l('Invaild choice'));
            }
        }
    }

    /**
     * Get minimum order amount
     * @return mixed|string
     * @throws Exception
     */
    protected function getMinOrderTotal()
    {
        if (!$this->configuration) {
            $this->getConfiguration();
        }
        return $this->getConfigData('minimumAmount', $this->configuration);
    }

    /**
     * Get maximum order amount
     * @return mixed|string
     * @throws Exception
     */
    protected function getMaxOrderTotal()
    {
        if (!$this->configuration) {
            $this->getConfiguration();
        }
        return $this->getConfigData('maximumAmount', $this->configuration);
    }

    /**
     * Check if order total is allowed
     * @param $amount
     * @return bool
     * @throws Exception
     */
    protected function isOrderAmountAvailable($amount)
    {
        if ($amount > $this->getMaxOrderTotal() || $amount < $this->getMinOrderTotal()) {
            return false;
        }
        return true;
    }

    /**
     * Get a configuration value
     * @param $key
     * @param $array
     * @param string $default
     * @return mixed|string
     */
    public function getConfigData($key, $array, $default = '')
    {
        $value = isset($array[$key]) ? $array[$key] : $default;
        return $value;
    }

    /**
     * Get order available refund amount
     * @param $orderId
     * @return false|float
     */
    public static function getAvailableRefundAmount($orderId) {
        /** @var OrderCore $order */
        $order = new Order($orderId);
        if ($order->getTotalPaid()) {
            return (round($order->getTotalPaid(), 2) - round(OrderHelper::getTotalRefundedAmount($orderId), 2));
        }
        return false;
    }

    /**
     * Add private message
     * @param $id_order
     * @param $message
     * @return false
     */
    public function _addNewPrivateMessage($id_order, $message)
    {
        if (!(bool) $id_order) {
            return false;
        }

        $new_message = new Message();
        $message = strip_tags($message, '<br>');

        if (!Validate::isCleanHtml($message)) {
            $message = $this->l('Payment message is not valid, please check your module.');
        }

        $new_message->message = $message;
        $new_message->id_order = (int) $id_order;
        $new_message->private = 1;

        return $new_message->add();
    }

    /**
     * Send refund request to payment gateway
     * @param OrderCore $order
     * @param float $amount
     * @param string $reason
     * @return array
     * @throws BinaryPay_Exception
     */
    public function _makeRefund($order, $amount, $reason = "")
    {
        $currencyCode = $this->_getCurrencyCodeByPaymentMethod($order->payment);
        if (!$currencyCode) {
            $currencyCode = self::getOrderCurrencyCode($order->id);
        }
        $gateway = $this->getGateway();
        if (!$gateway) {
            return array(
                "success" => false,
                "message" => "The gateway object did not initialized correctly."
            );
        }
        $payments = $order->getOrderPayments();
        $credentials = $this->getCredentials();
        $availableRefundAmount = $this->getAvailableRefundAmount($order->id);
        if (!$availableRefundAmount || $availableRefundAmount > $order->getTotalPaid()) {
            return array(
                "success" => false,
                "message" => "The order has been refunded already"
            );
        }

        $payment = reset($payments);
        $transactionId = $payment->transaction_id;
        $reference = $payment->order_reference;

        $refund = array(
            BinaryPay_Variable::PURCHASE_TOKEN => $transactionId,
            BinaryPay_Variable::CURRENCY => $currencyCode,
            BinaryPay_Variable::AMOUNT => $amount,
            BinaryPay_Variable::REFERENCE => $reference,
            BinaryPay_Variable::REASON => $reason,
            BinaryPay_Variable::PASSWORD => $credentials['password']
        );

        try {
            if (empty($transactionId))
            {
                throw new InvalidArgumentException(sprintf('The transaction ID for order %1$s is blank. A refund cannot 
                be processed unless there is a valid transaction associated with the order.', $order->id));
            }
            $response = $gateway->refund($refund);
            // Log the refund response
            BinaryPay::log(json_encode($response), true, 'prestashop-latitude-finance.log');
            if (Configuration::get(self::LATITUDE_FINANCE_DEBUG_MODE)) {
                if (isset($response['refundId'])) {
                    $message = "Refund ID: ".$response['refundId'];
                    $message .= "\n";
                    $message .= "Refund Date: ".$response['refundDate'];
                    $message .= "\n";
                    $message .= "Amount: ".$amount;
                    $this->_addNewPrivateMessage($order->id, $message);
                } else {
                    $this->_addNewPrivateMessage($order->id, "Response from the gateway: ".json_encode($response));
                }

            }

            if (isset($response['refundId'])) {

                $rTransaction = new LatitudeRefundTransaction();
                $rTransaction->id_refund = $response['refundId'];
                $rTransaction->id_order = $order->id;
                $rTransaction->refund_date = $response['refundDate'];
                $rTransaction->refund_amount = $amount;
                $rTransaction->reference = $response['reference'];
                $rTransaction->commission_amount = $response['commissionAmount'];
                $rTransaction->payment_gateway = $order->payment;
                $rTransaction->save();

                if (self::getAvailableRefundAmount($order->id) !== false && !self::getAvailableRefundAmount($order->id)) {
                    $this->_changeOrderStatus($order->id, (int) Configuration::get('PS_OS_REFUND'));
                }
            }
            return array(
                "success" => true,
                "response" => $response
            );
        } catch (BinaryPay_Exception $e)
        {
            PrestaShopLogger::addLog($e->getMessage(), 1, null, 'PaymentModule', (int)$order->id, true);
            BinaryPay::log($e->getMessage(), true, 'prestashop-latitude-finance.log');
            if (Configuration::get(self::LATITUDE_FINANCE_DEBUG_MODE)) {
                $this->_addNewPrivateMessage($order->id, $e->getMessage());
            }
            return array(
                "success" => false,
                "message" => $e->getMessage()
            );
        }
        catch (Exception $e) {
            throw $e;
        }
    }

    /**
     * Build the correct structure of the product list
     * @param OrderCore $order
     * @return array
     */
    public function getProductList($order)
    {
        $productList = [];
        $orderDetailList = $order->getOrderDetailList();
        foreach ($orderDetailList as $productDetail) {
            $productList[] = $productDetail['id_order_detail'];
        }
        return $productList;
    }

    /**
     * Get order currency code
     * @param $order
     * @return mixed
     */
    public static function getOrderCurrencyCode($order) {
        $currencyId = $order->id_currency;
        $currency = new Currency($currencyId);
        return $currency->iso_code;
    }

    /**
     * Get and set the instance's gateway name base on order payment method
     * @param $order
     */
    public function setGatewayNameByPaymentMethod($order) {
        if ($gatewayName = $this->_getGatewayNameByPaymentMethod($order)) {
            $this->gatewayName = $gatewayName;
        }
    }

    /**
     * Get gateway name base on payment method
     * @param $order
     * @return false
     */
    protected function _getGatewayNameByPaymentMethod($order) {
        if (in_array($order->payment, self::ALLOWED_PAYMENT_GATEWAYS)) {
            return $order->payment;
        }
        return false;
    }

    /**
     * Set payment gateway
     * @throws Exception
     */
    protected function _setPaymentGateway() {
        $order = null;
        if (Tools::getValue('id_order')) {
            $order = new Order((int) Tools::getValue('id_order'));
        }
        elseif (Tools::getValue('query_data')) {
            parse_str(Tools::getValue('query_data'), $queryData);
            $order = new Order((int) $queryData['order_id']);
        }
        if ($order && in_array($order->payment, self::ALLOWED_PAYMENT_GATEWAYS)) {
            $this->getGateway($this->_getGatewayNameByPaymentMethod($order));
        } else {
            if (isset($this->context->currency)) {
                $iso_code = $this->context->currency->iso_code;
            } else {
                global $cookie;
                $id_currency = isset($cookie->id_currency) ? $cookie->id_currency : Configuration::get('PS_CURRENCY_DEFAULT');
                $currency = new Currency((int) $id_currency);
                $iso_code = $currency->iso_code;
            }
            $this->getGateway($this->getPaymentGatewayNameByCurrencyCode($iso_code));
        }
    }

    /**
     * Get currency code by payment method name
     * @param $paymentMethod
     * @return false|string
     */
    protected function _getCurrencyCodeByPaymentMethod($paymentMethod) {
        switch ($paymentMethod) {
            case self::LATITUDE_PAYMENT_METHOD_CODE:
                return "AUD";
            case self::GENOAPAY_PAYMENT_METHOD_CODE:
                return "NZD";
            default:
                return false;
        }
    }

    /**
     * Update order status
     * @param $orderId
     * @param $statusId
     */
    protected function _changeOrderStatus($orderId, $statusId) {
        /** @var OrderHistoryCore $history */
        $history = new OrderHistory();
        $history->id_order = (int) $orderId;
        $history->changeIdOrderState($statusId, $history->id_order);
        $history->addWithemail();
        $history->save();
    }

    /**
     *
     * @return bool
     */
    protected function updateDatabase() {
        /* Create Refund transactions table table */
        return Db::getInstance()->Execute('
		CREATE TABLE IF NOT EXISTS `'._DB_PREFIX_.'latitude_refund_transactions` (
		`id_refund` varchar(100) NOT null,
		`id_order` int(10) NOT null,
		`refund_date` varchar(255) NOT null,
		`reference` varchar(50) NOT null,
		`refund_amount` decimal(20,6) NOT null,
		`commission_amount` decimal(20,6) NOT null,
		`payment_gateway` varchar(50) NOT null,
		PRIMARY KEY (`id_refund`)
		) ENGINE='._MYSQL_ENGINE_.' DEFAULT CHARSET=utf8');
    }
}
