<?php

declare(strict_types=1);

namespace FjrSoftware\Flinkbot\Bot;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Stream\ReadableResourceStream;

use FjrSoftware\Flinkbot\Exchange\Binance;

use FjrSoftware\Flinkbot\Indicator\StochasticRSI;
use FjrSoftware\Flinkbot\Indicator\MovingAverageSMA;
use FjrSoftware\Flinkbot\Indicator\Condition;
use FjrSoftware\Flinkbot\Indicator\OperatorInterface;

define('PUBLIC_KEY', 'Remno3Mox2ABEJ80AlzstcYdV0K6VznqVuS5a5lf2qWszWbvt4Z74YNQvOp4DtBd');
define('PRIVATE_KEY', 'FJS4eWOv8fyjMhABtIo1RhNi2a0yucYAwHtzOxio0pjiu4HCaiG3AyMhZMaKcH64');

define('PROFIT', 50);
define('MARGIN_BALANCE', 10);
define('TIMEOUT', 45);

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
     * Constructor
     *
     * @param int $botId
     */
    public function __construct(
        private readonly int $botId
    ) {
        $this->loop = Loop::get();
        $this->start();
    }

    /**
     * Run
     *
     * @param string $symbol
     * @return void
     */
    public function run(string $symbol): void
    {
        echo "Started - {$symbol} " . date('Y-m-d H:i:s') . "\n";

        $binance = new Binance(PUBLIC_KEY, PRIVATE_KEY);

        $candles = $binance->getCandles($symbol, '15m', 100);
        $closes = $binance->getClosePrice($candles);
        $current = $binance->getCurrentValue($candles, 'close');

        $indicators = [
            'SMA' => [
                7 => new MovingAverageSMA($closes, 7),
                25 => new MovingAverageSMA($closes, 25),
                99 => new MovingAverageSMA($closes, 99),
            ]
        ];

        $smaSide = '';
        $smaSide = (new Condition(
            [
                $indicators['SMA'][7],
                $indicators['SMA'][25],
                $indicators['SMA'][99],
            ],
            OperatorInterface::GREATER_EQUAL,
            $current
        ))->isSatisfied() ? 'SHORT' : $smaSide;

        $smaSide = (new Condition(
            [
                $indicators['SMA'][7],
                $indicators['SMA'][25],
                $indicators['SMA'][99],
            ],
            OperatorInterface::LESS_EQUAL,
            $current
        ))->isSatisfied() ? 'LONG' : $smaSide;

        $stochrsi = new StochasticRSI($closes, 14, 3, 3);
        $stochSell = 90;
        $stochBuy = 10;

        $stochSide = '';
        $side = '';

        $stochSide = (new Condition($stochrsi, OperatorInterface::GREATER_EQUAL, $stochSell))->isSatisfied() ? 'SHORT' : $stochSide;
        $stochSide = (new Condition($stochrsi, OperatorInterface::LESS_EQUAL, $stochBuy))->isSatisfied() ? 'LONG' : $stochSide;

        $stochrsi = $stochrsi->getValue();
        $k = $stochrsi[0];
        $d = $stochrsi[1];

        if ($smaSide && !$stochSide) {
            $side = $smaSide;
        }

        if ($stochSide && !$smaSide) {
            $side = $stochSide;
        }

        if ($stochSide === $smaSide) {
            $side = $stochSide;
        }

        printf(
            "Current: %s | Stoch: %s (k: %s, d: %s) | Sma: %s (7: %s, 25: %s, 99: %s) | Side: %s\n",
            $current,
            $stochSide,
            $k,
            $d,
            $smaSide,
            $indicators['SMA'][7]->getValue()[0],
            $indicators['SMA'][25]->getValue()[0],
            $indicators['SMA'][99]->getValue()[0],
            $side
        );

        $positions = $binance->getPosition($symbol);
        $book = $binance->getBook($symbol);
        $bookBuy = $book['bids'][0];
        $bookSell = $book['asks'][0];
        $infoPosition = [
            'LONG' => [
                'qty' => 0,
                'roi' => 0
            ],
            'SHORT' => [
                'qty' => 0,
                'roi' => 0
            ]
        ];
        $openOrders = $binance->getOpenOrders($symbol);
        $openOrdersClosed = array_filter($openOrders, fn($order) => $order['reduceOnly']);
        $openOrders = array_filter($openOrders, fn($order) => !$order['reduceOnly']);

        foreach ($positions as $position) {
            $qty = abs($position['positionAmt']);

            if ($qty > 0) {
                if ($position['positionSide'] === 'LONG') {
                    $infoPosition['LONG'] = [
                        'qty' => $qty,
                        'roi' => $binance->percentage($position['markPrice'], $position['entryPrice']) * $position['leverage']
                    ];
                } else {
                    $infoPosition['SHORT'] = [
                        'qty' => $qty,
                        'roi' => $binance->percentage($position['entryPrice'], $position['markPrice']) * $position['leverage']
                    ];
                }
            }

            $profit = $infoPosition[$position['positionSide']]['roi'] >= PROFIT;

            if ($qty > 0 && $profit) {
                $diffPrice = $binance->calculeProfit($current, 0.10);
                $priceCloseGain = (float) ($position['positionSide'] === 'SHORT' ? $current - $diffPrice : $current + $diffPrice);
                $priceCloseStopGain = (float) ($position['positionSide'] === 'SHORT' ? $current + $diffPrice : $current - $diffPrice);
                $sideOrder = $position['positionSide'] === 'SHORT' ? 'BUY' : 'SELL';

                $openOrdersClosed = array_filter($openOrdersClosed, fn($order) => $order['side'] === $sideOrder);

                if (!$openOrdersClosed) {
                    $result1 = $binance->closePosition($symbol, $position['positionSide'], $priceCloseGain);
                    $result2 = $binance->closePosition($symbol, $position['positionSide'], $priceCloseStopGain, true);

                    echo "Close position - ROI: {$infoPosition[$position['positionSide']]['roi']}\n";
                }
            }
        }

        foreach ($openOrders as $openOrder) {
            if ($binance->isTimeBoxOrder($openOrder['time'], TIMEOUT)) {
                $binance->cancelOrder($openOrder['symbol'], $openOrder['orderId']);
                echo "timeout\n";
            }
        }

        if ($side) {
            $noPosition = $side === 'LONG' && !$infoPosition['LONG']['qty'] ||$side === 'SHORT' && !$infoPosition['SHORT']['qty'];

            $account = $binance->getAccountInformation();
            $marginAccount = 100 - $binance->percentage((float) $account['totalMarginBalance'], (float) $account['totalMaintMargin']);

            if (!$openOrders && ($noPosition || $marginAccount <= MARGIN_BALANCE)) {
                $price = $bookSell[0];
                $sideOrder = 'SELL';
                $positionSideOrder = 'SHORT';

                if ($side === 'LONG') {
                    $price = $bookBuy[0];
                    $sideOrder = 'BUY';
                    $positionSideOrder = 'LONG';
                }

                $binance->createOrder([
                    'symbol' => $symbol,
                    'side' => $sideOrder,
                    'positionSide' => $positionSideOrder,
                    'type' => 'LIMIT',
                    'timeInForce' => 'GTC',
                    'quantity' => 0.003,
                    'price' => (float) $price
                ]);

                echo "Open position\n";
            }
        }

        /*
        $this->loop->addPeriodicTimer(1, function ($timer) use (&$i, $symbol) {
            echo "Pending - {$symbol} " . date('Y-m-d H:i:s') . "\n";

            if (++$i >= 30) {
                $this->loop->cancelTimer($timer);
                $this->exit();
            }
        });

        $this->loop->run();
        */

        echo "Finished - {$symbol} " . date('Y-m-d H:i:s') . "\n";
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
