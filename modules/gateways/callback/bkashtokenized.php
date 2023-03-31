<?php

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

class bKashCheckout
{
    /**
     * @var self
     */
    private static $instance;

    /**
     * @var string
     */
    protected $gatewayModuleName;

    /**
     * @var array
     */
    protected $gatewayParams;

    /**
     * @var boolean
     */
    public $isSandbox;

    /**
     * @var boolean
     */
    public $isActive;

    /**
     * @var integer
     */
    protected $customerCurrency;

    /**
     * @var object
     */
    protected $gatewayCurrency;

    /**
     * @var integer
     */
    protected $clientCurrency;

    /**
     * @var float
     */
    protected $convoRate;

    /**
     * @var array
     */
    protected $invoice;

    /**
     * @var float
     */
    protected $due;

    /**
     * @var float
     */
    protected $fee;

    /**
     * @var float
     */
    public $total;

    /**
     * @var string
     */
    protected $baseUrl;

    /**
     * @var \Symfony\Component\HttpFoundation\Request
     */
    public $request;

    /**
     * @var array
     */
    private $credential;

    /**
     * bKashCheckout constructor.
     */
    public function __construct()
    {
        $this->setRequest();
        $this->setGateway();
        $this->setInvoice();
    }

    /**
     * The instance.
     *
     * @return self
     */
    public static function init()
    {
        if (self::$instance == null) {
            self::$instance = new bKashCheckout;
        }

        return self::$instance;
    }

    /**
     * Set the payment gateway.
     */
    private function setGateway()
    {
        $this->gatewayModuleName = basename(__FILE__, '.php');
        $this->gatewayParams     = getGatewayVariables($this->gatewayModuleName);
        $this->isSandbox         = !empty($this->gatewayParams['sandbox']);
        $this->isActive          = !empty($this->gatewayParams['type']);

        $this->credential = [
            'username'  => $this->gatewayParams['username'],
            'password'  => $this->gatewayParams['password'],
            'appKey'    => $this->gatewayParams['appKey'],
            'appSecret' => $this->gatewayParams['appSecret'],
        ];

        $this->baseUrl = $this->isSandbox ? 'https://tokenized.sandbox.bka.sh/v1.2.0-beta/tokenized/checkout/' : 'https://tokenized.pay.bka.sh/v1.2.0-beta/tokenized/checkout/';
    }

    /**
     * Set request.
     */
    private function setRequest()
    {
        $this->request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
    }

    /**
     * Set the invoice.
     */
    private function setInvoice()
    {
        $this->invoice = localAPI('GetInvoice', [
            'invoiceid' => $this->request->get('id'),
        ]);

        $this->setCurrency();
        $this->setDue();
        $this->setFee();
        $this->setTotal();
    }

    /**
     * Set currency.
     */
    private function setCurrency()
    {
        $this->gatewayCurrency  = (int) $this->gatewayParams['convertto'];
        $this->customerCurrency = (int) \WHMCS\Database\Capsule::table('tblclients')
            ->where('id', '=', $this->invoice['userid'])
            ->value('currency');

        if (!empty($this->gatewayCurrency) && ($this->customerCurrency !== $this->gatewayCurrency)) {
            $this->convoRate = \WHMCS\Database\Capsule::table('tblcurrencies')
                ->where('id', '=', $this->gatewayCurrency)
                ->value('rate');
        } else {
            $this->convoRate = 1;
        }
    }

    /**
     * Set due.
     */
    private function setDue()
    {
        $this->due = $this->invoice['balance'];
    }

    /**
     * Set fee.
     */
    private function setFee()
    {
        $this->fee = empty($this->gatewayParams['fee']) ? 0 : (($this->gatewayParams['fee'] / 100) * $this->due);
    }

    /**
     * Set total.
     */
    private function setTotal()
    {
        $this->total = ceil(($this->due + $this->fee) * $this->convoRate);
    }

    /**
     * Grant and get token from API.
     *
     * @return mixed
     */
    private function getToken()
    {
        $fields   = [
            'app_key'    => $this->credential['appKey'],
            'app_secret' => $this->credential['appSecret'],
        ];
        $context  = [
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n" .
                    "Accept: application/json\r\n" .
                    "username: {$this->credential['username']}\r\n" .
                    "password: {$this->credential['password']}\r\n",
                'content' => json_encode($fields),
                'timeout' => 30,
            ],
        ];
        $context  = stream_context_create($context);
        $url      = $this->baseUrl . 'token/grant';
        $response = file_get_contents($url, false, $context);
        $token    = json_decode($response, true);

