<?php
namespace cointopay;

class Cointopay
{
    const VERSION           = '1.0.1';
    const USER_AGENT_ORIGIN = 'Cointopay PHP Library';
    const API_URL = 'https://cointopay.com/';

    public static $merchant_id      = '';
    public static $security_code     = '';
    public static $default_currency  = '';
    public static $user_agent  = '';

    public static function config($authentication)
    {
        if (isset($authentication['merchant_id']))
            self::$merchant_id = $authentication['merchant_id'];

        if (isset($authentication['security_code']))
            self::$security_code = $authentication['security_code'];

        if (isset($authentication['default_currency']))
            self::$default_currency= $authentication['default_currency'];

        if (isset($authentication['user_agent']))
            self::$user_agent = $authentication['user_agent'];
    }

    public static function request($url, $method = 'GET', $params = array(), $authentication = array())
    {

        $url = '';
        $merchant_id = isset($authentication['merchant_id']) ? $authentication['merchant_id'] : self::$merchant_id;
        $security_code= isset($authentication['security_code']) ? $authentication['security_code'] : self::$security_code;
        $user_agent = isset($authentication['user_agent']) ? $authentication['user_agent'] : (isset(self::$user_agent) ? self::$user_agent : (self::USER_AGENT_ORIGIN . ' v' . self::VERSION));


        # Check if credentials was passed
        if (empty($merchant_id) || empty($security_code))
            \cointopay\Exception::throwException(400, array('reason' => 'CredentialsMissing'));

        $amount = $params['price'];
        $order_id = $params['order_id'];
        $currency = $params['currency'];
        $callback_url= $params['callback_url'];
        $cancel_url = $params['cancel_url'];

        $url = "MerchantAPI?Checkout=true&MerchantID=$merchant_id&Amount=$amount&AltCoinID=1&CustomerReferenceNr=$order_id&SecurityCode=$security_code&output=json&inputCurrency=$currency&transactionconfirmurl=$callback_url&transactionfailurl=$cancel_url";

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL =>  self::API_URL.$url,
            CURLOPT_USERAGENT => $user_agent,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER=> 0
        ));

        $response = json_decode(curl_exec($curl), TRUE);
		if (is_string($response)){
				\cointopay\Exception::throwException(401, array('reason' => 'BadCredentials:'.$response));
		}
        $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($http_status === 200) {
            return $response;
        } else {
            \cointopay\Exception::throwException($http_status, $response);
        }
    }
}