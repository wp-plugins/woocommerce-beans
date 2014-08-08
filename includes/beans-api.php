<?PHP
/**
* Copyright 2014 Beans
*
* Licensed under the Apache License, Version 2.0 (the "License"); you may
* not use this file except in compliance with the License. You may obtain
* a copy of the License at
*
* http://www.apache.org/licenses/LICENSE-2.0
*
* Unless required by applicable law or agreed to in writing, software
* distributed under the License is distributed on an "AS IS" BASIS, WITHOUT
* WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied. See the
* License for the specific language governing permissions and limitations
* under the License.
*/


if (!function_exists('curl_init')) 
    trigger_error('Beans needs the CURL PHP extension.');

if (!function_exists('json_decode')) 
    trigger_error('Beans needs the JSON PHP extension.');

if (!class_exists('BeansException')) :
    
class BeansException extends Exception
{
    public function __construct($error=array()) 
    {
        if(!isset($error['code']))
            $error['code'] = -1;
        if(!isset($error['message']))
            $error['message'] = '';
            
        parent::__construct($error['message'], $error['code']);
    }
}

class BeansAPI
{
    static $SIGNATURE = 'PHP API'; // Modify this value to whatever the API is used for 
    static $ENDPOINT = "http://business.loyalbeans.com/api/v1/";
    static $WEBSITE = 'http://www.loyalbeans.com';
    
    const VERSION = '1.1.0';
    const REWARD_CART_COUPON = 'CART_COUPON';
    const REWARD_CART_DISCOUNT = 'CART_DISCOUNT';
    
    private static $secret = "";
    
    public function __construct($config) 
    {
        if(isset($config['secret']))
            self::$secret = $config['secret'];
    }
    
    public function call($function, $arg=null)
    {       
        if(!isset($arg['user']))
            $arg['user'] = self::user_cookie();
            
        if(!isset($arg['reward']))
            $arg['reward'] = self::reward_cookie();
            
        $url = self::$ENDPOINT.$function;
        
        return self::make_request($url, $arg);
    }
    
    private static function make_request($url, $data=null)
    {
        $response = NULL;
        $ch = curl_init();
        $curlConfig = array(
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
        );
        if($data)
            $curlConfig += array(
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $data,
                CURLOPT_HTTPHEADER     =>array(
                     'API-Key: '.self::$secret,
                     'Signature: '.self::$SIGNATURE,
                     'Beans API PHP Version: '.self::VERSION,
                )
            );
        curl_setopt_array($ch, $curlConfig);
        $response = curl_exec($ch);
        
        // Check for HTTP Error
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        if($content_type!='application/json'){
            $error = array(
                'code' => $http_status,
                'message' => 'HTTP Error: '.$http_status,
            );
            throw new BeansException($error);
        }
        curl_close($ch);
    
        $response = json_decode($response, TRUE);
        
        if($response['error'])
            throw new BeansException($response['error']);
        
        return $response['result'];
    }
    
    private static function user_cookie()
    {
        if(isset($_COOKIE['beans_user']))
            return  $_COOKIE['beans_user'];
        else 
            return null;
    }
    
    private static function reward_cookie()
    {
        if(isset($_COOKIE['beans_reward']))
            return $_COOKIE['beans_reward'];
        else 
            return null;
    }
}

endif;