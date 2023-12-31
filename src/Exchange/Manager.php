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
     * @var array
     */
    private array $rateLimits = [];

    /**
     * @var array
     */
    private array $connectors = [];

    /**
     * Constructor
     *
     * @param ExchangeOptions $exchange
     */
    public function __construct(
        private readonly ExchangeOptions $exchange,
        private readonly string $publicKey,
        private readonly string $privateKey
    ) {
        $this->init();
    }

    /**
     * Get connectors
     *
     * @return ExchangeInterface[]
     */
    public function getConnectors(): array
    {
        return $this->connectors;
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
            'exchange' => $this->exchange,
            'status' => 'active',
            'ip' => $this->getIp()
        ])->first();

        $rateLimitList = ApiRateLimit::where([
            'type' => 'proxy',
            'exchange' => $this->exchange,
            'status' => 'active'
        ])->get();

        if ($rateLimitHost->request_status === 'active') {
            $this->rateLimits['request'] = $rateLimitHost;
        }

        if ($rateLimitHost->order_status === 'active') {
            $this->rateLimits['order'] = $rateLimitHost;
        }

        foreach ($rateLimitList as $rateLimit) {
            if ($rateLimit->request_status === 'active' && !$this->rateLimits['request']) {
                $this->rateLimits['request'] = $rateLimit;
            }

            if ($rateLimit->order_status === 'active' && !$this->rateLimits['order']) {
                $this->rateLimits['order'] = $rateLimit;
            }

            if ($this->rateLimits['request'] && $this->rateLimits['order']) {
                break;
            }
        }

        $this->connectors = [
            'request' => new $this->exchange(
                $this->publicKey,
                $this->privateKey,
                $this->getProxie($this->rateLimits['request']),
                [$this, 'requestCallback']
            ),
            'order' => new $this->exchange(
                $this->publicKey,
                $this->privateKey,
                $this->getProxie($this->rateLimits['order']),
                [$this, 'requestCallback']
            ),
        ];
    }

    /**
     * Request callback
     *
     * @param RateLimit $rateLimit
     * @param Proxie|null $proxie
     * @return void
     */
    private function requestCallback(RateLimit $rateLimit, ?Proxie $proxie = null): void
    {
        $proxie->getModel()?->update([
            'request_count' => $rateLimit->getCurrentRequest(),
            'request_last_time' => ApiRateLimit::raw('NOW()'),
            'order_count' => $rateLimit->getCurrentOrder(),
            'order_last_time' => ApiRateLimit::raw('NOW()')
        ]);
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
        $username = '-ip-'.$model->ip;
        $username = sprintf('brd-customer-%s-zone-%s%s', $customerId, $zone, $username);
        $proxie = new Proxie($host, $username, $password);
        $proxie->setModel($model);

        return $proxie;
    }
}
