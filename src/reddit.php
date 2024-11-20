<?php
namespace knivey\reddit;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\Interceptor\SetRequestHeaderIfUnset;
use Amp\Http\Client\Request;
use Amp\Http\Client\Form;
use Amp\Future;
use Amp;


class reddit {
    protected string $access_token = "";
    protected int $tokenExpires = 0;
    protected string $device_id;
    protected HttpClient $httpClient;

    //TODO add option for setting request timeouts

    function __construct(protected string $client_id, protected string $user_agent)
    {
        $this->device_id = bin2hex(random_bytes(21));
        $this->httpClient = (new HttpClientBuilder())->skipDefaultUserAgent()->intercept(new SetRequestHeaderIfUnset('user-agent', $user_agent))->build();
    }

    /**
     * 
     * @param bool $force 
     * @return Future<void> 
     */
    public function renewToken(bool $force = false): Future {
        return \Amp\async (function () use ($force): void {
            if($this->tokenExpires -100 > time() && !$force)
                return;
            $time = time();
            $r = new Request("https://www.reddit.com/api/v1/access_token", "POST");
            $b = new Form();
            $b->addField("grant_type", "https://oauth.reddit.com/grants/installed_client");
            $b->addField("device_id", $this->device_id);
            $r->setBody($b);
            $r->addHeader("Authorization", 'Basic ' . base64_encode($this->client_id . ':'));
            $response = $this->httpClient->request($r);
            $body = $response->getBody()->buffer();
            if ($response->getStatus() != 200) {
                throw new \Exception("Reddit response status {$response->getStatus()} when renewing token");
            }
            $json = json_decode($body);
            if(!isset($json->access_token) || !isset($json->expires_in)) {
                throw new \Exception("Reddit unknown data when renewing token");
            }
            $this->access_token = $json->access_token;
            $this->tokenExpires = $time + $json->expires_in;
        });
    }

    /**
     * 
     * @param string $endpoint 
     * @return Future<mixed> json_decode of the response
     */
    public function getCall(string $endpoint): Future {
        return Amp\async(function () use ($endpoint) {
            $this->renewToken()->await();
            $retry = 0;
            while(true) {
                if($retry > 0) {
                    $this->renewToken(true)->await();
                }
                $r = new Request("https://oauth.reddit.com/$endpoint");
                $r->addHeader("Authorization", "bearer {$this->access_token}");
                $response = $this->httpClient->request($r);
                $body = $response->getBody()->buffer();
                if ($response->getStatus() != 200) {
                    if ($retry < 2) {
                        $retry++;
                        continue;
                    }
                    throw new \Exception("Reddit response status {$response->getStatus()} when accessing $endpoint");
                }
                return json_decode($body);
            }
        });
    }

    /**
     * 
     * @param string $url 
     * @return Future<mixed> 
     */
    public function info(string $url): Future {
        return Amp\async(function () use ($url) {
            return $this->getCall("api/info?url=".urlencode($url));
        });
    }
}