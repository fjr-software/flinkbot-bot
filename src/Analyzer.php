<?php

declare(strict_types=1);

namespace FjrSoftware\Flinkbot\Bot;

use Exception;
use FjrSoftware\Flinkbot\Bot\Account\Bot;
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
     * @var LoopInterfacee|null
     */
    private ?LoopInterface $loop = null;

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
     * Constructor
     *
     * @param int $botId
     */
    public function __construct(
        private readonly int $botId
    ) {
        $this->bot = new Bot($this->botId);
        $this->position = new Position($this->bot);
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

            $this->loop->addPeriodicTimer(5, function ($timer) use (&$i, $symbol) {
                $this->runAnalyzer($symbol);

                if (++$i >= 2) {
                    $this->loop->cancelTimer($timer);
                    $this->exit();
                }
            });
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
                    $sideInd = $indicators['short'][$ind] ? 'SHORT' : '';
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

            echo implode(' - ', $debugValues) . " - Side: {$side}\n";

            $book = $this->bot->getExchange()->getBook($symbol);
            $bookBuy = $book['bids'][0];
            $bookSell = $book['asks'][0];
            $openOrders = $this->bot->getExchange()->getOpenOrders($symbol);
            $openOrdersClosed = array_filter($openOrders, fn($order) => $order['reduceOnly']);
            $openOrders = array_filter($openOrders, fn($order) => !$order['reduceOnly']);
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

                if ($position->status === 'open') {
                    $hasPosition[$position->side] = true;
                }

                if ($position->status === 'open' && $position->pnl_roi_percent >= $this->bot->getConfig()->getPosition()['profit']) {
                    $markPrice = (float) $position->mark_price;
                    $diffPrice = $this->bot->getExchange()->calculeProfit($markPrice, 0.10);
                    $priceCloseGain = (float) ($position->side === 'SHORT' ? $markPrice - $diffPrice : $markPrice + $diffPrice);
                    $priceCloseStopGain = (float) ($position->side === 'SHORT' ? $markPrice + $diffPrice : $markPrice - $diffPrice);
                    $sideOrder = $position->side === 'SHORT' ? 'BUY' : 'SELL';

                    $openOrdersClosed = array_filter($openOrdersClosed, fn($order) => $order['side'] === $sideOrder);
                    $priceCloseGain = $this->bot->getExchange()->formatDecimal($markPrice, $priceCloseGain);
                    $priceCloseStopGain = $this->bot->getExchange()->formatDecimal($markPrice, $priceCloseStopGain);

                    if (!$openOrdersClosed) {
                        $result1 = $this->bot->getExchange()->closePosition($symbol, $position->side, $priceCloseGain);
                        $result2 = $this->bot->getExchange()->closePosition($symbol, $position->side, $priceCloseStopGain, true);

                        echo "Close position - ROI: {$position->pnl_roi_percent}\n";
                    }
                }
            }

            foreach ($openOrders as $openOrder) {
                if ($this->bot->getExchange()->isTimeBoxOrder($openOrder['time'], $this->bot->getConfig()->getOrderTimeout())) {
                    $this->bot->getExchange()->cancelOrder($openOrder['symbol'], (string) $openOrder['orderId']);

                    echo "timeout\n";
                }
            }

            if ($side) {
                $limitMargin = $marginAccountPercent <= $this->bot->getConfig()->getMargin()['account']
                    || $marginSymbol[$side] <= $this->bot->getConfig()->getMargin()['symbol'];

                if (!$openOrders && (!$hasPosition[$side] || $limitMargin)) {
                    $price = $bookSell[0];
                    $sideOrder = 'SELL';
                    $positionSideOrder = 'SHORT';

                    if ($side === 'LONG') {
                        $price = $bookBuy[0];
                        $sideOrder = 'BUY';
                        $positionSideOrder = 'LONG';
                    }

                    $symbolConfig = Symbols::find([
                        'bot_id' => $this->bot->getId(),
                        'symbol' => $symbol,
                        'status' => 'active'
                    ])->first();

                    if ($symbolConfig) {
                        $lastOrderFilled = $this->getLastOrderFilled($symbolConfig);

                        if ($lastOrderFilled && $this->bot->getExchange()->isTimeBoxOrder($lastOrderFilled, $this->bot->getConfig()->getPosition()['filledTime'])) {
                            echo "Very close to the last order filled\n";
                        } else {
                            $order = $this->bot->getExchange()->createOrder([
                                'symbol' => $symbol,
                                'side' => $sideOrder,
                                'positionSide' => $positionSideOrder,
                                'type' => 'LIMIT',
                                'timeInForce' => 'GTC',
                                'quantity' => (float) $symbolConfig->base_quantity,
                                'price' => (float) $price
                            ]);
                            $order['userId'] = $this->bot->getUserId();
                            $order['symbolId'] = $symbolConfig->id;

                            $this->updateOrCreateOrder($order);

                            echo "Open position\n";
                        }
                    } else {
                        echo "Symbol {$symbol} not found\n";
                    }
                } else {
                    $reason = '';

                    if (!$limitMargin) {
                        $reason = 'Margin';
                    }

                    if ($openOrders) {
                        $reason = 'OpenOrders';
                    }

                    echo "Without operation[$reason]\n";
                }
            }
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
        }
    }

    /**
     * Get last order filled
     *
     * @param object $symbol
     * @return int
     */
    private function getLastOrderFilled(object $symbol): ?int
    {
        $order = Orders::where([
            'user_id' => $this->bot->getUserId(),
            'symbol_id' => $symbol->id,
            'status' => ['NEW','PARTIALLY_FILLED', 'FILLED']
        ])
        ->orderBy('updated_at', 'desc')
        ->first();

        if ($order) {
            return (int) strtotime($order->updated_at);
        }

        return null;
    }

    /**
     * Update information of orders
     *
     * @param string $symbol
     * @return void
     */
    private function updateOrder(string $symbol): void
    {
        $symbols = Symbols::where([
            'bot_id' => $this->bot->getId(),
            'pair' => $symbol
        ])->get();

        foreach ($symbols as $symbolConfig) {
            $orders = Orders::where([
                'user_id' => $this->bot->getUserId(),
                'symbol_id' => $symbolConfig->id,
                'status' => ['NEW','PARTIALLY_FILLED']
            ])->get();

            foreach ($orders as $order) {
                if ($result = $this->bot->getExchange()->getOrderById($order->order_id, $symbolConfig->pair)) {
                    $result['userId'] = $order->user_id;
                    $result['symbolId'] = $order->symbol_id;

                    $this->updateOrCreateOrder($result);
                }
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
                'price' => $order['price'],
                'stop_price' => $order['stopPrice'] ? $order['stopPrice'] : null,
                'close_position' => $order['closePosition'] ? 'true' : 'false',
                'time_in_force' => $order['timeInForce'],
                //'order_id' => $order['orderId'],
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
