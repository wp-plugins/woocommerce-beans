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


class Beans
{
    static $signature = 'PHP API';      // Set this value to whatever the API is used for 
    static $fail_silently = true;       // Set this to false in dev env

    static $endpoint = 'http://api.trybeans.com/v1/';
    static $access_token_path = 'oauth/access_token';

    const VERSION = '1.3.3';

    private static $secret = '';
    private static $error_log_type = 0;         // PHP error_log: $message_type  argument
    private static $error_log_destination = ''; // PHP error_log: $destination   argument
    private static $error_log_extra = '';       // PHP error_log: $extra_headers argument

    // Consider using init instead of object construct 
    public function __construct()
    {
        self::init('');
    }

    public static function init($secret_key)
    {
        self::$secret = $secret_key;
    }

    public static function get($path, $arg=null, $fail_silently=null)
    {
        return self::make_request($path, $arg, $fail_silently, 'GET');
    }

    public static function post($path, $arg=null, $fail_silently=null)
    {
        return self::make_request($path, $arg, $fail_silently, 'POST');
    }

    public static function delete($path, $arg=null, $fail_silently=null)
    {
        return self::make_request($path, $arg, $fail_silently, 'DELETE');
    }

    // Use This function to avoid handling errors
    public static function set_error_log($type, $destination='', $extra='')
    {
        self::$error_log_type = $type;
        self::$error_log_destination = $destination;
        self::$error_log_extra = $extra;
    }

    public static function get_token_from_cookie($cookies=null, $fail_silently=null){
        if(!$cookies) $cookies = $_COOKIE;

        if(!empty($cookies['beans_user'])){
            $code = $cookies['beans_user'];
            setcookie('beans_user', '', time()-10, '/');
            return Beans::get(self::$access_token_path, array('code'=>$code), $fail_silently);
        }
        return null;
    }

    private static function make_request($path, $data=null, $fail_silently=null, $method=null)
    {
        $url = self::$endpoint.$path;

        $data_string = json_encode( $data ? $data : array() );

        $ua = array(
            'bindings_version'  => self::VERSION,
            'application'       => self::$signature,
            'lang'              => 'PHP',
            'lang_version'      => phpversion(),
            'publisher'         => 'Beans',
        );

        // Set Request Options
        $curlConfig = array(
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_POSTFIELDS     => $data_string,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT        => 80,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_USERPWD        => self::$secret.':',
            CURLOPT_HTTPHEADER     => array(
                'Content-Type: application/json',
                'Content-Length: ' . strlen($data_string),
                'X-Beans-Client-User-Agent: '. json_encode($ua),
            ),
        );

        //Make HTTP request
        $ch = curl_init();
        curl_setopt_array($ch, $curlConfig);
        $response = curl_exec($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);

        // Check for connection error
        if (!$http_status) {
            $error = array(
                'code' => curl_errno($ch),
                'message' => 'cURL Error: '.curl_error($ch),
            );
            curl_close($ch);
            return self::handle_error($error, $fail_silently);
        }

        curl_close($ch);

        // Check for HTTP error
        if($content_type!='application/json'){
            $error = array(
                'code' => $http_status,
                'message' => 'HTTP Error: '.$http_status,
            );
            return self::handle_error($error, $fail_silently);
        }

        // Load response
        $response = json_decode($response, TRUE);

        // Check for Beans error
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
            $log = PHP_EOL.PHP_EOL.PHP_EOL
                .'Exception at '.date('Y-m-d H:i:s.uP').PHP_EOL
                .self::$signature.PHP_EOL
                .$error->getMessage().PHP_EOL;
            error_log($log, self::$error_log_type, self::$error_log_destination, self::$error_log_extra);
            error_log($error, self::$error_log_type, self::$error_log_destination, self::$error_log_extra);
            return null;
        }
        else
            throw $error;
    }
}