<?php

declare(strict_types=1);

namespace FjrSoftware\Flinkbot\Bot;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Stream\ReadableResourceStream;

use FjrSoftware\Flinkbot\Bot\Account\Bot;
use FjrSoftware\Flinkbot\Bot\Account\Position;
use FjrSoftware\Flinkbot\Indicator\StochasticRSI;
use FjrSoftware\Flinkbot\Indicator\MovingAverageSMA;
use FjrSoftware\Flinkbot\Indicator\Condition;
use FjrSoftware\Flinkbot\Indicator\OperatorInterface;

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
     * Run
     *
     * @param string $symbol
     * @return void
     */
    public function run(string $symbol): void
    {
        $this->loop->addPeriodicTimer(5, function ($timer) use (&$i, $symbol) {
            try {
                $this->position->execute($symbol);

                $candles = $this->bot->getExchange()->getCandles($symbol, '15m', 100);
                $closes = $this->bot->getExchange()->getClosePrice($candles);
                $current = $this->bot->getExchange()->getCurrentValue($candles, 'close');

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
                    OperatorInterface::GREATER,
                    $current
                ))->isSatisfied() ? 'SHORT' : $smaSide;

                $smaSide = (new Condition(
                    [
                        $indicators['SMA'][7],
                        $indicators['SMA'][25],
                        $indicators['SMA'][99],
                    ],
                    OperatorInterface::LESS,
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

                $book = $this->bot->getExchange()->getBook($symbol);
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
                $openOrders = $this->bot->getExchange()->getOpenOrders($symbol);
                $openOrdersClosed = array_filter($openOrders, fn($order) => $order['reduceOnly']);
                $openOrders = array_filter($openOrders, fn($order) => !$order['reduceOnly']);

                foreach ($this->position->get($symbol) as $position) {
                    if ($position->status === 'open' && $position->pnl_roi_percent >= PROFIT) {
                        $markPrice = (float) $position->mark_price;
                        $diffPrice = $this->bot->getExchange()->calculeProfit($markPrice, 0.10);
                        $priceCloseGain = (float) ($position->side === 'SHORT' ? $markPrice - $diffPrice : $markPrice + $diffPrice);
                        $priceCloseStopGain = (float) ($position->side === 'SHORT' ? $markPrice + $diffPrice : $markPrice - $diffPrice);
                        $sideOrder = $position->side === 'SHORT' ? 'BUY' : 'SELL';

                        $openOrdersClosed = array_filter($openOrdersClosed, fn($order) => $order['side'] === $sideOrder);

                        if (!$openOrdersClosed) {
                            $result1 = $this->bot->getExchange()->closePosition($symbol, $position->side, $priceCloseGain);
                            $result2 = $this->bot->getExchange()->closePosition($symbol, $position->side, $priceCloseStopGain, true);

                            echo "Close position - ROI: {$position->pnl_roi_percent}\n";
                        }
                    }
                }

                foreach ($openOrders as $openOrder) {
                    if ($this->bot->getExchange()->isTimeBoxOrder($openOrder['time'], TIMEOUT)) {
                        $this->bot->getExchange()->cancelOrder($openOrder['symbol'], (string) $openOrder['orderId']);
                        echo "timeout\n";
                    }
                }

                if ($side) {
                    $noPosition = $side === 'LONG' && !$infoPosition['LONG']['qty'] ||$side === 'SHORT' && !$infoPosition['SHORT']['qty'];

                    $account = $this->bot->getExchange()->getAccountInformation();
                    $marginAccount = 100 - $this->bot->getExchange()->percentage((float) $account['totalMarginBalance'], (float) $account['totalMaintMargin']);

                    if (!$openOrders && ($noPosition || $marginAccount <= MARGIN_BALANCE)) {
                        $price = $bookSell[0];
                        $sideOrder = 'SELL';
                        $positionSideOrder = 'SHORT';

                        if ($side === 'LONG') {
                            $price = $bookBuy[0];
                            $sideOrder = 'BUY';
                            $positionSideOrder = 'LONG';
                        }

                        $this->bot->getExchange()->createOrder([
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
            } catch (\Exception $e) {
                echo "Error: " . $e->getMessage() . "\n";
            }

            if (++$i >= 2) {
                $this->loop->cancelTimer($timer);
                $this->exit();
            }
        });

        $this->loop->run();
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
