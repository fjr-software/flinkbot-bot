<?php

declare(strict_types=1);

namespace FjrSoftware\Flinkbot\Bot\Exchange;

use FjrSoftware\Flinkbot\Bot\Model\ApiRateLimit;
use FjrSoftware\Flinkbot\Exchange\ExchangeInterface;
use FjrSoftware\Flinkbot\Exchange\RateLimit;
use FjrSoftware\Flinkbot\Request\Proxie;

class Manager
{
    /**
     * @var ExchangeInterface
     */
    private ExchangeInterface $exchange;

    /**
     * Constructor
     *
     * @param ExchangeOptions $exchangeName
     */
    public function __construct(
        private readonly ExchangeOptions $exchangeName,
        private readonly string $publicKey,
        private readonly string $privateKey
    ) {
        $this->init();
    }

    /**
     * Get exchange
     *
     * @return ExchangeInterface
     */
    public function getExchange(): ExchangeInterface
    {
        $this->init();

        return $this->exchange;
    }

    /**
     * Initialize
     *
     * @return void
     */
    private function init(): void
    {
        $rateLimitHost = ApiRateLimit::where([
            'type' => 'hosting',
            'exchange' => strtolower($this->exchangeName->name),
            'status' => 'active',
            'ip' => $this->getIp()
        ])->first();

        $rateLimitList = ApiRateLimit::where([
            'type' => 'proxy',
            'exchange' => strtolower($this->exchangeName->name),
            'status' => 'active'
        ])->get();

        $rateLimitCurrent = null;

        if ($rateLimitHost?->request_status === 'active') {
            $rateLimitCurrent = $rateLimitHost;
        }

        foreach ($rateLimitList as $rateLimit) {
            if ($rateLimit->request_status === 'active' && !$rateLimitCurrent) {
                $rateLimitCurrent = $rateLimit;
                break;
            }
        }

        $this->exchange = new ($this->exchangeName->getClass())(
            $this->publicKey,
            $this->privateKey,
            $this->getProxie($rateLimitCurrent),
            function (RateLimit $rateLimit, ?Proxie $proxie = null) {
                $requestCount = $rateLimit->getCurrentRequest();
                $requestCount >= 0 ? $requestCount : 0;

                $orderCount = $rateLimit->getCurrentOrder();
                $orderCount >= 0 ? $orderCount : 0;

                $proxie->getModel()?->update([
                    'request_count' => abs((int) $requestCount),
                    'request_last_time' => ApiRateLimit::raw('NOW()'),
                    'order_count' => abs((int) $orderCount),
                    'order_last_time' => ApiRateLimit::raw('NOW()')
                ]);
            }
        );
    }

    /**
     * Get ip
     *
     * @return string|null
     */
    private function getIp(): ?string
    {
        $result = exec('ifconfig eth0 | grep \'inet \' | awk \'{print $2}\'');
        return $result ? trim($result) : null;
    }

    /**
     * Get proxie
     *
     * @param object|null $model
     * @return Proxie
     */
    private function getProxie(?object $model = null): Proxie
    {
        $host = 'http://brd.superproxy.io:22225';
        $customerId = 'hl_1697dddf';
        $zone = 'data_center';
        $password = 't5g38nb15w1y';
        $username = '-ip-'.$model?->ip;
        $username = sprintf('brd-customer-%s-zone-%s%s', $customerId, $zone, $username);
        $proxie = new Proxie($host, $username, $password);
        $proxie->setModel($model);

        return $proxie;
    }
}
