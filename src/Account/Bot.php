<?php

declare(strict_types=1);

namespace FjrSoftware\Flinkbot\Bot\Account;

use LogicException;
use FjrSoftware\Flinkbot\Bot\Model\Bots;
use FjrSoftware\Flinkbot\Exchange\Binance;
use FjrSoftware\Flinkbot\Exchange\ExchangeInterface;
use Illuminate\Database\Eloquent\Collection;

class Bot
{
    /**
     * @const array
     */
    public const EXCHANGES = [
        'binance' => Binance::class
    ];

    /**
     * @var ExchangeInterface|null
     */
    private ?ExchangeInterface $exchange = null;

    /**
     * @var Collection
     */
    private Collection $collection;

    /**
     * Constructor
     *
     * @param int $botId
     */
    public function __construct(
        private readonly int $botId
    ) {
        $this->collection = Bots::where('id', $this->botId)->get();
    }

    /**
     * Get bot id
     *
     * @return int
     */
    public function getId(): int
    {
        return $this->collection->id;
    }

    /**
     * Get user id
     *
     * @return int
     */
    public function getUserId(): int
    {
        return $this->collection->user_id;
    }

    /**
     * Get name
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->collection->name;
    }

    /**
     * Get exchange
     *
     * @return ExchangeInterface
     * @throws LogicException
     */
    public function getExchange(): ExchangeInterface
    {
        $exchange = $this->collection->exchange;

        if (!in_array($exchange, self::EXCHANGES)) {
            throw new LogicException("Exchange {$exchange} not found.");
        }

        if (!$this->exchange) {
            $exchange = self::EXCHANGES[$exchange];
            $this->exchange = new $exchange($this->getApiKey(), $this->getApiSecret());
        }

        return $this->exchange;
    }

    /**
     * Get API key
     *
     * @return string
     */
    public function getApiKey(): string
    {
        return $this->collection->getApiKey();
    }

    /**
     * Get API secret
     *
     * @return string
     */
    public function getApiSecret(): string
    {
        return $this->collection->getApiSecret();
    }

    /**
     * Get status
     *
     * @return string
     */
    public function getStatus(): string
    {
        return $this->collection->status;
    }

    /**
     * Get state
     *
     * @return string
     */
    public function getState(): string
    {
        return $this->collection->state;
    }

    /**
     * Get config
     *
     * @return array
     */
    public function getConfig(): array
    {
        return json_decode($this->collection->config, true);
    }

    /**
     * Get description
     *
     * @return string
     */
    public function getDescription(): string
    {
        return $this->collection->description;
    }

    /**
     * Get created at
     *
     * @return string
     */
    public function getCreatedAt(): string
    {
        return $this->collection->created_at;
    }

    /**
     * Get updated at
     *
     * @return string
     */
    public function getUpdatedAt(): string
    {
        return $this->collection->updated_at;
    }
}
