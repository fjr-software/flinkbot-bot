<?php

declare(strict_types=1);

namespace FjrSoftware\Flinkbot\Bot\Account;

use LogicException;
use FjrSoftware\Flinkbot\Bot\Exchange\ExchangeOptions;
use FjrSoftware\Flinkbot\Bot\Exchange\Manager;
use FjrSoftware\Flinkbot\Bot\Model\Bots;
use FjrSoftware\Flinkbot\Exchange\ExchangeInterface;
use Illuminate\Database\Eloquent\Collection;

class Bot
{
    /**
     * @var Manager|null
     */
    private ?Manager $exchangeManager = null;

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
        $this->collection = Bots::where(['id' => $this->botId])->get();
        $this->init();
    }

    /**
     * Initialize
     *
     * @return void
     */
    private function init(): void
    {
        $exchange = strtoupper($this->getData()->exchange);

        if (!key_exists($exchange, array_column(ExchangeOptions::cases(), 'name'))) {
            throw new LogicException("Exchange {$exchange} not found.");
        }

        if (!$this->exchangeManager) {
            $exchange = ExchangeOptions::from($exchange);
            $this->exchangeManager = new Manager($exchange, $this->getApiKey(), $this->getApiSecret());
        }
    }

    /**
     * Get bot id
     *
     * @return int
     */
    public function getId(): int
    {
        return $this->getData()->id;
    }

    /**
     * Get user id
     *
     * @return int
     */
    public function getUserId(): int
    {
        return $this->getData()->user_id;
    }

    /**
     * Get name
     *
     * @return string
     */
    public function getName(): string
    {
        return $this->getData()->name;
    }

    /**
     * Get exchange manager
     *
     * @return Manager|null
     * @throws LogicException
     */
    public function getExchangeManager(): ?Manager
    {
        return $this->exchangeManager;
    }

    /**
     * Get exchange
     *
     * @return ExchangeInterface|null
     */
    public function getExchange(): ?ExchangeInterface
    {
        return $this->exchangeManager?->getExchange();
    }

    /**
     * Get API key
     *
     * @return string
     */
    public function getApiKey(): string
    {
        return $this->getData()->getApiKey();
    }

    /**
     * Get API secret
     *
     * @return string
     */
    public function getApiSecret(): string
    {
        return $this->getData()->getApiSecret();
    }

    /**
     * Get status
     *
     * @return string
     */
    public function getStatus(): string
    {
        return $this->getData()->status;
    }

    /**
     * Get state
     *
     * @return string
     */
    public function getState(): string
    {
        return $this->getData()->state;
    }

    /**
     * Get config
     *
     * @return BotConfig
     */
    public function getConfig(): BotConfig
    {
        return (new BotConfig($this->getData()->config));
    }

    /**
     * Get description
     *
     * @return string
     */
    public function getDescription(): string
    {
        return $this->getData()->description;
    }

    /**
     * Get created at
     *
     * @return string
     */
    public function getCreatedAt(): string
    {
        return $this->getData()->created_at;
    }

    /**
     * Get updated at
     *
     * @return string
     */
    public function getUpdatedAt(): string
    {
        return $this->getData()->updated_at;
    }

    /**
     * Get data
     *
     * @return object
     * @throws LogicException
     */
    private function getData(): object
    {
        $data = $this->collection->first();

        if (!$data) {
            throw new LogicException("Bot {$this->botId} not found.");
        }

        return $data;
    }
}
