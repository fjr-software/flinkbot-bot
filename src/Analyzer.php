<?php

declare(strict_types=1);

namespace FjrSoftware\Flinkbot\Bot;

use Exception;
use FjrSoftware\Flinkbot\Bot\Account\Bot;
use FjrSoftware\Flinkbot\Bot\Account\Position;
use FjrSoftware\Flinkbot\Bot\Model\Symbols;
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

            foreach ($this->position->get($symbol) as $position) {
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
                $account = $this->bot->getExchange()->getAccountInformation();
                $marginAccount = 100 - $this->bot->getExchange()->percentage((float) $account['totalMarginBalance'], (float) $account['totalMaintMargin']);

                if (!$openOrders && (!$hasPosition[$side] || $marginAccount <= $this->bot->getConfig()->getMargin()['account'])) {
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
                    ]);

                    if ($symbolConfig) {
                        $this->bot->getExchange()->createOrder([
                            'symbol' => $symbol,
                            'side' => $sideOrder,
                            'positionSide' => $positionSideOrder,
                            'type' => 'LIMIT',
                            'timeInForce' => 'GTC',
                            'quantity' => (float) $symbolConfig->base_quantity,
                            'price' => (float) $price
                        ]);

                        echo "Open position\n";
                    } else {
                        echo "Symbol {$symbol} not found\n";
                    }
                } else {
                    echo "Without operation\n";
                }
            }
        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
        }
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
