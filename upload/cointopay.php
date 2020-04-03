<?php

defined('_JEXEC') or die('Restricted access');
define('COINTOPAY_VIRTUEMART_EXTENSION_VERSION', '1.0');

require_once('cointopay/init.php');

if (!class_exists('vmPSPlugin'))
    require(JPATH_VM_PLUGINS . DS . 'vmpsplugin.php');

class plgVmPaymentCointopay extends vmPSPlugin
{
    function __construct(&$subject, $config)
    {
        parent::__construct($subject, $config);

        $this->_loggable = true;
        $this->tableFields = array_keys($this->getTableSQLFields());
        $varsToPush = $this->getVarsToPush();

        $this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
    }

    function getTableSQLFields()
    {
        $SQLfields = array(
            'id' => 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
            'virtuemart_order_id' => 'int(1) UNSIGNED',
            'order_number' => 'char(64)',
            'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
            'payment_name' => 'varchar(5000)',
            'payment_order_total' => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
            'payment_currency' => 'char(3)',
            'logo' => 'varchar(5000)'
        );

        return $SQLfields;
    }

    public function getVmPluginCreateTableSQL()
    {
        return $this->createTableSQL('Payment Cointopay Table');
    }

    function getCosts(VirtueMartCart $cart, $method, $cart_prices)
    {
        return 0;
    }

    function plgVmOnStoreInstallPaymentPluginTable($jplugin_id)
    {
        return $this->onStoreInstallPluginTable($jplugin_id);
    }

    public function plgVmonSelectedCalculatePricePayment(VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name)
    {
        return $this->onSelectedCalculatePrice($cart, $cart_prices, $cart_prices_name);
    }

