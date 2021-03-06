<?php

/**
 * This file is part of Robokassa package.
 *
 * (c) 2014 IDM Agency (http://idma.ru)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Idma\Robokassa;

use Idma\Robokassa\Exception\InvalidSumException;
use Idma\Robokassa\Exception\InvalidSumCurrencyException;
use Idma\Robokassa\Exception\InvalidParamException;
use Idma\Robokassa\Exception\InvalidInvoiceIdException;
use Idma\Robokassa\Exception\EmptyDescriptionException;
use Idma\Robokassa\Exception\UnsupportedHashFunctionException;
use Idma\Robokassa\Helpers\Dictionaries\HashFunctions;
use ReflectionClass;

/**
 * Class Payment
 *
 * @author JhaoDa <jhaoda@gmail.com>
 *
 * @package Idma\Robokassa
 */
class Payment
{
    const CULTURE_EN = 'en';
    const CULTURE_RU = 'ru';

    private $baseUrl = 'https://auth.robokassa.ru/Merchant/Index.aspx?';
    private $isTestMode;
    private $valid = false;
    private $data;
    private $customParams = [];

    private $hashFunction = HashFunctions::MD5;

    private $login;
    private $paymentPassword;
    private $validationPassword;

    /**
     * Class constructor.
     *
     * @param string $login login of Merchant
     * @param string $paymentPassword password #1
     * @param string $validationPassword password #2
     * @param bool $testMode use test server
     */
    public function __construct($login, $paymentPassword, $validationPassword, $testMode = false)
    {
        $this->login = $login;
        $this->paymentPassword = $paymentPassword;
        $this->validationPassword = $validationPassword;
        $this->isTestMode = $testMode;

        $this->data = [
            'MerchantLogin' => $this->login,
            'InvId' => null,
            'OutSum' => 0,
            'OutSumCurrency' => null,
            'Desc' => null,
            'SignatureValue' => '',
            'Encoding' => 'utf-8',
            'Culture' => self::CULTURE_RU,
            'IncCurrLabel' => ''
        ];
        if ($testMode) {
            $this->data['IsTest'] = 1;
        }
    }

    /**
     * Create payment url.
     *
     * @return string the payment url
     * @throws EmptyDescriptionException if description is empty or not provided
     * @throws InvalidInvoiceIdException if invoice ID less or equals zero or not provided
     *
     * @throws InvalidSumException       if sum less or equals zero
     */
    public function getPaymentUrl()
    {
        if ($this->data['OutSum'] <= 0) {
            throw new InvalidSumException();
        }

        if (empty($this->data['Desc'])) {
            throw new EmptyDescriptionException();
        }

        if ($this->data['InvId'] <= 0) {
            throw new InvalidInvoiceIdException();
        }

        $signature = vsprintf('%s:%01.2f:%u:', [
            // '$login:$OutSum:$InvId:'
            $this->login,
            $this->data['OutSum'],
            $this->data['InvId']
        ]);

        if ($this->data['OutSumCurrency']) {
            $signature .= $this->data['OutSumCurrency'] . ':';
        }

        $signature .= $this->paymentPassword;

        if ($this->customParams) {
            // sort params alphabetically
            ksort($this->customParams, SORT_STRING);
            $signature .= ':' . $this->buildParams($this->customParams);
        }

        $this->data['SignatureValue'] = $this->getHash($signature);

        $data = http_build_query($this->data, null, '&');
        $custom = http_build_query($this->customParams, null, '&');

        return $this->baseUrl . $data . ($custom ? '&' . $custom : '');
    }

    /**
     * Validates on ResultURL.
     *
     * @param array $data query data
     *
     * @return bool
     */
    public function validateResult($data)
    {
        return $this->validate($data);
    }

    /**
     * Validates on SuccessURL.
     *
     * @param array $data query data
     *
     * @return bool
     */
    public function validateSuccess($data)
    {
        return $this->validate($data, 'payment');
    }

    /**
     * Validates the Robokassa query.
     *
     * @param array $data query data
     * @param string $passwordType type of password, 'validation' or 'payment'
     *
     * @return bool
     */
    private function validate($data, $passwordType = 'validation')
    {
        $this->data = $data;

        if (!isset($data['OutSum'], $data['InvId'], $data['SignatureValue'])) {
            $this->valid = false;
            return $this->valid;
        }

        $password = $this->{$passwordType . 'Password'};

        $signature = vsprintf('%s:%u:%s%s', [
            // '$OutSum:$InvId:$password[:$params]'
            $data['OutSum'],
            $data['InvId'],
            $password,
            $this->getCustomParamsString($this->data)
        ]);

        $this->valid = ($this->getHash($signature) === strtolower($data['SignatureValue']));

        return $this->valid;
    }

    /**
     * Returns whether the Robokassa query is valid.
     *
     * @return bool
     */
    public function isValid()
    {
        return $this->valid;
    }

