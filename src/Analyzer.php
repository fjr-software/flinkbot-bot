<?php

declare(strict_types=1);

namespace FjrSoftware\Flinkbot\Bot;

use DateTime;
use Exception;
use UnexpectedValueException;
use FjrSoftware\Flinkbot\Bot\Account\Bot;
use FjrSoftware\Flinkbot\Bot\Account\Log;
use FjrSoftware\Flinkbot\Bot\Account\LogLevel;
use FjrSoftware\Flinkbot\Bot\Account\Position;
use FjrSoftware\Flinkbot\Bot\Model\Symbols;
use FjrSoftware\Flinkbot\Bot\Model\Orders;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Stream\ReadableResourceStream;

class Analyzer
{
    /**
     * @const int
     * Default result status.
     */
    public const RESULT_DEFAULT = 0;

    /**
     * @const int
     * Indicates successful operation.
     */
    public const RESULT_SUCCESS = 1;

    /**
     * @const int
     * Indicates a need for restart.
     */
    public const RESULT_RESTART = 2;

    /**
     * @const int
     * Indicates operation was closed.
     */
    public const RESULT_CLOSED = 3;

    /**
     * @const int
     * Indicates a timeout occurred.
     */
    public const RESULT_TIMEOUT = 4;

    /**
     * @const int
     * Indicates a closed operation due to timeout.
     */
    public const RESULT_CLOSED_TIMEOUT = 5;

    /**
     * @var int
     */
    private int $exitCode = self::RESULT_RESTART;

    /**
     * @var ReadableResourceStream|null
     */
    private ?ReadableResourceStream $stream = null;

    /**
     * @var Bot
     */
    private Bot $bot;

    /**
     * @var Position
     */
    private Position $position;

    /**
     * @var Log
     */
    private Log $log;

    /**
     * @var LoopInterfacee|null
     */
    private ?LoopInterface $loop = null;

    /**
     * Constructor
     *
     * @param int $botId
     * @param string $host
     */
    public function __construct(
        private readonly int $botId,
        private readonly string $host
    ) {
        $this->bot = new Bot($this->botId, $this->host);
        $this->position = new Position($this->bot);
        $this->log = new Log($botId);
        $this->loop = Loop::get();

        $this->start();
    }

    /**
     * Destructor
     */
    public function __destruct()
    {
        echo "Finished - " . date('Y-m-d H:i:s') . "\n";
    }

    /**
     * Run
     *
     * @param string $symbol
     * @return void
     */
    public function run(string $symbol): void
    {
        $this->loop->futureTick(function () use ($symbol) {
            $this->runAnalyzer($symbol);
            $this->exit();
        });

        $this->loop->run();
    }

