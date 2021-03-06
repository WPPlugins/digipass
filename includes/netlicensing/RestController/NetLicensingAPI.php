<?php

/**
 * @author    Labs64 <info@labs64.com>
 * @license   GPL-2.0+
 * @link      http://www.labs64.com
 * @copyright 2015 Labs64
 */
namespace NetLicensing;

use Curl;
use CurlResponse;

class NetLicensingAPI
{
    const BASIC_AUTHENTICATION = 0;
    const API_KEY_IDENTIFICATION = 1;

    private $_username = '';
    private $_password = '';
    private $_api_key = '';
    private $_security_code = '';
    private $_vendor_number = '';

    /** @var $_curl Curl */
    private $_curl;
    private $_base_url = '';

    /** @var $_last_response CurlResponse  */
    private $_last_response = null;
    private $_success_required = TRUE;

    public function __construct($base_url)
    {
        if (filter_var($base_url, FILTER_VALIDATE_URL) === false) {
            throw new NetLicensingException($base_url . ' is not a valid URL');
        }

        $this->_base_url = $base_url;
        $this->_curl = new Curl();
    }

    public static function connect($base_url)
    {
        return new NetLicensingAPI($base_url);
    }

    public function setUserName($username)
    {
        $this->_username = $username;
    }

    public function getUserName()
    {
        return $this->_username;
    }

    public function setPassword($password)
    {
        $this->_password = $password;
    }

    public function getPassword()
    {
        return $this->_password;
    }

    public function setApiKey($api_key)
    {
        $this->_api_key = $api_key;
    }

    public function getApiKey()
    {
        return $this->_api_key;
    }

    public function setSecurityCode($security_flag)
    {
        if ($security_flag != self::BASIC_AUTHENTICATION && $security_flag != self::API_KEY_IDENTIFICATION) {
            throw new NetLicensingException('Wrong authentication flag');
        }

        $this->_security_code = $security_flag;
    }

    public function getSecurityCode()
    {
        return $this->_security_code;
    }

    public function setVendorNumber($vendor_number)
    {
        $this->_vendor_number = $vendor_number;
    }

    public function getVendorNumber()
    {
        return $this->_vendor_number;
    }

    public function setResponseFormat($format)
    {
        switch (strtolower($format)) {
            case 'json':
                $format = 'application/json';
                break;
            case 'xml':
                $format = 'application/xml';
                break;
            case 'application/json':
                break;
            case 'application/xml':
                break;
            default:
                throw new NetLicensingException(printf('Got unsupported response format %s', $format));
                break;
        }

        $this->_curl->headers['Accept'] = $format;
    }

    public function getLastResponse()
    {
        return $this->_last_response;
    }

    public function get($url, $params = array())
    {
        return $this->_request('GET', $url, $params);
    }

    public function post($url, $params = array())
    {
        return $this->_request('POST', $url, $params);
    }

    public function put($url, $params = array())
    {
        return $this->_request('PUT', $url, $params);
    }

    public function delete($url, $params = array())
    {
        return $this->_request('DELETE', $url, $params);
    }

    public function successRequestRequired($state)
    {
        $this->_success_required = ($state) ? TRUE : FALSE;
    }

    protected function _request($method, $url, $params = array())
    {
        $allowed_requests_types = array('GET', 'POST', 'PUT', 'DELETE');

        $method = strtoupper($method);
        if (!in_array($method, $allowed_requests_types)) {
            throw new NetLicensingException('Invalid request type:' . $method . ', allowed requests types: GET, POST, DELETE.');
        }

        switch ($this->_security_code) {
            case self::BASIC_AUTHENTICATION:

                if (empty($this->_username)) throw new NetLicensingException('Missing parameter "username" for connection');
                if (empty($this->_password)) throw new NetLicensingException('Missing parameter "password" for connection');

                $this->_curl->headers['Authorization'] = 'Basic ' . base64_encode($this->_username . ":" . $this->_password);
                break;
            case self::API_KEY_IDENTIFICATION:

                if (empty($this->_api_key)) throw new NetLicensingException('Missing parameter "apiKey" for connection');

                $this->_curl->headers['Authorization'] = 'Basic ' . base64_encode("apiKey:" . $this->_api_key);
                break;
            default:
                throw new NetLicensingException('Missing or wrong authentication security code');
                break;
        }

        $url = $this->_base_url . $url;

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new NetLicensingException($url . ' is not a valid URL');
        }

        switch($method){
            case 'GET':
                $this->_last_response = $this->_curl->get($url, $params);
                break;
            case 'POST':
                $this->_last_response = $this->_curl->post($url, $params);
                break;
            case 'DELETE':
                $this->_last_response = $this->_curl->delete($url, $params);
                break;
        }

        if ($this->_success_required) {
            switch ($this->_last_response->headers['Status-Code']) {
                case '200':
                    break;
                case '204':
                    break;
                default:
                    if ($this->_last_response) {
                        $status_code = $this->_last_response->headers['Status-Code'];
                        $status_description = self::getInfoByXml($this->_last_response->body);

                        throw new NetLicensingException($status_description, $status_code);
                    } else {
                        throw new NetLicensingException('Can not connect to the NetLicensing server');
                    }

                    break;
            }
        }

        return $this->_last_response;
    }

    public static function getPropertiesByXml($xml)
    {
        $properties = array();

        if (is_string($xml)) {
            $xml = simplexml_load_string($xml);
        }

        if ($xml instanceof \SimpleXMLElement) {
            if (!empty($xml->items->item)) {
                foreach ($xml->items->item as $item) {
                    if ($item->property) {
                        $tmp_array = array();
                        foreach ($item->property as $property) {
                            $name = (string)$property['name'];
                            $value = (string)$property;
                            $tmp_array[$name] = $value;
                        }
                        $properties[] = $tmp_array;
                    }
                }
            }
        }

        return $properties;
    }

    public static function getPropertiesByJson($json)
    {
        $properties = array();

        $response = $json;

        if (is_string($json)) {
            $response = json_decode($json);
        }

        if (!empty($response->items->item)) {

            foreach ($response->items->item as $item) {
                $tmp_array = array();

                foreach ($item->property as $property) {
                    $property = (array)$property;
                    $tmp_array[$property['@name']] = $property['$'];

                }
                if (!empty($tmp_array['number'])) {
                    $properties[$tmp_array['number']] = $tmp_array;
                }
            }
        }

        return $properties;
    }

    public static function getInfoByXml($xml)
    {

        if (is_string($xml)) {
            $xml = simplexml_load_string($xml);
        }

        return (string)$xml->infos->info;
    }
}
