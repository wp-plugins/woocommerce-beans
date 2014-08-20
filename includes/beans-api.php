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
    static $signature = 'PHP API';      // Set this value to whatever the API is used for 
    static $fail_silently = true;       // Set this to false in dev env
    
    static $endpoint = "http://business.loyalbeans.com/api/v1/";
    static $website = 'http://www.loyalbeans.com';
    
    const VERSION = '1.2.0';
    
    private static $secret = "";
    private static $error_log_type = 0;         // PHP error_log: $message_type  argument
    private static $error_log_destination = ''; // PHP error_log: $destination   argument
    private static $error_log_extra = '';       // PHP error_log: $extra_headers argument
    
    // Consider using init instead of object construct 
    public function __construct($config) 
    {
        self::init('', $config);
    }
    
    public static function init($key, $config=null)
    {
        self::$secret = $key;
        if(isset($config['secret']))
            self::$secret = $config['secret'];
    }
    
    // This is the famous function
    public static function call($function, $arg=null, $fail_silently=null)
    {
        if(!isset($arg['user']))
            $arg['user'] = self::user_cookie();
            
        if(!isset($arg['reward']))
            $arg['reward'] = self::reward_cookie();
            
        $url = self::$endpoint.$function;
        
        return self::make_request($url, $arg, $fail_silently);
    }
    
    // Use This function to avoid handling errors
    public static function set_error_log($type, $destination='', $extra='')
    {
        self::$error_log_type = $type;
        self::$error_log_destination = $destination;
        self::$error_log_extra = $extra;
    }
    
    private static function make_request($url, $data=null, $fail_silently=null)
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
                     'Signature: '.self::$signature,
                     'Beans Version: API PHP '.self::VERSION,
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
            return self::handle_error($error, $fail_silently);
        }
        curl_close($ch);
    
        $response = json_decode($response, TRUE);
        
        if($response['error'])
            return self::handle_error($response['error'], $fail_silently);
        
        return $response['result'];
    }
    
    private static function handle_error($error_string, $fail_silently=null)
    {
        if( $fail_silently === null )
            $fail_silently = self::$fail_silently;
        
        $error = new BeansException($error_string);
        
        if($fail_silently){
            error_log($error, self::$error_log_type, self::$error_log_destination, self::$error_log_extra);
            return null;
        }
        else
            throw $error;
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