    /**
     * Run analyzer
     *
     * @param string $symbol
     * @return void
     */
    private function runAnalyzer(string $symbol): void
    {
        try {
            $this->position->execute($symbol);
            $this->updateOrder($symbol);

            $candles = $this->bot->getExchange()->getCandles($symbol, $this->bot->getConfig()->getInterval(), 100);
            $highs = $this->bot->getExchange()->getHighPrice($candles);
            $lows = $this->bot->getExchange()->getLowPrice($candles);
            $closes = $this->bot->getExchange()->getClosePrice($candles);
            $current = $this->bot->getExchange()->getCurrentValue($candles, 'close');

            $indicators = $this->bot->getConfig()->getIndicator([$highs, $lows, $closes], $current);
            $debugValues = ['Current: ' . $current];
            $priceTakeIndicator = 0;
            $priceStopIndicator = 0;
            $side = '';

            foreach ($indicators as $ind => $val) {
                if (!in_array($ind, ['long', 'short'])) {
                    if ($this->bot->getConfig()->getPosition()['enableTakeIndicator']) {
                        if (preg_match('/(?<indicator>[a-z]+)@(?<ord>[0-9\.]+)\_(?<value>[0-9\.]+)/i', $this->bot->getConfig()->getPosition()['takeIndicator'], $match)) {
                            if ($ind === $match['indicator'] && isset($val[$match['ord']])) {
                                if (isset($val[$match['ord']]->getValue()[$match['value']])) {
                                    $priceTakeIndicator = (float) $val[$match['ord']]->getValue()[$match['value']];
                                }
                            }
                        }
                    }

                    if ($this->bot->getConfig()->getPosition()['enableStopIndicator']) {
                        if (preg_match('/(?<indicator>[a-z]+)@(?<ord>[0-9\.]+)\_(?<value>[0-9\.]+)/i', $this->bot->getConfig()->getPosition()['stopIndicator'], $match)) {
                            if ($ind === $match['indicator'] && isset($val[$match['ord']])) {
                                if (isset($val[$match['ord']]->getValue()[$match['value']])) {
                                    $priceStopIndicator = (float) $val[$match['ord']]->getValue()[$match['value']];
                                }
                            }
                        }
                    }

                    $sideInd = ($indicators['long'][$ind] ?? false) ? 'LONG' : '';
                    $sideInd = ($indicators['short'][$ind] ?? false) ? 'SHORT' : $sideInd;
                    $debugValues[] = $ind . ':'. $sideInd;

                    foreach ($val as $indTmp) {
                        $debugValues[] = implode(' - ', $indTmp->getValue());
                    }
                }
            }

            if ($indicators['long']['enable_trade']) {
                $side = 'LONG';
            }

            if ($indicators['short']['enable_trade']) {
                $side = 'SHORT';
            }

            if ($indicators['long']['enable_trade'] && $indicators['short']['enable_trade']) {
                $prioritySideIndicator = strtoupper($this->bot->getConfig()->getPrioritySideIndicator());

                if (in_array($prioritySideIndicator, ['LONG', 'SHORT'])) {
                    $side = $prioritySideIndicator;
                } else {
                    if ($indicators['short'][$this->bot->getConfig()->getPrioritySideIndicator()] ?? false) {
                        $side = 'SHORT';
                    }

                    if ($indicators['long'][$this->bot->getConfig()->getPrioritySideIndicator()] ?? false) {
                        $side = 'LONG';
                    }
                }
            }

            if ($this->bot->enableDebug()) {
                $message = implode(' - ', $debugValues) . " - Side: {$side}";

                $this->log->register(LogLevel::LEVEL_DEBUG, $message);

                echo "{$message}\n";
            }

            $book = $this->bot->getExchange()->getBook($symbol);
            $bookBuy = $book['bids'][0];
            $bookSell = $book['asks'][0];
            $openOrders = $this->bot->getExchange()->getOpenOrders($symbol);
            $openOrdersPartial = array_filter($openOrders, fn($order) => $order['reduceOnly'] && !$order['closePosition']);
            $openOrdersClosed = array_filter($openOrders, fn($order) => $order['reduceOnly'] && $order['closePosition']);
            $openOrders = array_filter($openOrders, fn($order) => !$order['reduceOnly']);
            $canGainLoss = false;
            $hasPosition = [
                'LONG' => false,
                'SHORT' => false
            ];
            $marginAccountPercent = 0;
            $marginSymbol = [
                'LONG' => 0,
                'SHORT' => 0
            ];
            $collateral = [
                'LONG' => null,
                'SHORT' => null
            ];
            $positions = $this->position->get($symbol);
            $configPosition = $this->bot->getConfig()->getPosition();
            $percentageFrom = (float) ($configPosition['triggerPreventOnGain']['percentage_from'] ?? 0 / 100);
            $percentageTo = (float) ($configPosition['triggerPreventOnGain']['percentage_to'] ?? 0 / 100);
            $checkCollateralForProfitClosure = (bool) ($this->bot->getConfig()->getPosition()['checkCollateralForProfitClosure'] ?? false);
            $collateralCheckDisableThreshold = (float) ($this->bot->getConfig()->getPosition()['collateralCheckDisableThreshold'] ?? 0);
            $collateralCheckDisableThreshold /= 100;

            if ($checkCollateralForProfitClosure) {
                foreach ($positions as $position) {
                    if ($position->status === 'open') {
                        $collateralSide = $position->side === 'SHORT' ? 'LONG' : 'SHORT';
                        $collateral[$collateralSide] = $position;
                    }
                }
            }

            $hasOrders = [
                'LONG' => [
                    'gain' => false,
                    'loss' => false
                ],
                'SHORT' => [
                    'gain' => false,
                    'loss' => false
                ]
            ];

            foreach ($openOrdersClosed as $openOrder) {
                if ($openOrder['origType'] === 'TAKE_PROFIT_MARKET') {
                    $hasOrders[$openOrder['positionSide']]['gain'] = true;
                }

                if ($openOrder['origType'] === 'STOP_MARKET') {
                    $hasOrders[$openOrder['positionSide']]['loss'] = true;
                }
            }

            $pricesClosedPosition = [
                'LONG' => [
                    'gain' => 0,
                    'loss' => 0,
                    'partial' => [
                        'take' => [
                            'price' => 0,
                            'qty' => 0
                        ],
                        'stop' => [
                            'price' => 0,
                            'qty' => 0
                        ]
                    ]
                ],
                'SHORT' => [
                    'gain' => 0,
                    'loss' => 0,
                    'partial' => [
                        'take' => [
                            'price' => 0,
                            'qty' => 0
                        ],
                        'stop' => [
                            'price' => 0,
                            'qty' => 0
                        ]
                    ]
                ]
            ];
            $marginSymbolQty = [
                'LONG' => 0,
                'SHORT' => 0
            ];

            $canTradeCurrentCycle = false;

            if ($configPosition['triggerTradeCurrentCycle']['enabled'] ?? false) {
                if ($currentCycle = $this->position->getCurrentCycle()) {
                    $canTradeCurrentCycle = (bool) $currentCycle->done;
                    $side = $canTradeCurrentCycle ? '' : $side;
                }
            }

            foreach ($positions as $position) {
                $marginAccountPercent = $position->margin_account_percent;
                $marginSymbolQty[$position->side] = $position->size;
                $marginSymbol[$position->side] = [
                    'usage' => $position->margin_symbol_percent,
                    'limit' => $position->symbol->max_margin
                ];

                $canPositionTrade = $side === $position->side;
                $canPositionGain = $configPosition['profit'] > 0
                    && $position->pnl_roi_percent >= $configPosition['profit']
                    && $position->pnl_roi_value >= $configPosition['minimumGain'];
                $canPositionLoss = $configPosition['loss'] > 0
                    && $position->pnl_roi_value < 0
                    && abs((float) $position->pnl_roi_percent) >= $configPosition['loss']
                    && abs((float) $position->pnl_roi_value) >= $configPosition['minimumLoss'];

                $canActivateTrigger = ($configPosition['valueActivateGainTrigger'] > 0
                        && $position->pnl_roi_value >= $configPosition['valueActivateGainTrigger']
                    )
                    || (
                        $position->pnl_roi_value < 0 && $configPosition['valueActivateLossTrigger'] > 0
                        && abs((float) $position->pnl_roi_value) >= $configPosition['valueActivateLossTrigger']
                    );

                if (!$canActivateTrigger) {
                    $canActivateTrigger = $configPosition['triggerAccountOnGain']['enabled']
                        && $position->pnl_account_percent >= $configPosition['triggerAccountOnGain']['percentage']
                        && $position->pnl_roi_value > 0;
                }

                $canMaximumTime = false;

                if ($position->status === 'open' && $configPosition['maximumTime'] > 0 && ($openAt = (string) $position?->open_at)) {
                    $canMaximumTime = $position->pnl_roi_value < 0
                        && !$canPositionTrade
                        && abs((float) $position->pnl_roi_value) >= $configPosition['minimumLoss']
                        && $this->bot->getExchange()->timePosition($openAt) >= $configPosition['maximumTime'];
                }

                $canPrevent = $configPosition['profit'] > 0
                    && !$canPositionGain && !$canPositionLoss && !$canActivateTrigger && !$canMaximumTime && !$canTradeCurrentCycle
                    && $configPosition['triggerPreventOnGain']['enabled']
                    && $position->pnl_roi_percent >= ($configPosition['profit'] * $percentageFrom)
                    && $position->pnl_roi_percent <= ($configPosition['profit'] * $percentageTo)
                    && $position->pnl_roi_value >= $configPosition['minimumGain'];

                if ($position->status === 'open') {
                    $hasPosition[$position->side] = true;

                    if ($checkCollateralForProfitClosure && ($canPositionGain || $canActivateTrigger || $canPrevent) && $collateralPosition = $collateral[$position->side]) {
                        $requiredValueCollateral = abs((float) ($collateralPosition->pnl_roi_value) * $collateralCheckDisableThreshold);
                        $canCollateral = ($collateralPosition->pnl_roi_percent <= ($configPosition['profit'] * -1)
                            && $position->pnl_roi_value < $requiredValueCollateral
                            && $collateralPosition->pnl_roi_value <= ($configPosition['minimumGain'] * -1)
                        );

                        if ($canCollateral) {
                            $canPositionGain = false;
                            $canPrevent = false;

                            if ($this->bot->enableDebug()) {
                                $message = "Closing blocked by collateral position[{$position->side}] - ROI: {$position->pnl_roi_value} < {$requiredValueCollateral}";

                                $this->log->register(LogLevel::LEVEL_DEBUG, $message);

                                echo "{$message}\n";
                            }
                        }
                    }

                    $canAnalyzer = [
                        $canPrevent,
                        $canPositionGain,
                        $canPositionLoss,
                        $canActivateTrigger,
                        $canMaximumTime,
                        $canTradeCurrentCycle,
                    ];

                    if (in_array(true, $canAnalyzer, true)) {
                        $staticsTicker = $this->bot->getExchange()->getStaticsTicker($symbol);
                        $priceChangePercent = abs($this->bot->getExchange()->percentage((float) $staticsTicker['highPrice'], (float) $staticsTicker['lowPrice']));
                        $factorVolatility = floor(($priceChangePercent / ($configPosition['profit'] / $position->leverage)) / 2.5);
                        $multipleTrigger = $canPositionTrade ? $this->bot->getConfig()->getMultiplierIncrementTrigger() : 1;
                        $multipleTrigger += $factorVolatility;
                        $incrementTriggerPercentage = $this->bot->getConfig()->getIncrementTriggerPercentage() * $multipleTrigger;

                        $markPrice = (float) ($staticsTicker['lastPrice'] ?? 0);
                        $markPrice = (float) ($markPrice ?? $position->mark_price);
                        $entryPrice = (float) $position->entry_price;
                        $breakEvenPrice = (float) $position->break_even_price;

                        if ($this->bot->getConfig()->getPosition()['useBreakEvenPoint'] && $breakEvenPrice > 0
                            && (
                                $position->side === 'LONG' && $breakEvenPrice > $entryPrice
                                || $position->side === 'SHORT' && $breakEvenPrice < $entryPrice
                            )
                        ) {
                            $entryPrice = $breakEvenPrice;
                        }

                        $diffPrice = $this->bot->getExchange()->calculeProfit($markPrice, $incrementTriggerPercentage);
                        $priceCloseGain = (float) ($position->side === 'SHORT' ? $markPrice - $diffPrice : $markPrice + $diffPrice);

                        $diffPriceLoss = $this->bot->getExchange()->calculeProfit($markPrice, $this->bot->getConfig()->getIncrementTriggerPercentage());
                        $priceCloseStopGain = (float) ($position->side === 'SHORT' ? $markPrice + $diffPriceLoss : $markPrice - $diffPriceLoss);

                        if ($this->bot->getConfig()->getEnableHalfPriceProtection()) {
                            $priceCloseStopGain = ($priceCloseStopGain + $entryPrice) / 2;
                        }

                        $sideOrder = $position->side === 'SHORT' ? 'BUY' : 'SELL';
                        $typeClosed = $canPositionGain ? 'profit' : 'loss';

                        $openOrdersClosed = array_filter($openOrdersClosed, fn($order) => $order['side'] === $sideOrder);
                        $priceCloseGain = $this->bot->getExchange()->formatDecimal($markPrice, $priceCloseGain);
                        $priceCloseStopGain = $this->bot->getExchange()->formatDecimal($markPrice, $priceCloseStopGain);
                        $canTakeIndicator = $priceTakeIndicator && (
                            $position->side === 'LONG' && $markPrice < $priceTakeIndicator
                            || $position->side === 'SHORT' && $markPrice > $priceTakeIndicator
                        );
                        $canStopIndicator = $priceStopIndicator && (
                            $position->side === 'LONG' && $markPrice > $priceStopIndicator
                            || $position->side === 'SHORT' && $markPrice < $priceStopIndicator
                        );
                        $canGainLoss = true;
                        $qtyPartial = null;

                        if ($canTakeIndicator && (
                            $position->side === 'LONG' && $priceTakeIndicator > $entryPrice
                            || $position->side === 'SHORT' && $priceTakeIndicator < $entryPrice
                        )) {
                            $priceCloseGain = $this->bot->getExchange()->formatDecimal($markPrice, $priceTakeIndicator);
                        }

                        if ($canStopIndicator && (
                            $position->side === 'LONG' && $priceStopIndicator < $entryPrice
                            || $position->side === 'SHORT' && $priceStopIndicator > $entryPrice
                        )) {
                            $priceReal = $priceStopIndicator;
                            $priceReal = $position->side === 'LONG' && $markPrice < $priceStopIndicator ? $markPrice : $priceReal;
                            $priceReal = $position->side === 'SHORT' && $markPrice > $priceStopIndicator ? $markPrice : $priceReal;
                            $priceCloseStopGain = $this->bot->getExchange()->formatDecimal($markPrice, $priceReal);
                        }

                        if ($canPrevent) {
                            $canGainLoss = $position->pnl_roi_percent >= ($configPosition['profit'] / 2);
                            $diffPrice = $this->bot->getExchange()->calculeProfit(
                                $entryPrice,
                                (float) ($configPosition['profit'] / $position->leverage) + $incrementTriggerPercentage
                            );
                            $avgEntryMarkGain = ($entryPrice + $markPrice) / 2;
                            $priceCloseGain = (float) ($position->side === 'SHORT' ? $avgEntryMarkGain - $diffPrice : $avgEntryMarkGain + $diffPrice);
                            $priceCloseGain = $this->bot->getExchange()->formatDecimal($markPrice, $priceCloseGain);
                            $typeClosed = 'prevent';
                        }

                        if ($canMaximumTime) {
                            $typeClosed = 'maximumTime';
                        }

                        if (!$canPrevent && $configPosition['partialOrderProfit']['enabled']
                            && $position->size > $position->symbol->min_quantity
                        ) {
                            if ($symbolExchange = $this->getSymbolExchange($position->symbol->pair)) {
                                $percPartial = (float) $configPosition['partialOrderProfit']['percentage'];
                                $qtyPartial = (float) ($position->size * ($percPartial / 100));
                                $qtyPartial = round($qtyPartial, (int) $symbolExchange['quantityPrecision']);
                                $qtyPartial = (float) ($qtyPartial < $position->symbol->min_quantity ? $position->symbol->min_quantity : $qtyPartial);

                                $diffPartialPrice = $this->bot->getExchange()->calculeProfit($markPrice, $this->bot->getConfig()->getIncrementTriggerPercentage());
                                $pricePartialCloseGain = (float) ($position->side === 'SHORT' ? $markPrice - $diffPartialPrice : $markPrice + $diffPartialPrice);
                                $pricePartialCloseGain = $this->bot->getExchange()->formatDecimal($markPrice, $pricePartialCloseGain);

                                $pricePartialCloseStopGain = $priceCloseStopGain;
                                $priceCloseStopGain = ($pricePartialCloseStopGain + $entryPrice) / 2;
                                $priceCloseStopGain = $this->bot->getExchange()->formatDecimal($markPrice, $priceCloseStopGain);
                            }
                        }

                        $pricesClosedPosition[$position->side] = [
                            'gain' => $priceCloseGain,
                            'loss' => $priceCloseStopGain,
                            'partial' => [
                                'take' => [
                                    'price' => $pricePartialCloseGain ?? 0,
                                    'qty' => $qtyPartial ?? 0
                                ],
                                'stop' => [
                                    'price' => $pricePartialCloseStopGain ?? 0,
                                    'qty' => $qtyPartial ?? 0
                                ]
                            ]
                        ];

                        if (!$hasOrders[$position->side]['gain']) {
                            if (!$canPrevent && $configPosition['partialOrderProfit']['enabled'] && isset($pricePartialCloseGain)) {
                                $this->closePosition($symbol, $position->side, $pricePartialCloseGain, false, $qtyPartial);

                                if ($this->bot->enableDebug()) {
                                    $percent = (float) $position->pnl_roi_percent;
                                    $message = "Partial-take {$percPartial}% order created[{$typeClosed}] - ROI: {$percent}%";

                                    $this->log->register(LogLevel::LEVEL_DEBUG, $message);

                                    echo "{$message}\n";
                                }
                            }

                            $this->closePosition($symbol, $position->side, $priceCloseGain);
                        }

                        if (!$hasOrders[$position->side]['loss']) {
                            if (!$canPrevent && $configPosition['partialOrderProfit']['enabled'] && isset($pricePartialCloseStopGain)) {
                                $this->closePosition($symbol, $position->side, $pricePartialCloseStopGain, true, $qtyPartial);

                                if ($this->bot->enableDebug()) {
                                    $percent = (float) $position->pnl_roi_percent;
                                    $message = "Partial-stop {$percPartial}% order created[{$typeClosed}] - ROI: {$percent}%";

                                    $this->log->register(LogLevel::LEVEL_DEBUG, $message);

                                    echo "{$message}\n";
                                }
                            }

                            $this->closePosition($symbol, $position->side, $priceCloseStopGain, true);
                        }

                        if ($this->bot->enableDebug()) {
                            $percent = (float) $position->pnl_roi_percent;
                            $statusForClosed = !$openOrdersClosed ? '' : ' - Renewed';
                            $message = "Close position[{$typeClosed}] - ROI: {$percent}%{$statusForClosed}";

                            $this->log->register(LogLevel::LEVEL_DEBUG, $message);

                            echo "{$message}\n";
                        }
                    }
                }
            }

            foreach ($openOrders as $openOrder) {
                if ($this->bot->getExchange()->isTimeBoxOrder($openOrder['time'], $this->bot->getConfig()->getOrderCommonTimeout())) {
                    $this->bot->getExchange()->cancelOrder($openOrder['symbol'], (string) $openOrder['orderId']);

                    if ($this->bot->enableDebug()) {
                        $message = 'Order timeout[common]';

                        $this->log->register(LogLevel::LEVEL_DEBUG, $message);

                        echo "{$message}\n";
                    }
                }
            }

            if ($canGainLoss) {
                if (($openOrder ?? false) && !$hasOrders[$openOrder['positionSide']]['loss']
                    && $this->bot->getExchange()->isTimeBoxOrder($openOrder['time'], $this->bot->getConfig()->getOrderTriggerTimeout())
                    && $openOrder['origType'] === 'TAKE_PROFIT_MARKET'
                    && $pricesClosedPosition[$openOrder['positionSide']]['gain']
                    && (
                        $openOrder['positionSide'] === 'SHORT' && $pricesClosedPosition[$openOrder['positionSide']]['gain'] < $openOrder['stopPrice']
                        || $openOrder['positionSide'] === 'LONG' && $pricesClosedPosition[$openOrder['positionSide']]['gain'] > $openOrder['stopPrice']
                    )
                ) {
                    $this->bot->getExchange()->cancelOrder($openOrder['symbol'], (string) $openOrder['orderId']);
                }

                $partialOrderTakeRenewed = false;
                $partialOrderStopRenewed = false;
                $openOrdersPartialIds = [
                    'take' => [],
                    'stop' => []
                ];
                foreach ($openOrdersPartial as $openOrder) {
                    $typePartial = $openOrder['origType'] === 'TAKE_PROFIT_MARKET' ? 'take' : 'stop';
                    $openOrdersPartialIds[$typePartial][] = $openOrder['orderId'];

                    if ($this->bot->getExchange()->isTimeBoxOrder($openOrder['time'], $this->bot->getConfig()->getOrderTriggerTimeout())) {
                        if (
                            $openOrder['origType'] === 'TAKE_PROFIT_MARKET'
                            && !empty($pricesClosedPosition[$openOrder['positionSide']]['partial']['take']['price'])
                            && !empty($pricesClosedPosition[$openOrder['positionSide']]['partial']['take']['qty'])
                            && (
                                $openOrder['positionSide'] === 'SHORT' && $pricesClosedPosition[$openOrder['positionSide']]['partial']['take']['price'] < $openOrder['stopPrice']
                                || $openOrder['positionSide'] === 'LONG' && $pricesClosedPosition[$openOrder['positionSide']]['partial']['take']['price'] > $openOrder['stopPrice']
                            )
                        ) {
                            $diffTrigger = abs($this->bot->getExchange()->percentage(
                                (float) $pricesClosedPosition[$openOrder['positionSide']]['partial']['take']['price'],
                                (float) $openOrder['stopPrice']
                            ));

                            if ($diffTrigger >= 0.10) {
                                if ($this->bot->enableDebug()) {
                                    $message = 'Order timeout[trigger] - partial';

                                    $this->log->register(LogLevel::LEVEL_DEBUG, $message);

                                    echo "{$message}\n";
                                }

                                $this->bot->getExchange()->cancelOrder($openOrder['symbol'], (string) $openOrder['orderId']);

                                $this->closePosition(
                                    $openOrder['symbol'],
                                    $openOrder['positionSide'],
                                    $pricesClosedPosition[$openOrder['positionSide']]['partial']['take']['price'],
                                    false,
                                    $pricesClosedPosition[$openOrder['positionSide']]['partial']['take']['qty']
                                );

                                $partialOrderTakeRenewed = true;
                            }
                        }

                        if (
                            $openOrder['origType'] === 'STOP_MARKET'
                            && !empty($pricesClosedPosition[$openOrder['positionSide']]['partial']['stop']['price'])
                            && !empty($pricesClosedPosition[$openOrder['positionSide']]['partial']['stop']['qty'])
                            && (
                                $openOrder['positionSide'] === 'SHORT' && $pricesClosedPosition[$openOrder['positionSide']]['partial']['stop']['price'] < $openOrder['stopPrice']
                                || $openOrder['positionSide'] === 'LONG' && $pricesClosedPosition[$openOrder['positionSide']]['partial']['stop']['price'] > $openOrder['stopPrice']
                            )
                        ) {
                            $diffTrigger = abs($this->bot->getExchange()->percentage(
                                (float) $pricesClosedPosition[$openOrder['positionSide']]['partial']['stop']['price'],
                                (float) $openOrder['stopPrice']
                            ));

                            if ($diffTrigger >= 0.10) {
                                if ($this->bot->enableDebug()) {
                                    $message = 'Order timeout[trigger] - partial';

                                    $this->log->register(LogLevel::LEVEL_DEBUG, $message);

                                    echo "{$message}\n";
                                }

                                $this->bot->getExchange()->cancelOrder($openOrder['symbol'], (string) $openOrder['orderId']);

                                $this->closePosition(
                                    $openOrder['symbol'],
                                    $openOrder['positionSide'],
                                    $pricesClosedPosition[$openOrder['positionSide']]['partial']['stop']['price'],
                                    true,
                                    $pricesClosedPosition[$openOrder['positionSide']]['partial']['stop']['qty']
                                );

                                $partialOrderStopRenewed = true;
                            }
                        }
                    }
                }

                foreach ($openOrdersClosed as $openOrder) {
                    if ($canPrevent && $openOrder['origType'] === 'STOP_MARKET') {
                        continue;
                    }

                    if ($this->bot->getExchange()->isTimeBoxOrder($openOrder['time'], $this->bot->getConfig()->getOrderTriggerTimeout())) {
                        if (
                            $openOrder['origType'] === 'TAKE_PROFIT_MARKET'
                            && $pricesClosedPosition[$openOrder['positionSide']]['gain']
                            && (
                                $openOrder['positionSide'] === 'SHORT' && $pricesClosedPosition[$openOrder['positionSide']]['gain'] < $openOrder['stopPrice']
                                || $openOrder['positionSide'] === 'LONG' && $pricesClosedPosition[$openOrder['positionSide']]['gain'] > $openOrder['stopPrice']
                            )
                        ) {
                            $diffTrigger = abs($this->bot->getExchange()->percentage(
                                (float) $pricesClosedPosition[$openOrder['positionSide']]['gain'],
                                (float) $openOrder['stopPrice']
                            ));

                            if ($diffTrigger >= 0.10) {
                                if ($this->bot->enableDebug()) {
                                    $message = 'Order timeout[trigger]';

                                    $this->log->register(LogLevel::LEVEL_DEBUG, $message);

                                    echo "{$message}\n";
                                }

                                if (!$partialOrderTakeRenewed
                                    && !empty($pricesClosedPosition[$openOrder['positionSide']]['partial']['take']['price'])
                                    && !empty($pricesClosedPosition[$openOrder['positionSide']]['partial']['take']['qty'])
                                ) {
                                    if ($openOrdersPartialIds['take']) {
                                        foreach ($openOrdersPartialIds['take'] as $ids) {
                                            $this->bot->getExchange()->cancelOrder($openOrder['symbol'], (string) $ids);
                                        }
                                    }

                                    $this->closePosition(
                                        $openOrder['symbol'],
                                        $openOrder['positionSide'],
                                        $pricesClosedPosition[$openOrder['positionSide']]['partial']['take']['price'],
                                        false,
                                        $pricesClosedPosition[$openOrder['positionSide']]['partial']['take']['qty']
                                    );
                                }

                                $this->bot->getExchange()->cancelOrder($openOrder['symbol'], (string) $openOrder['orderId']);

                                $this->closePosition($openOrder['symbol'], $openOrder['positionSide'], $pricesClosedPosition[$openOrder['positionSide']]['gain']);
                            }
                        }

                        if (
                            $openOrder['origType'] === 'STOP_MARKET'
                            && $pricesClosedPosition[$openOrder['positionSide']]['loss']
                            && (
                                $openOrder['positionSide'] === 'SHORT' && $pricesClosedPosition[$openOrder['positionSide']]['loss'] < $openOrder['stopPrice']
                                || $openOrder['positionSide'] === 'LONG' && $pricesClosedPosition[$openOrder['positionSide']]['loss'] > $openOrder['stopPrice']
                            )
                        ) {
                            $diffTrigger = abs($this->bot->getExchange()->percentage(
                                (float) $pricesClosedPosition[$openOrder['positionSide']]['loss'],
                                (float) $openOrder['stopPrice']
                            ));

                            if ($diffTrigger >= 0.10) {
                                if ($this->bot->enableDebug()) {
                                    $message = 'Order timeout[trigger]';

                                    $this->log->register(LogLevel::LEVEL_DEBUG, $message);

                                    echo "{$message}\n";
                                }

                                if (!$partialOrderStopRenewed
                                    && !empty($pricesClosedPosition[$openOrder['positionSide']]['partial']['stop']['price'])
                                    && !empty($pricesClosedPosition[$openOrder['positionSide']]['partial']['stop']['qty'])
                                ) {
                                    if ($openOrdersPartialIds['stop']) {
                                        foreach ($openOrdersPartialIds['stop'] as $ids) {
                                            $this->bot->getExchange()->cancelOrder($openOrder['symbol'], (string) $ids);
                                        }
                                    }

                                    $this->closePosition(
                                        $openOrder['symbol'],
                                        $openOrder['positionSide'],
                                        $pricesClosedPosition[$openOrder['positionSide']]['partial']['stop']['price'],
                                        true,
                                        $pricesClosedPosition[$openOrder['positionSide']]['partial']['stop']['qty']
                                    );
                                }

                                $this->bot->getExchange()->cancelOrder($openOrder['symbol'], (string) $openOrder['orderId']);

                                $this->closePosition($openOrder['symbol'], $openOrder['positionSide'], $pricesClosedPosition[$openOrder['positionSide']]['loss'], true);
                            }
                        }
                    }
                }
            } else {
                foreach ($openOrdersClosed+$openOrdersPartial as $openOrder) {
                    if ($this->bot->getExchange()->isTimeBoxOrder($openOrder['time'], $this->bot->getConfig()->getOrderLongTriggerTimeout())) {
                        if ($this->bot->enableDebug()) {
                            $message = 'Order timeout[trigger] - long time';

                            $this->log->register(LogLevel::LEVEL_DEBUG, $message);

                            echo "{$message}\n";
                        }

                        $this->bot->getExchange()->cancelOrder($openOrder['symbol'], (string) $openOrder['orderId']);
                    }
                }
            }

            if ($side) {
                $symbolConfig = Symbols::where([
                    'bot_id' => $this->bot->getId(),
                    'pair' => $symbol,
                    'status' => 'active'
                ])->first();

                if ($symbolConfig) {
                    $quantity = (float) ($symbolConfig->base_quantity < $symbolConfig->min_quantity
                        ? $symbolConfig->min_quantity
                        : $symbolConfig->base_quantity);
                    $qtyEntries = ($marginSymbolQty[$side] > 0 ? $marginSymbolQty[$side] / $quantity : 0);
                    $simulatedUsageMargin = ($qtyEntries > 0 ? $marginSymbol[$side]['usage'] / $qtyEntries : 0);

                    $limitMarginAccount = $marginAccountPercent < $this->bot->getConfig()->getMargin()['account'];
                    $limitMarginSymbol = (
                        ($marginSymbol[$side]['usage'] + $simulatedUsageMargin) < $this->bot->getConfig()->getMargin()['symbol']
                    ) && (
                        !$marginSymbol[$side]['limit']
                        || ($marginSymbol[$side]['limit'] && ($marginSymbol[$side]['usage'] + $simulatedUsageMargin) < $marginSymbol[$side]['limit'])
                    );
                    $limitMargin = $limitMarginAccount && $limitMarginSymbol;

                    if (!$openOrders && (!$hasPosition[$side] || $limitMargin)) {
                        $price = $bookSell[0];
                        $sideOrder = 'SELL';
                        $positionSideOrder = 'SHORT';

                        if ($side === 'LONG') {
                            $price = $bookBuy[0];
                            $sideOrder = 'BUY';
                            $positionSideOrder = 'LONG';
                        }

                        $checkSideBot = strtoupper($this->bot->getConfig()->getOperationSide()) != 'BOTH';
                        $checkSideSymbol = $symbolConfig->side != 'BOTH';
                        $sideChecked = '';
                        $validSideBot = true;
                        $validSideSymbol = true;

                        if ($checkSideBot) {
                            $validSideBot = !($side != strtoupper($this->bot->getConfig()->getOperationSide()));
                        }

                        if ($checkSideSymbol) {
                            $validSideSymbol = !($side != $symbolConfig->side);
                        }

                        if (!$validSideBot) {
                            $sideChecked = strtoupper($this->bot->getConfig()->getOperationSide());
                        }

                        if (!$validSideSymbol) {
                            $sideChecked = $symbolConfig->side;
                        }

                        if (!$sideChecked) {
                            $lastOrderFilled = $this->getLastOrderFilled($symbolConfig, $positionSideOrder);

                            if ($lastOrderFilled && !$this->isTimeBoxOrder($lastOrderFilled)) {
                                if ($this->bot->enableDebug()) {
                                    $message = "Recently closed {$side} order";

                                    $this->log->register(LogLevel::LEVEL_DEBUG, $message);

                                    echo "{$message}\n";
                                }
                            } else {
                                $enableTradeAvg = true;

                                if ($avgPriceOrder = $this->getAvgOrdersFilled($symbolConfig, $positionSideOrder)) {
                                    $enableTradeAvg = $positionSideOrder === 'LONG'
                                        ? $price < $avgPriceOrder
                                        : $price > $avgPriceOrder;
                                }

                                if ($enableTradeAvg) {
                                    $order = $this->bot->getExchange()->createOrder([
                                        'symbol' => $symbol,
                                        'side' => $sideOrder,
                                        'positionSide' => $positionSideOrder,
                                        'type' => 'LIMIT',
                                        'timeInForce' => 'GTC',
                                        'quantity' => $quantity,
                                        'price' => (float) $price
                                    ]);
                                    $order['userId'] = $this->bot->getUserId();
                                    $order['symbolId'] = $symbolConfig->id;

                                    $this->updateOrCreateOrder($order);

                                    if ($this->bot->enableDebug()) {
                                        $message = 'Open position';

                                        $this->log->register(LogLevel::LEVEL_DEBUG, $message);

                                        echo "{$message}\n";
                                    }
                                } else {
                                    $price = (float) $price;
                                    $avgPriceOrder = $this->bot->getExchange()->formatDecimal($price, $avgPriceOrder);

                                    if ($this->bot->enableDebug()) {
                                        $message = "The current price is unfavorable[{$positionSideOrder}] - {$price} - {$avgPriceOrder}";

                                        $this->log->register(LogLevel::LEVEL_DEBUG, $message);

                                        echo "{$message}\n";
                                    }
                                }
                            }
                        } else {
                            if ($this->bot->enableDebug()) {
                                $message = "{$side} different from {$sideChecked}";

                                $this->log->register(LogLevel::LEVEL_DEBUG, $message);

                                echo "{$message}\n";
                            }
                        }
                    } else {
                        $reason = '';

                        if (!$limitMargin) {
                            $reason = !$limitMarginAccount ? 'marginAccount' : 'marginSymbol';
                        }

                        if ($openOrders) {
                            $reason = 'openOrders';
                        }

                        if ($this->bot->enableDebug()) {
                            $message = "Without {$side} operation[$reason]";

                            $this->log->register(LogLevel::LEVEL_DEBUG, $message);

                            echo "{$message}\n";
                        }
                    }
                } else {
                    if ($this->bot->enableDebug()) {
                        $message = "Symbol {$symbol} not found";

                        $this->log->register(LogLevel::LEVEL_DEBUG, $message);

                        echo "{$message}\n";
                    }
                }
            }
        } catch (Exception $e) {
            echo $e->getMessage() . "\n";
        }
    }

