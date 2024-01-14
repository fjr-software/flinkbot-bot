<?php

declare(strict_types=1);

namespace FjrSoftware\Flinkbot\Bot\Account;

use FjrSoftware\Flinkbot\Indicator\Aroon;
use FjrSoftware\Flinkbot\Indicator\Condition;
use FjrSoftware\Flinkbot\Indicator\MovingAverageEMA;
use FjrSoftware\Flinkbot\Indicator\MovingAverageSMA;
use FjrSoftware\Flinkbot\Indicator\StochasticRSI;
use FjrSoftware\Flinkbot\Indicator\IndicatorInterface;
use FjrSoftware\Flinkbot\Indicator\Support;
use FjrSoftware\Flinkbot\Indicator\Resistance;

class BotConfig
{
    public const ALLOWED_INDICATORS = [
        'MovingAverageEMA' => MovingAverageEMA::class,
        'MovingAverageSMA' => MovingAverageSMA::class,
        'StochasticRSI' => StochasticRSI::class,
        'Aroon' => Aroon::class,
        'Support' => Support::class,
        'Resistance' => Resistance::class,
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
    private string $prioritySideIndicator = 'short';

    /**
     * @var string
     */
    private string $interval = '15m';

    /**
     * @var int
     */
    private int $orderCommonTimeout = 60;

    /**
     * @var int
     */
    private int $orderTriggerTimeout = 60;

    /**
     * @var int
     */
    private int $orderLongTriggerTimeout = 3600;

    /**
     * @var bool
     */
    private bool $enableHalfPriceProtection = false;

    /**
     * @var float
     */
    private float $incrementTriggerPercentage = 0.0;

    /**
     * @var int
     */
    private int $multiplierIncrementTrigger = 1;

    /**
     * @var int
     */
    private int $averagePriceOrderCount = 0;

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
     * Set priority side indicator
     *
     * @param string $prioritySideIndicator
     * @return BotConfig
     */
    public function setPrioritySideIndicator(string $prioritySideIndicator): BotConfig
    {
        $this->prioritySideIndicator = $prioritySideIndicator;

        return $this;
    }

    /**
     * Get priority side indicator
     *
     * @return string
     */
    public function getPrioritySideIndicator(): string
    {
        return $this->prioritySideIndicator;
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
     * Set order common timeout
     *
     * @param int $orderCommonTimeout
     * @return BotConfig
     */
    public function setOrderCommonTimeout(int $orderCommonTimeout): BotConfig
    {
        $this->orderCommonTimeout = $orderCommonTimeout;

        return $this;
    }

    /**
     * Get order common timeout
     *
     * @return int
     */
    public function getOrderCommonTimeout(): int
    {
        return $this->orderCommonTimeout;
    }

    /**
     * Set order trigger timeout
     *
     * @param int $orderTriggerTimeout
     * @return BotConfig
     */
    public function setOrderTriggerTimeout(int $orderTriggerTimeout): BotConfig
    {
        $this->orderTriggerTimeout = $orderTriggerTimeout;

        return $this;
    }

    /**
     * Get order trigger timeout
     *
     * @return int
     */
    public function getOrderTriggerTimeout(): int
    {
        return $this->orderTriggerTimeout;
    }

    /**
     * Set order long trigger timeout
     *
     * @param int $orderLongTriggerTimeout
     * @return BotConfig
     */
    public function setOrderLongTriggerTimeout(int $orderLongTriggerTimeout): BotConfig
    {
        $this->orderLongTriggerTimeout = $orderLongTriggerTimeout;

        return $this;
    }

    /**
     * Get order long trigger timeout
     *
     * @return int
     */
    public function getOrderLongTriggerTimeout(): int
    {
        return $this->orderLongTriggerTimeout;
    }

    /**
     * Set enable half price protection
     *
     * @param bool $enableHalfPriceProtection
     * @return BotConfig
     */
    public function setEnableHalfPriceProtection(bool $enableHalfPriceProtection): BotConfig
    {
        $this->enableHalfPriceProtection = $enableHalfPriceProtection;

        return $this;
    }

    /**
     * Get enable half price protection
     *
     * @return bool
     */
    public function getEnableHalfPriceProtection(): bool
    {
        return $this->enableHalfPriceProtection;
    }

    /**
     * Set increment trigger percentage
     *
     * @param float $incrementTriggerPercentage
     * @return BotConfig
     */
    public function setIncrementTriggerPercentage(float $incrementTriggerPercentage): BotConfig
    {
        $this->incrementTriggerPercentage = $incrementTriggerPercentage;

        return $this;
    }

    /**
     * Get increment trigger percentage
     *
     * @return float
     */
    public function getIncrementTriggerPercentage(): float
    {
        return $this->incrementTriggerPercentage;
    }

    /**
     * Set multiplier increment trigger
     *
     * @param int $multiplierIncrementTrigger
     * @return BotConfig
     */
    private function setMultiplierIncrementTrigger(int $multiplierIncrementTrigger): BotConfig
    {
        $this->multiplierIncrementTrigger = $multiplierIncrementTrigger;

        return $this;
    }

    /**
     * Get multiplier increment trigger
     *
     * @return int
     */
    public function getMultiplierIncrementTrigger(): int
    {
        return $this->multiplierIncrementTrigger;
    }

    /**
     * Set average price order count
     *
     * @param int $averagePriceOrderCount
     * @return BotConfig
     */
    public function setAveragePriceOrderCount(int $averagePriceOrderCount): BotConfig
    {
        $this->averagePriceOrderCount = $averagePriceOrderCount;

        return $this;
    }

    /**
     * Get average price order count
     *
     * @return int
     */
    public function getAveragePriceOrderCount(): int
    {
        return $this->averagePriceOrderCount;
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
     * @param float $currentValue
     * @return array
     */
    public function getIndicator(array $values, float $currentValue = 0): array
    {
        $result = [];

        foreach ($this->indicator['indicators'] as $indicator => $config) {
            $indicatorClass = self::ALLOWED_INDICATORS[$indicator];

            if (!$config) {
                $instance = new $indicatorClass($values);

                $instance->setSymbolPrice($currentValue);

                $result[$indicator][] = $instance;
            } else {
                foreach ($config as $params) {
                    $instance = new $indicatorClass($values, ...$params);

                    $instance->setSymbolPrice($currentValue);

                    $result[$indicator][] = $instance;
                }
            }
        }

        $resultConditions = [];

        foreach ($result as $indicator => $list) {
            $conditionsLong = $this->indicator['conditions']['long'][$indicator] ?? [];
            $conditionsShort = $this->indicator['conditions']['short'][$indicator] ?? [];
            $countLong = count($conditionsLong);
            $countShort = count($conditionsLong);

            foreach ($conditionsLong as $conditionKey => $condition) {
                $indicatorList = $countLong === 1 ? $list : $list[$conditionKey];

                if (is_array($condition['condition']['value'])) {
                    $value = $this->handleValues($condition['condition']['value'], $list);
                } else {
                    $value = $this->getValue((string) $condition['condition']['value'], $list);
                }

                $operator = $condition['condition']['operator'];
                $resultConditions['long'][$indicator][] = (new Condition($indicatorList, $operator, $value))->isSatisfied();
            }

            if (isset($resultConditions['long'][$indicator])) {
                $resultConditions['long'][$indicator] = !in_array(false, $resultConditions['long'][$indicator], true);
            }

            foreach ($conditionsShort as $conditionKey => $condition) {
                $indicatorList = $countShort === 1 ? $list : $list[$conditionKey];

                if (is_array($condition['condition']['value'])) {
                    $value = $this->handleValues($condition['condition']['value'], $list);
                } else {
                    $value = $this->getValue((string) $condition['condition']['value'], $list);
                }

                $operator = $condition['condition']['operator'];
                $resultConditions['short'][$indicator][] = (new Condition($indicatorList, $operator, $value))->isSatisfied();
            }

            if (isset($resultConditions['short'][$indicator])) {
                $resultConditions['short'][$indicator] = !in_array(false, $resultConditions['short'][$indicator], true);
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
     * Handle values
     *
     * @param array $values
     * @param array $list
     */
    private function handleValues(array $values, array $list): array
    {
        $result = [];

        foreach ($values as $value) {
            $result[] = $this->getValue((string) $value, $list);
        }

        return $result;
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
        if (!preg_match('/^@/', $value)) {
            return (float) $value;
        }

        if ($value === '@SYMBOL_PRICE') {
            return $indicators[0]->getSymbolPrice();
        }

        if (preg_match('/@SYMBOL_PRICE@ADD_PERC_(?<value>[0-9\.]+)/i', $value, $match)) {
            $value = $indicators[0]->getSymbolPrice();
            $valueAdd = (float) ($match['value'] ?? 0) / 100;
            $valueAdd = $value * $valueAdd;
            $value += $valueAdd;

            return (float) $value;
        }

        if (preg_match('/@SYMBOL_PRICE@SUB_PERC_(?<value>[0-9\.]+)/i', $value, $match)) {
            $value = $indicators[0]->getSymbolPrice();
            $valueSub = (float) ($match['value'] ?? 0) / 100;
            $valueSub = $value * $valueSub;
            $value -= $valueSub;

            return (float) $value;
        }

        if (preg_match('/@INDICATOR\_(?<indicator>[a-z]+)\_(?<ord>[0-9\.]+)\_(?<value>[0-9\.]+)/i', $value, $match)) {
            if (isset($indicators[$match['indicator']], $val[$match['ord']])) {
                if (isset($indicators[$match['ord']]->getValue()[$match['value']])) {
                    return $indicators[$match['ord']]->getValue()[$match['value']];
                }
            }
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
            $this->setPrioritySideIndicator($config['prioritySideIndicator'] ?? 'short');
            $this->setInterval($config['interval'] ?? '15m');
            $this->setOrderCommonTimeout($config['orderCommonTimeout'] ?? 60);
            $this->setOrderTriggerTimeout($config['orderTriggerTimeout'] ?? 60);
            $this->setOrderLongTriggerTimeout($config['orderLongTriggerTimeout'] ?? 3600);
            $this->setEnableHalfPriceProtection($config['enableHalfPriceProtection'] ?? false);
            $this->setIncrementTriggerPercentage($config['incrementTriggerPercentage'] ?? 0.0);
            $this->setMultiplierIncrementTrigger($config['multiplierIncrementTrigger'] ?? 1);
            $this->setAveragePriceOrderCount($config['averagePriceOrderCount'] ?? 0);
            $this->setMargin($config['margin'] ?? []);
            $this->setPosition($config['position'] ?? []);
            $this->setIndicator($config['indicator'] ?? []);
        }
    }
}