    function plgVmgetPaymentCurrency($virtuemart_paymentmethod_id, &$paymentCurrencyId)
    {
        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id)))
            return NULL;

        if (!$this->selectedThisElement($method->payment_element))
            return false;

        $this->getPaymentCurrency($method);

        $paymentCurrencyId = $method->payment_currency;

        return;
    }

    function plgVmOnCheckAutomaticSelectedPayment(VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter)
    {
        return $this->onCheckAutomaticSelected($cart, $cart_prices, $paymentCounter);
    }

    public function plgVmOnShowOrderFEPayment($virtuemart_order_id, $virtuemart_paymentmethod_id, &$payment_name)
    {
        $this->onShowOrderFE($virtuemart_order_id, $virtuemart_paymentmethod_id, $payment_name);
    }

    function plgVmonShowOrderPrintPayment($order_number, $method_id)
    {
        return $this->onShowOrderPrint($order_number, $method_id);
    }

    function plgVmDeclarePluginParamsPayment($name, $id, &$data)
    {
        return $this->declarePluginParams('payment', $name, $id, $data);
    }

    function plgVmDeclarePluginParamsPaymentVM3(&$data)
    {
        return $this->declarePluginParams('payment', $data);
    }

    function plgVmSetOnTablePluginParamsPayment($name, $id, &$table)
    {
        return $this->setOnTablePluginParams($name, $id, $table);
    }

    function plgVmOnPaymentNotification()
    {
        try {

            $jinput = JFactory::getApplication()->input;
            $callbackData = $jinput->getArray(array());
            $ctpOrderStatus = $callbackData['status'];
            $notEnough = $callbackData['notenough'];

            if (!isset($callbackData['CustomerReferenceNr']))
                throw new Exception('order_id was not found in callback');

            $virtuemartOrderId = $callbackData['CustomerReferenceNr'];
            $data = [
                'TransactionID' => $callbackData['TransactionID'],
                'Status' => $callbackData['status'],
                'ConfirmCode' => $callbackData['ConfirmCode']
            ];
			$transactionData = $this->getTransactiondetail($data);
            if(!$transactionData) {
                throw new Exception('Data mismatch! Data doesn\'t match with Cointopay');
            }
			if(200 !== $transactionData['status_code']){
				throw new Exception($transactionData['message']);
			}
            $response = $this->validateResponse($data);
            if(!$response) {
                throw new Exception('Data mismatch! Data doesn\'t match with Cointopay');
            }

            $modelOrder = VmModel::getModel('orders');
            $order = $modelOrder->getOrder($virtuemartOrderId);
            $paymentMethodID = $order['details']['BT']->virtuemart_paymentmethod_id;

            if (!$order)
                throw new Exception('Order #' . $callbackData['CustomerReferenceNr'] . ' does not exists');

            if($response->Status !== $ctpOrderStatus)
			   {
				   throw new Exception('We have detected different order status. Your order has been halted.');
			   }
			$method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id);


            if (!$this->selectedThisElement($method->payment_element))
                return false;


            if ($ctpOrderStatus == 'paid' && $notEnough == 0) {   // paid
                $orderStatus = 'U';
                $orderComment = 'Cointopay invoice was paid successfully.';

                $redirect = (JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&pm=' . $paymentMethodID));
            } elseif ($ctpOrderStatus == 'paid' && $notEnough == 1) { // paid and not enough
                $orderStatus = 'P';
                $orderComment = 'Cointopay invoice was paid successfully but amount is not enough.';

                $redirect = (JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&pm=' . $paymentMethodID));
            } elseif ($ctpOrderStatus == 'failed') { // failed
                $orderStatus = 'X';
                $orderComment = 'Cointopay invoice was canceled by the user.';

                $redirect = (JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=cart'));
            } else {
                $orderStatus = NULL;
                $orderComment = NULL;

                $redirect = (JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=cart'));
            }

            if (!is_null($orderStatus)) {
                $modelOrder = new VirtueMartModelOrders();
                $order['order_status'] = $orderStatus;
                $order['virtuemart_order_id'] = $virtuemartOrderId;
                $order['customer_notified'] = 1;
                $order['comments'] = $orderComment;

                $modelOrder->updateStatusForOneOrder($virtuemartOrderId, $order, true);;
            }
            header('Location: ' . $redirect);
        } catch (Exception $e) {
            exit(get_class($e) . ': ' . $e->getMessage());
        }
    }

    public function validateResponse($response) {
        $validate = true;

        $query = "SELECT payment_params FROM `#__virtuemart_paymentmethods` WHERE  payment_element = 'cointopay'";
        $db = JFactory::getDBO();
        $db->setQuery($query);
        $params = $db->loadResult();
        $payment_params = explode("=", explode("|", $params)[0]);
        $merchant_id = str_replace('"','',$payment_params[1]);

        $transaction_id = $response['TransactionID'];
        $confirm_code = $response['ConfirmCode'];
        $url = "https://app.cointopay.com/v2REAPI?MerchantID=$merchant_id&Call=QA&APIKey=_&output=json&TransactionID=$transaction_id&ConfirmCode=$confirm_code";
        $curl = curl_init($url);
        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_SSL_VERIFYPEER => 0
        ));
        $result = curl_exec($curl);
        $result = json_decode($result, true);
        if(!$result || !is_array($result)) {
            $validate = false;
        }else{
            if($response['Status'] != $result['Status']) {
                $validate = false;
            }
        }
        return $validate;
    }
	public function getTransactiondetail($data) {
        $validate = true;

        $query = "SELECT payment_params FROM `#__virtuemart_paymentmethods` WHERE  payment_element = 'cointopay'";
        $db = JFactory::getDBO();
        $db->setQuery($query);
        $params = $db->loadResult();
        $payment_params = explode("=", explode("|", $params)[0]);
        $merchant_id = str_replace('"','',$payment_params[1]);
        $confirm_code = $data['ConfirmCode'];
        $url = "https://cointopay.com/v2REAPI?Call=Transactiondetail&MerchantID=".$merchant_id."&output=json&ConfirmCode=".$confirm_code."&APIKey=a";
        $curl = curl_init($url);
        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_SSL_VERIFYPEER => 0
        ));
        $result = curl_exec($curl);
        $result = json_decode($result, true);
        if(!$result || !is_array($result)) {
            $validate = false;
        }
		else{
			return $result;
		}
    }

    function plgVmOnPaymentResponseReceived(&$html)
    {
        if (!class_exists('VirtueMartCart'))
            require(JPATH_VM_SITE . DS . 'helpers' . DS . 'cart.php');

        if (!class_exists('shopFunctionsF'))
            require(JPATH_VM_SITE . DS . 'helpers' . DS . 'shopfunctionsf.php');

        if (!class_exists('VirtueMartModelOrders'))
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');

        $virtuemart_paymentmethod_id = JRequest::getInt('pm', 0);
        $order_number = JRequest::getString('on', 0);
        $vendorId = 0;

        if (!($method = $this->getVmPluginMethod($virtuemart_paymentmethod_id)))
            return NULL;

        if (!$this->selectedThisElement($method->payment_element))
            return NULL;

        if (!($virtuemart_order_id = VirtueMartModelOrders::getOrderIdByOrderNumber($order_number)))
            return NULL;

        if (!($paymentTable = $this->getDataByOrderId($virtuemart_order_id)))
            return '';

        $payment_name = $this->renderPluginName($method);
        $html = $this->_getPaymentResponseHtml($paymentTable, $payment_name);

        return TRUE;
    }

    function plgVmDisplayListFEPayment(VirtueMartCart $cart, $selected = 0, &$htmlIn)
    {
        $session = JFactory::getSession();
        $errors = $session->get('errorMessages', 0, 'vm');

        if ($errors != "") {
            $errors = unserialize($errors);
            $session->set('errorMessages', "", 'vm');
        } else
            $errors = array();

        return $this->displayListFE($cart, $selected, $htmlIn);
    }

    public function getGMTTimeStamp()
    {
        $tz_minutes = date('Z') / 60;

        if ($tz_minutes >= 0)
            $tz_minutes = '+' . sprintf("%03d", $tz_minutes);

        $stamp = date('YdmHis000000') . $tz_minutes;

        return $stamp;
    }

    function plgVmConfirmedOrder($cart, $order)
    {
        if (!($method = $this->getVmPluginMethod($order['details']['BT']->virtuemart_paymentmethod_id)))
            return NULL;

        if (!$this->selectedThisElement($method->payment_element))
            return false;

        if (!class_exists('VirtueMartModelOrders'))
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'orders.php');

        if (!class_exists('VirtueMartModelCurrency'))
            require(JPATH_VM_ADMINISTRATOR . DS . 'models' . DS . 'currency.php');

        VmConfig::loadJLang('com_virtuemart', true);
        VmConfig::loadJLang('com_virtuemart_orders', true);

        $orderID = $order['details']['BT']->virtuemart_order_id;
        $paymentMethodID = $order['details']['BT']->virtuemart_paymentmethod_id;

        $currency_code_3 = shopFunctions::getCurrencyByID($method->currency_id, 'currency_code_3');

        $paymentCurrency = CurrencyDisplay::getInstance($method->currency_id);
        $totalInCurrency = round($paymentCurrency->convertCurrencyTo($method->currency_id, $order['details']['BT']->order_total, false), 2);

        $description = array();
        foreach ($order['items'] as $item) {
            $description[] = $item->product_quantity . ' × ' . $item->order_item_name;
        }

        $authentication = array(
            'display_name' => $method->display_name,
            'merchant_id' => $method->merchant_id,
            'security_code' => $method->security_code,
            'default_currency' => $method->currency,
            'user_agent' => 'Cointopay - Joomla VirtueMart Extension v' . COINGATE_VIRTUEMART_EXTENSION_VERSION
        );

        $ctpOrder = \Cointopay\Merchant\Order::createOrFail(array(
            'order_id' => $orderID,
            'price' => $totalInCurrency,
            'currency' => $currency_code_3,
            'cancel_url' => $this->flash_encode((JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=cart'))),
            'callback_url' => $this->flash_encode((JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginnotification&tmpl=component'))),
            'success_url' => $this->flash_encode((JROUTE::_(JURI::root() . 'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived&pm=' . $paymentMethodID))),
            'title' => JFactory::getApplication()->getCfg('sitename'),
            'description' => join($description, ', ')
        ), array(), $authentication);


        $cart->emptyCart();
        header('Location: ' . $ctpOrder->shortURL);
        exit;
    }

    public function flash_encode($input)
    {
        return rawurlencode(utf8_encode($input));
    }

    protected function checkConditions($cart, $method, $cart_prices)
    {
        return true;
    }

}

defined('_JEXEC') or die('Restricted access');

if (!class_exists('VmConfig'))
    require(JPATH_ADMINISTRATOR . DS . 'components' . DS . 'com_virtuemart' . DS . 'helpers' . DS . 'config.php');

if (!class_exists('ShopFunctions'))
    require(JPATH_VM_ADMINISTRATOR . DS . 'helpers' . DS . 'shopfunctions.php');

defined('JPATH_BASE') or die();