    /**
     * Get symbol exchange
     *
     * @param string $symbol
     * @return array
     */
    private function getSymbolExchange(string $symbol): array
    {
        $exchangeInfo = $this->bot->getExchange()->getExchangeInfo();
        $symbolInfo = array_filter($exchangeInfo['symbols'], fn($info) => $info['symbol'] === $symbol);

        if ($symbolInfo) {
            return current($symbolInfo);
        }

        return [];
    }

    /**
     * Check if order is time box
     *
     * @param int $orderTime
     * @return bool
     */
    private function isTimeBoxOrder(int $orderTime): bool
    {
        $timeOrder = new DateTime('@'. $orderTime);
        $timeNow = new DateTime('now');
        $time = $timeOrder->diff($timeNow);

        $timeBox = (int) ($time->format('%i')) * 60;
        $timeBox += (int) ($time->format('%s'));

        return $timeBox >= $this->bot->getConfig()->getPosition()['filledTime'];
    }

    /**
     * Get last order filled
     *
     * @param object $symbol
     * @param string $positionSide
     * @return int
     */
    private function getLastOrderFilled(object $symbol, string $positionSide): ?int
    {
        $order = Orders::where([
            'user_id' => $this->bot->getUserId(),
            'symbol_id' => $symbol->id,
            'position_side' => $positionSide,
            'type' => 'LIMIT'
        ])
        ->whereIn('status', ['NEW','PARTIALLY_FILLED', 'FILLED'])
        ->orderBy('updated_at', 'desc')
        ->first();

        if ($order) {
            return (int) strtotime((string) $order->updated_at);
        }

        return null;
    }