    /**
     * Adds custom parameters in payment.
     * The 'shp_' prefix will be added automatically.
     *
     * @param array $params custom parameters array
     *
     * @return Payment
     * @throws InvalidParamException if params is not an array
     *
     */
    public function addCustomParameters($params)
    {
        if (!is_array($params)) {
            throw new InvalidParamException();
        }

        foreach ($params as $key => $val) {
            $this->customParams['shp_' . $key] = $val;
        }

        return $this;
    }

    /**
     * @return string
     */
    public function getSuccessAnswer()
    {
        return 'OK' . $this->getInvoiceId() . "\n";
    }

    private function getCustomParamsString(array $source)
    {
        $params = [];

        foreach ($source as $key => $val) {
            if (stripos($key, 'shp_') === 0) {
                $params[$key] = $val;
            }
        }

        ksort($params);
        $params = $this->buildParams($params);

        return $params ? ':' . $params : '';
    }

    /**
     * Build list of params.
     * @param $params
     * @return string
     */
    private function buildParams($params)
    {
        return implode(array_map(function ($key, $value) {
            return $key . '=' . $value;
        }, array_keys($params), $params), ':');
    }

    /**
     * Get custom parameter from payment data.
     *
     * @param string $name parameter name without "shp_"
     *
     * @return mixed
     */
    public function getCustomParam($name)
    {
        $key = 'shp_' . $name;

        if (isset($this->data[$key])) {
            return $this->data[$key];
        }

        return null;
    }

    /**
     * @return int
     */
    public function getInvoiceId()
    {
        return $this->data['InvId'];
    }

    /**
     * @param $id
     *
     * @return Payment
     */
    public function setInvoiceId($id)
    {
        $this->data['InvId'] = (int)$id;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getSum()
    {
        return $this->data['OutSum'];
    }

    /**
     * @param mixed $summ
     *
     * @return Payment
     * @throws InvalidSumException
     *
     */
    public function setSum($summ)
    {
        $summ = number_format($summ, 2, '.', '');

        if ($summ > 0) {
            $this->data['OutSum'] = $summ;

            return $this;
        }

        throw new InvalidSumException();
    }

    /**
     * @return mixed
     */
    public function getSumCurrency()
    {
        if (isset($this->data['OutSumCurrency'])) {
            return $this->data['OutSumCurrency'];
        }

        return null;
    }

    /**
     * @param mixed $summ
     *
     * @return Payment
     * @throws InvalidSumException
     *
     */
    public function setSumCurrency($sum_currency)
    {
        $sum_currency = strtoupper($sum_currency);

        if (in_array($sum_currency, ['USD', 'EUR', 'KZT'])) {
            $this->data['OutSumCurrency'] = $sum_currency;
            return $this;
        }

        throw new InvalidSumCurrencyException();
    }

    /**
     * @return string
     */
    public function getDescription()
    {
        return $this->data['Desc'];
    }

    /**
     * @param string $description
     *
     * @return Payment
     */
    public function setDescription($description)
    {
        $this->data['Desc'] = (string)$description;

        return $this;
    }

    /**
     * @return string
     */
    public function getCulture()
    {
        return $this->data['Culture'];
    }

    /**
     * @param string $culture
     *
     * @return Payment
     */
    public function setCulture($culture = self::CULTURE_RU)
    {
        $this->data['Culture'] = (string)$culture;

        return $this;
    }

    /**
     * @return string
     */
    public function getCurrencyLabel()
    {
        return $this->data['IncCurrLabel'];
    }

    /**
     * @param string $currLabel
     *
     * @return Payment
     */
    public function setCurrencyLabel($currLabel)
    {
        $this->data['IncCurrLabel'] = (string)$currLabel;

        return $this;
    }

    /**
     * @param $email
     * @return $this
     */
    public function setEmail($email)
    {
        $this->data['Email'] = $email;

        return $this;
    }

    /**
     * Set hash function, that will be used in signature encoding.
     *
     * @param $hashFunction string One of HashFunctions constants
     * @return Payment
     *
     * @throws UnsupportedHashFunctionException If passed hash function isn't supported by Robokassa
     */
    public function setHashFunction($hashFunction)
    {
        $hashFunctionsClass = new ReflectionClass(HashFunctions::class);
        $allowedHashFunctions = $hashFunctionsClass->getConstants();

        if (!in_array($hashFunction, $allowedHashFunctions, true)) {
            throw new UnsupportedHashFunctionException();
        }

        $this->hashFunction = $hashFunction;

        return $this;
    }

    /**
     * @return string
     */
    public function getHashFunction()
    {
        return $this->hashFunction;
    }

    /**
     * Get hash of given string, using current hash function.
     * @param $string
     * @return string
     */
    private function getHash($string)
    {
        return hash($this->hashFunction, $string);
    }

}