        return (is_array($token) && isset($token['id_token'])) ? $token['id_token'] : null;
    }

    /**
     * Create payment session.
     *
     * @return array
     */
    public function createPayment()
    {
        $systemUrl = \WHMCS\Config\Setting::getValue('SystemURL');
        $callbackURL = $systemUrl . '/modules/gateways/callback/' . $this->gatewayModuleName . '.php?id=' . $this->invoice['invoiceid'] . '&action=verify';
        $fields   = [
            'mode'                  => '0011',
            'amount'                => $this->total,
            'currency'              => 'BDT',
            'intent'                => 'sale',
            'payerReference'        => $this->invoice['invoiceid'],
            'callbackURL'           => $callbackURL,
            'merchantInvoiceNumber' => $this->invoice['invoiceid'] . '-' . rand(1000000, 9999999),
        ];
        $context  = [
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n" .
                    "Accept: application/json\r\n" .
                    "Authorization: {$this->getToken()}\r\n" .
                    "X-APP-KEY: {$this->credential['appKey']}\r\n",
                'content' => json_encode($fields),
                'timeout' => 30,
            ],
        ];
        $context  = stream_context_create($context);
        $url      = $this->baseUrl . 'create';
        $response = file_get_contents($url, false, $context);
        $data     = json_decode($response, true);

        if (is_array($data)) {
            return $data;
        }

        return [
            'status'    => 'error',
            'message'   => 'Invalid response from bKash API.',
            'errorCode' => 'irs',
        ];
    }

    /**
     * Execute the payment by ID.
     *
     * @return array
     */
    private function executePayment()
    {
        $paymentId = $this->request->get('paymentID');
        $context   = [
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n" .
                    "Accept: application/json\r\n" .
                    "Authorization: {$this->getToken()}\r\n" .
                    "X-APP-KEY: {$this->credential['appKey']}\r\n",
                'timeout' => 30,
                'content' => json_encode([
                    'paymentID' => $paymentId,
                ]),
            ],
        ];

        $context  = stream_context_create($context);
        $url      = $this->baseUrl . 'execute';
        $response = file_get_contents($url, false, $context);
        $data     = json_decode($response, true);

        if (is_array($data)) {
            return $data;
        }

        return [
            'status'    => 'error',
            'message'   => 'Invalid response from bKash API.',
            'errorCode' => 'irs',
        ];
    }

    private function queryPayment()
    {
        $paymentId = $this->request->get('paymentID');
        $context   = [
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n" .
                    "Accept: application/json\r\n" .
                    "Authorization: {$this->getToken()}\r\n" .
                    "X-APP-KEY: {$this->credential['appKey']}\r\n",
                'timeout' => 30,
                'content' => json_encode([
                    'paymentID' => $paymentId,
                ]),
            ],
        ];

        $context  = stream_context_create($context);
        $url      = $this->baseUrl . 'payment/status';
        $response = file_get_contents($url, false, $context);
        $data     = json_decode($response, true);

        if (is_array($data)) {
            return $data;
        }

        return [
            'status'    => 'error',
            'message'   => 'Invalid response from bKash API.',
            'errorCode' => 'irs',
        ];
    }

    /**
     * Check if transaction if exists.
     *
     * @param string $trxId
     *
     * @return mixed
     */
    private function checkTransaction($trxId)
    {
        return localAPI('GetTransactions', ['transid' => $trxId]);
    }

    /**
     * Log the transaction.
     *
     * @param array $payload
     *
     * @return mixed
     */
    private function logTransaction($payload)
    {
        return logTransaction(
            $this->gatewayParams['name'],
            [
                $this->gatewayModuleName => $payload,
                'request_data'           => $this->request->request->all(),
            ],
            $payload['transactionStatus']
        );
    }

    /**
     * Add transaction to the invoice.
     *
     * @param string $trxId
     *
     * @return array
     */
    private function addTransaction($trxId)
    {
        $fields = [
            'invoiceid' => $this->invoice['invoiceid'],
            'transid'   => $trxId,
            'gateway'   => $this->gatewayModuleName,
            'date'      => \Carbon\Carbon::now()->toDateTimeString(),
            'amount'    => $this->due,
            'fees'      => $this->fee,
        ];
        $add    = localAPI('AddInvoicePayment', $fields);

        return array_merge($add, $fields);
    }

    /**
     * Make the transaction.
     *
     * @return array
     */
    public function makeTransaction()
    {
        $executePayment = $this->executePayment();

        if (!isset($executePayment['transactionStatus']) && !isset($executePayment['errorCode'])) {
            $executePayment = $this->queryPayment();
        }

        if (isset($executePayment['transactionStatus']) && $executePayment['transactionStatus'] === 'Completed') {
            $existing = $this->checkTransaction($executePayment['trxID']);

            if ($existing['totalresults'] > 0) {
                return [
                    'status'    => 'error',
                    'message'   => 'The transaction has been already used.',
                    'errorCode' => 'tau',
                ];
            }

            if ($executePayment['amount'] < $this->total) {
                return [
                    'status'    => 'error',
                    'message'   => 'You\'ve paid less than amount is required.',
                    'errorCode' => 'lpa',
                ];
            }

            $this->logTransaction($executePayment);

            $trxAddResult = $this->addTransaction($executePayment['trxID']);

            if ($trxAddResult['result'] === 'success') {
                return [
                    'status'  => 'success',
                    'message' => 'The payment has been successfully verified.',
                ];
            }
        }

        return $executePayment;
    }
}

if (!(new \WHMCS\ClientArea)->isLoggedIn()) {
    die("You will need to login first.");
}

$bKashCheckout = bKashCheckout::init();
if (!$bKashCheckout->isActive) {
    die("The gateway is unavailable.");
}

$response = [
    'status'  => 'error',
    'message' => 'Invalid action.',
];
$action = $bKashCheckout->request->get('action');
$invid = $bKashCheckout->request->get('id');
$status = $bKashCheckout->request->get('status');

if ($action === 'init') {
    $response = $bKashCheckout->createPayment();
    if ($response['statusCode'] === '0000') {
        header('Location: ' . $response['bkashURL']);
        exit;
    } else {
        redirSystemURL("id=$invid&paymentfailed=true&errorCode={$response['errorCode']}", "viewinvoice.php");
        exit;
    }
}

if ($action === 'verify' && $status === 'success') {
    $response = $bKashCheckout->makeTransaction();
    if ($response['status'] === 'success') {
        redirSystemURL("id={$invid}&paymentsuccess=true", "viewinvoice.php");
        exit;
    } else {
        redirSystemURL("id=$invid&paymentfailed=true&errorCode={$response['errorCode']}", "viewinvoice.php");
        exit;
    }
}


redirSystemURL("id=$invid&paymentfailed=true&errorCode=$status", "viewinvoice.php");