    /**
     * Get average orders filled
     *
     * @param object $symbol
     * @param string $positionSide
     * @return float
     */
    private function getAvgOrdersFilled(object $symbol, string $positionSide): float
    {
        if ($limit = $this->bot->getConfig()->getAveragePriceOrderCount()) {
            $interval = str_replace(
                ['m', 'h', 'd'],
                [' minute', ' hour', ' day'],
                $this->bot->getConfig()->getInterval()
            );

            $orders = Orders::where([
                'user_id' => $this->bot->getUserId(),
                'symbol_id' => $symbol->id,
                'position_side' => $positionSide,
                'type' => 'LIMIT'
            ])
            ->where('updated_at', '>=', date('Y-m-d H:i:s', strtotime("- {$interval}")))
            ->whereIn('status', ['FILLED'])
            ->orderBy('updated_at', 'desc')
            ->take($limit)
            ->get();

            $avgPrice = 0;

            foreach ($orders as $order) {
                $avgPrice += $order->price;
            }

            return $avgPrice > 0 ? $avgPrice / count($orders) : 0;
        }

        return 0;
    }

    /**
     * Close position
     *
     * @param string $symbol
     * @param string $side
     * @param float $price
     * @param bool $stop
     * @param float|null $qty
     * @return void
     * @throws Exception
     */
    private function closePosition(string $symbol, string $side, float $price, bool $stop = false, ?float $qty = null): void
    {
        try {
            $symbolConfig = Symbols::where([
                'bot_id' => $this->bot->getId(),
                'pair' => $symbol
            ])->first();

            $order = $this->bot->getExchange()->closePosition($symbol, $side, $price, $stop, $qty);
            $order['userId'] = $this->bot->getUserId();
            $order['symbolId'] = $symbolConfig->id;

            $this->updateOrCreateOrder($order);
        } catch (UnexpectedValueException $e) {
            if (str_contains($e->getMessage(), 'Order would immediately trigger')) {
                $staticsTicker = $this->bot->getExchange()->getStaticsTicker($symbol);
                $markPrice = (float) ($staticsTicker['lastPrice'] ?? 0);

                $diffPrice = $this->bot->getExchange()->calculeProfit($markPrice, $this->bot->getConfig()->getIncrementTriggerPercentage());

                if ($stop) {
                    $newPrice = (float) ($side === 'SHORT' ? $markPrice + $diffPrice : $markPrice - $diffPrice);
                } else {
                    $newPrice = (float) ($side === 'SHORT' ? $markPrice - $diffPrice : $markPrice + $diffPrice);
                }

                $price = $this->bot->getExchange()->formatDecimal($markPrice, $newPrice);

                $this->closePosition($symbol, $side, $price, $stop, $qty);

                if ($this->bot->enableDebug()) {
                    $message = "Recreating order with new price[$side]";

                    $this->log->register(LogLevel::LEVEL_DEBUG, $message);

                    echo "{$message}\n";
                }
            }
        }
    }

