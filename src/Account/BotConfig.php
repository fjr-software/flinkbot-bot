<?php

declare(strict_types=1);

namespace FjrSoftware\Flinkbot\Bot\Account;

use FjrSoftware\Flinkbot\Indicator\Condition;
use FjrSoftware\Flinkbot\Indicator\MovingAverageSMA;
use FjrSoftware\Flinkbot\Indicator\StochasticRSI;

class BotConfig
{
    public const ALLOWED_INDICATORS = [
        'MovingAverageSMA' => MovingAverageSMA::class,
        'StochasticRSI' => StochasticRSI::class,
    ];

    /**
     * @var float
     */
    private float $initialBalance = 0;

    /**
     * @var string
     */
    private string $operationSide = 'both';

    /**
     * @var string
     */
    private string $interval = '15m';

    /**
     * @var int
     */
    private int $orderTimeout = 60;

    /**
     * @var array
     */
    private array $margin = [];

    /**
     * @var array
     */
    private array $position = [];

    /**
     * @var array
     */
    private array $indicator = [];

    public function __construct(
        private readonly ?string $config = null
    ) {
        $this->load();
    }

    /**
     * Set initial balance
     *
     * @param float $initialBalance
     * @return BotConfig
     */
    public function setInitialBalance(float $initialBalance): BotConfig
    {
        $this->initialBalance = $initialBalance;

        return $this;
    }

    /**
     * Get initial balance
     *
     * @return float
     */
    public function getInitialBalance(): float
    {
        return $this->initialBalance;
    }

    /**
     * Set operation side
     *
     * @param string $operationSide
     * @return BotConfig
     */
    public function setOperationSide(string $operationSide): BotConfig
    {
        $this->operationSide = $operationSide;

        return $this;
    }

    /**
     * Get operation side
     *
     * @return string
     */
    public function getOperationSide(): string
    {
        return $this->operationSide;
    }

    /**
     * Set interval
     *
     * @param string $interval
     * @return BotConfig
     */
    public function setInterval(string $interval): BotConfig
    {
        $this->interval = $interval;

        return $this;
    }

    /**
     * Get interval
     *
     * @return string
     */
    public function getInterval(): string
    {
        return $this->interval;
    }

    /**
     * Set order timeout
     *
     * @param int $orderTimeout
     * @return BotConfig
     */
    public function setOrderTimeout(int $orderTimeout): BotConfig
    {
        $this->orderTimeout = $orderTimeout;

        return $this;
    }

    /**
     * Get order timeout
     *
     * @return int
     */
    public function getOrderTimeout(): int
    {
        return $this->orderTimeout;
    }

    /**
     * Set margin
     *
     * @param array $margin
     * @return BotConfig
     */
    public function setMargin(array $margin): BotConfig
    {
        $this->margin = $margin;

        return $this;
    }

    /**
     * Get margin
     *
     * @return array
     */
    public function getMargin(): array
    {
        return $this->margin;
    }

    /**
     * Set position
     *
     * @param array $position
     * @return BotConfig
     */
    public function setPosition(array $position): BotConfig
    {
        $this->position = $position;

        return $this;
    }

    /**
     * Get position
     *
     * @return array
     */
    public function getPosition(): array
    {
        return $this->position;
    }

    /**
     * Set indicator
     *
     * @param array $indicator
     * @return BotConfig
     */
    public function setIndicator(array $indicator): BotConfig
    {
        $this->indicator = $indicator;

        return $this;
    }

    /**
     * Get indicator
     *
     * @param array $values
     * @return array
     */
    public function getIndicator(array $values): array
    {
        $result = [];

        foreach ($this->indicator['indicators'] as $indicator => $config) {
            $indicatorClass = self::ALLOWED_INDICATORS[$indicator];

            foreach ($config as $params) {
                $result[$indicator][] = new $indicatorClass($values, ...$params);
            }
        }

        $resultConditions = [];

        foreach ($result as $indicator => $list) {
            $conditionsLong = $this->indicator['conditions']['long'][$indicator];
            $conditionsShort = $this->indicator['conditions']['short'][$indicator];
            $countLong = count($conditionsLong);
            $countShort = count($conditionsLong);

            foreach ($conditionsLong as $condition) {
                $value = $this->getValue((string) $condition['condition']['value'], $list);
                $operator = $condition['condition']['operator'];
                $resultConditions['long'][$indicator] = (new Condition($list, $operator, (float) $value))->isSatisfied();

                if ($countLong === 1) {
                    break;
                }
            }

            foreach ($conditionsShort as $condition) {
                $value = $this->getValue((string) $condition['condition']['value'], $list);
                $operator = $condition['condition']['operator'];
                $resultConditions['short'][$indicator] = (new Condition($list, $operator, (float) $value))->isSatisfied();

                if ($countShort === 1) {
                    break;
                }
            }
        }

        switch ($this->indicator['conditions']['when']) {
            case 'any':
                foreach ($resultConditions as &$indicator) {
                    if (in_array(true, $indicator, true)) {
                        $indicator['enable_trade'] = true;
                    }

                    if (!isset($indicator['enable_trade'])) {
                        $indicator['enable_trade'] = false;
                    }
                }

                unset($indicator);
                break;
            case 'only':
                foreach ($resultConditions as &$indicator) {
                    $valid = array_filter($indicator, fn($value) => $value);
                    $indicator['enable_trade'] = count($valid) === 1;
                }

                unset($indicator);
                break;
            case 'all':
                foreach ($resultConditions as &$indicator) {
                    if (in_array(false, $indicator, true)) {
                        $indicator['enable_trade'] = false;
                    }

                    if (!isset($indicator['enable_trade'])) {
                        $indicator['enable_trade'] = true;
                    }
                }

                unset($indicator);
                break;
        }

        return $result + $resultConditions;
    }

    /**
     * Convert to string
     *
     * @return string
     */
    public function __toString(): string
    {
        return json_encode(get_object_vars($this));
    }

    /**
     * Get value based on syntax
     *
     * @param string $value
     * @param IndicatorInterface[] $indicators
     * @return mixed
     */
    private function getValue(string $value, array $indicators): mixed
    {
        if ($value === '@SYMBOL_PRICE') {
            return $indicators[0]->getSymbolPrice();
        }

        return $value;
    }

    /**
     * Load config
     *
     * @return void
     */
    private function load(): void
    {
        if ($config = json_decode($this->config, true)) {
            $this->setInitialBalance($config['initialBalance'] ?? 0);
            $this->setOperationSide($config['operationSide'] ?? 'both');
            $this->setInterval($config['interval'] ?? '15m');
            $this->setOrderTimeout($config['orderTimeout'] ?? 60);
            $this->setMargin($config['margin'] ?? []);
            $this->setPosition($config['position'] ?? []);
            $this->setIndicator($config['indicator'] ?? []);
        }
    }
}
