<?php
namespace Taydh\TeleQuery;

class DatahaltConnector
{
    private $clientId;
    private $authDisabled;
    private $otpKey;
    private $baseUrl;
    private $connect;
    private $authToken;

    public function __construct ( $config )
    {
        $this->clientId = $config['clientId'];
        $this->authDisabled = $config['authDisabled'] ?? false;
        $this->otpKey = $config['otpKey'] ?? null;
        $this->baseUrl = $config['baseUrl'];
        $this->connect = $config['connect'];
    }

    private function generateOTP ( )
    {
        $g = new \Sonata\GoogleAuthenticator\GoogleAuthenticator();

        return $g->getCode($this->otpKey);
    }

    private function auth ()
    {
        $otp = $this->generateOTP();
        $data = http_build_query(['clientId' => $this->clientId, 'otp' => $otp]);
        $ctx = stream_context_create([
            'http' => [
                'ignore_errors' => true,
                'method'  => 'POST',
                'header'  => 'Content-Type: application/x-www-form-urlencoded' . PHP_EOL
                    .'Content-Length: '. strlen($data) . PHP_EOL,
                'content' => $data
            ]
        ]);

        $url = $this->baseUrl . 'auth.php';
        $resp = file_get_contents($url, false, $ctx);
        $resp = @json_decode($resp, true);

        if ($resp) {
            $this->authToken = $resp['data']['authToken'];
        }
        else {
            throw new \Exception('Datahalt connector authorization fail');
        }
    }

    public function query ( $entry )
    {
        if (!$this->authToken) {
            $this->auth();
        }

        $query = [
            'entries' => [
                ['_connect_' => $this->connect],
                $entry
            ]
        ];

        $json = json_encode($query);
        $ctx = stream_context_create([
            'http' =>[
                'ignore_errors' => true,
                'method'  => 'POST',
                'header'  => 'Content-Type: application/json' . PHP_EOL
                    .'Content-Length: '. strlen($json) . PHP_EOL
                    .'Authorization: Bearer ' . $this->authToken . PHP_EOL,
                'content' => $json,
            ]
        ]);

        $url = $this->baseUrl . 'query.php';
        $respRaw = file_get_contents($url, false, $ctx);
        $resp = @json_decode($respRaw, true);
        $result = [];
        
        if ($resp) {
            $result = $resp['data'][$entry->label];
        }
        else {
            $result[] = ['error' => 'Query, please see error logs'];
        }

        return $result;
    }
}