    /**
     * Update information of orders
     *
     * @param string $symbol
     * @return void
     */
    private function updateOrder(string $symbol): void
    {
        $symbolConfig = Symbols::where([
            'bot_id' => $this->bot->getId(),
            'pair' => $symbol
        ])->first();

        $orders = Orders::where([
            'user_id' => $this->bot->getUserId(),
            'symbol_id' => $symbolConfig->id
        ])
        ->whereIn('status', ['NEW','PARTIALLY_FILLED'])
        ->get();

        foreach ($orders as $order) {
            if ($result = $this->bot->getExchange()->getOrderById($order->order_id, $symbolConfig->pair)) {
                $realizedPnl = $this->bot->getExchange()->getRealizedPnl($symbolConfig->pair, $order->order_id);

                $result['userId'] = $order->user_id;
                $result['symbolId'] = $order->symbol_id;
                $result['pnl_close'] = $realizedPnl['close'];
                $result['pnl_commission'] = $realizedPnl['commission'];
                $result['pnl_realized'] = $realizedPnl['realized'];

                $this->updateOrCreateOrder($result);
            }
        }
    }

    /**
     * Update or create order
     *
     * @param array $data
     * @return void
     */
    private function updateOrCreateOrder(array $order): void
    {
        Orders::updateOrCreate(
            [
                'order_id' => $order['orderId'],
            ],
            [
                'user_id' => $order['userId'],
                'symbol_id' => $order['symbolId'],
                'side' => $order['side'],
                'position_side' => $order['positionSide'],
                'type' => $order['origType'],
                'quantity' => $order['origQty'],
                'pnl_close' => $order['pnl_close'] ?? null,
                'pnl_commission' => $order['pnl_commission'] ?? null,
                'pnl_realized' => $order['pnl_realized'] ?? null,
                'price' => $order['price'],
                'stop_price' => $order['stopPrice'] ?? null,
                'close_position' => $order['closePosition'] ? 'true' : 'false',
                'time_in_force' => $order['timeInForce'],
                'client_order_id' => $order['clientOrderId'],
                'status' => $order['status'],
            ]
        );
    }

    /**
     * Start
     *
     * @return void
     */
    private function start(): void
    {
        echo "Started - " . date('Y-m-d H:i:s') . "\n";

        $this->stream = new ReadableResourceStream(STDIN);
        $this->stream->on('data', function (mixed $chunk) {
            if ($chunk === '@STOP') {
                $this->exitCode = self::RESULT_SUCCESS;
            }
        });
    }

    /**
     * Exit
     *
     * @return never
     */
    private function exit(): never
    {
        exit($this->exitCode);
    }
}
