<?php

declare(strict_types=1);

namespace FjrSoftware\Flinkbot\Bot;

use DateTime;
use Exception;
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
     */
    public function __construct(
        private readonly int $botId
    ) {
        $this->bot = new Bot($this->botId);
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
        $rateLimit = $this->bot->getExchange()->getRateLimit();

        printf(
            "Limit request: %d - Limit order: %d\n",
            $rateLimit->getCurrentRequest(),
            $rateLimit->getCurrentOrder()
        );

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
            $closes = $this->bot->getExchange()->getClosePrice($candles);
            $current = $this->bot->getExchange()->getCurrentValue($candles, 'close');

            $indicators = $this->bot->getConfig()->getIndicator($closes, $current);
            $debugValues = ['Current: ' . $current];
            $side = '';

            foreach ($indicators as $ind => $val) {
                if (!in_array($ind, ['long', 'short'])) {
                    $sideInd = $indicators['long'][$ind] ? 'LONG' : '';
                    $sideInd = $indicators['short'][$ind] ? 'SHORT' : $sideInd;
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
                    if ($indicators['long'][$this->bot->getConfig()->getPrioritySideIndicator()] ?? false) {
                        $side = 'LONG';
                    }

                    if ($indicators['short'][$this->bot->getConfig()->getPrioritySideIndicator()] ?? false) {
                        $side = 'SHORT';
                    }
                }
            }

            $message = implode(' - ', $debugValues) . " - Side: {$side}";

            $this->log->register(LogLevel::LEVEL_DEBUG, $message);

            echo "{$message}\n";

            $book = $this->bot->getExchange()->getBook($symbol);
            $bookBuy = $book['bids'][0];
            $bookSell = $book['asks'][0];
            $openOrders = $this->bot->getExchange()->getOpenOrders($symbol);
            $openOrdersClosed = array_filter($openOrders, fn($order) => $order['reduceOnly']);
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

            foreach ($this->position->get($symbol) as $position) {
                $marginAccountPercent = $position->margin_account_percent;
                $marginSymbol[$position->side] = $position->margin_symbol_percent;
                $configPosition = $this->bot->getConfig()->getPosition();
                $canPositionGain = $configPosition['profit'] > 0
                    && $position->pnl_roi_percent >= $configPosition['profit']
                    && $position->pnl_roi_value >= $configPosition['minimumGain'];
                $canPositionLoss = $configPosition['loss'] > 0
                    && abs((float) $position->pnl_roi_percent) >= $configPosition['loss']
                    && abs((float) $position->pnl_roi_value) >= $configPosition['minimumLoss'];
                $canPrevent = $configPosition['profit'] > 0
                    && !$canPositionGain && !$canPositionLoss
                    && $position->pnl_roi_percent >= ($configPosition['profit'] / 4)
                    && $position->pnl_roi_value >= $configPosition['minimumGain'];

                if ($position->status === 'open') {
                    $hasPosition[$position->side] = true;

                    if ($canPrevent || ($canPositionGain || $canPositionLoss)) {
                        $staticsTicker = $this->bot->getExchange()->getStaticsTicker($symbol);
                        $markPrice = (float) ($staticsTicker['lastPrice'] ?? 0);

                        $markPrice = (float) ($markPrice ?? $position->mark_price);
                        $entryPrice = (float) $position->entry_price;

                        $diffPrice = $this->bot->getExchange()->calculeProfit($markPrice, $this->bot->getConfig()->getIncrementTriggerPercentage());
                        $priceCloseGain = (float) ($position->side === 'SHORT' ? $markPrice - $diffPrice : $markPrice + $diffPrice);
                        $priceCloseStopGain = (float) ($position->side === 'SHORT' ? $markPrice + $diffPrice : $markPrice - $diffPrice);

                        if ($this->bot->getConfig()->getEnableHalfPriceProtection()) {
                            $priceCloseStopGain = ($priceCloseStopGain + $entryPrice) / 2;
                        }

                        $sideOrder = $position->side === 'SHORT' ? 'BUY' : 'SELL';
                        $typeClosed = $canPositionGain ? 'profit' : 'loss';

                        $openOrdersClosed = array_filter($openOrdersClosed, fn($order) => $order['side'] === $sideOrder);
                        $priceCloseGain = $this->bot->getExchange()->formatDecimal($markPrice, $priceCloseGain);
                        $priceCloseStopGain = $this->bot->getExchange()->formatDecimal($markPrice, $priceCloseStopGain);
                        $canGainLoss = true;

                        if (!$openOrdersClosed) {
                            if ($canPrevent) {
                                $canGainLoss = $position->pnl_roi_percent >= ($configPosition['profit'] / 2);
                                $diffPrice = $this->bot->getExchange()->calculeProfit(
                                    $entryPrice,
                                    (float) ($configPosition['profit'] / $position->leverage) + $this->bot->getConfig()->getIncrementTriggerPercentage()
                                );
                                $avgEntryMarkGain = ($entryPrice + $markPrice) / 2;
                                $priceCloseGain = (float) ($position->side === 'SHORT' ? $avgEntryMarkGain - $diffPrice : $avgEntryMarkGain + $diffPrice);
                                $priceCloseGain = $this->bot->getExchange()->formatDecimal($markPrice, $priceCloseGain);
                                $typeClosed = 'prevent';
                            }

                            $orderProfit = $this->bot->getExchange()->closePosition($symbol, $position->side, $priceCloseGain);
                            $orderProfit['userId'] = $this->bot->getUserId();
                            $orderProfit['symbolId'] = $position->symbol->id;

                            $this->updateOrCreateOrder($orderProfit);

                            if (!$canPrevent) {
                                $orderStop = $this->bot->getExchange()->closePosition($symbol, $position->side, $priceCloseStopGain, true);
                                $orderStop['userId'] = $this->bot->getUserId();
                                $orderStop['symbolId'] = $position->symbol->id;

                                $this->updateOrCreateOrder($orderStop);
                            }

                            $message = "Close position[{$typeClosed}] - ROI: {$position->pnl_roi_percent}";

                            $this->log->register(LogLevel::LEVEL_DEBUG, $message);

                            echo "{$message}\n";
                        }
                    }
                }
            }

            foreach ($openOrders as $openOrder) {
                if ($this->bot->getExchange()->isTimeBoxOrder($openOrder['time'], $this->bot->getConfig()->getOrderCommonTimeout())) {
                    $this->bot->getExchange()->cancelOrder($openOrder['symbol'], (string) $openOrder['orderId']);

                    $message = 'Order timeout[common]';

                    $this->log->register(LogLevel::LEVEL_DEBUG, $message);

                    echo "{$message}\n";
                }
            }

            if ($canGainLoss) {
                foreach ($openOrdersClosed as $openOrder) {
                    if ($this->bot->getExchange()->isTimeBoxOrder($openOrder['time'], $this->bot->getConfig()->getOrderTriggerTimeout())) {
                        $this->bot->getExchange()->cancelOrder($openOrder['symbol'], (string) $openOrder['orderId']);

                        $message = 'Order timeout[trigger]';

                        $this->log->register(LogLevel::LEVEL_DEBUG, $message);

                        echo "{$message}\n";
                    }
                }
            }

            if ($side) {
                $limitMarginAccount = $marginAccountPercent < $this->bot->getConfig()->getMargin()['account'];
                $limitMarginSymbol = $marginSymbol[$side] < $this->bot->getConfig()->getMargin()['symbol'];
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

                    $symbolConfig = Symbols::where([
                        'bot_id' => $this->bot->getId(),
                        'pair' => $symbol,
                        'status' => 'active'
                    ])->first();

                    if ($symbolConfig) {
                        $lastOrderFilled = $this->getLastOrderFilled($symbolConfig, $positionSideOrder);

                        if ($lastOrderFilled && !$this->isTimeBoxOrder($lastOrderFilled)) {
                            $message = "Recently closed {$side} order";

                            $this->log->register(LogLevel::LEVEL_DEBUG, $message);

                            echo "{$message}\n";
                        } else {
                            $enableTradeAvg = true;

                            if ($avgPriceOrder = $this->getAvgOrdersFilled($symbolConfig, $positionSideOrder)) {
                                $enableTradeAvg = $positionSideOrder === 'LONG'
                                    ? $price < $avgPriceOrder
                                    : $price > $avgPriceOrder;
                            }

                            if ($enableTradeAvg) {
                                $quantity = (float) ($symbolConfig->base_quantity < $symbolConfig->min_quantity
                                    ? $symbolConfig->min_quantity
                                    : $symbolConfig->base_quantity);

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

                                $message = 'Open position';

                                $this->log->register(LogLevel::LEVEL_DEBUG, $message);

                                echo "{$message}\n";
                            } else {
                                $avgPriceOrder = $this->bot->getExchange()->formatDecimal($price, $avgPriceOrder);
                                $message = "The current price is unfavorable[{$positionSideOrder}] - {$price} - {$avgPriceOrder}";

                                $this->log->register(LogLevel::LEVEL_DEBUG, $message);

                                echo "{$message}\n";
                            }
                        }
                    } else {
                        $message = "Symbol {$symbol} not found";

                        $this->log->register(LogLevel::LEVEL_DEBUG, $message);

                        echo "{$message}\n";
                    }
                } else {
                    $reason = '';

                    if (!$limitMargin) {
                        $reason = !$limitMarginAccount ? 'marginAccount' : 'marginSymbol';
                    }

                    if ($openOrders) {
                        $reason = 'openOrders';
                    }

                    $message = "Without {$side} operation[$reason]";

                    $this->log->register(LogLevel::LEVEL_DEBUG, $message);

                    echo "{$message}\n";
                }
            }
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
        }
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
