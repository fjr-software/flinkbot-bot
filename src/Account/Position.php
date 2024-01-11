<?php

declare(strict_types=1);

namespace FjrSoftware\Flinkbot\Bot\Account;

use FjrSoftware\Flinkbot\Bot\Model\AccountValueCycle;
use FjrSoftware\Flinkbot\Bot\Model\Positions;
use FjrSoftware\Flinkbot\Bot\Model\Symbols;
use Illuminate\Database\Eloquent\Builder;

class Position
{
    /**
     * @var array|null
     */
    private ?array $exchangeInfo = null;

    /**
     * Constructor
     *
     * @param Bot $bot
     */
    public function __construct(
        private readonly Bot $bot
    ) {
    }

    /**
     * Update positions
     *
     * @param string $symbol
     * @return void
     */
    public function execute(string $symbol): void
    {
        if ($symbolInfo = $this->getSymbol($symbol)) {
            $account = $this->bot->getExchange()->getAccountInformation();
            $positions = $this->bot->getExchange()->getPosition($symbolInfo->pair);

            $marginAccountPercent = $this->bot->getExchange()->percentage((float) $account['totalMarginBalance'], (float) $account['totalMaintMargin']);
            $marginAccountPercent = $marginAccountPercent ? (100 - $marginAccountPercent) : 0;

            $pnlAccountPercent = $this->bot->getExchange()->percentage((float) $account['totalWalletBalance'], round((float) $account['totalUnrealizedProfit'], 2));
            $pnlAccountPercent = $pnlAccountPercent ? (100 - $pnlAccountPercent) : 0;

            $this->updateCycle((float) $account['totalWalletBalance'], 10);

            foreach ($positions as $position) {
                $size = abs((float) $position['positionAmt']);
                $type = $position['marginType'] === 'cross' ? 'CROSSED' : 'ISOLATED';
                $marginSymbol = abs((float) $position['notional']) / $position['leverage'];
                $marginSymbolPercent = $size ? (100 - $this->bot->getExchange()->percentage((float) $account['totalWalletBalance'], (float) $marginSymbol)) : 0;
                $status = $size > 0 ? 'open' : 'closed';
                $roiPercent = 0;

                if ($size > 0) {
                    $value1 = (float) $position['entryPrice'];
                    $value2 = (float) $position['markPrice'];

                    if ($position['positionSide'] === 'LONG') {
                        $value1 = (float) $position['markPrice'];
                        $value2 = (float) $position['entryPrice'];
                    }

                    $roiPercent = $this->bot->getExchange()->percentage($value1, $value2) * $position['leverage'];
                }

                if ($symbolExchange = $this->getSymbolExchange($symbolInfo->pair)) {
                    $position['markPrice'] = (float) $position['markPrice'];

                    if (!$position['markPrice']) {
                        $staticsTicker = $this->bot->getExchange()->getStaticsTicker($symbolInfo->pair);
                        $position['markPrice'] = (float) ($staticsTicker['lastPrice'] ?? 0);
                    }

                    if ($position['markPrice']) {
                        $entryPrice = round((float) $position['entryPrice'], (int) $symbolExchange['pricePrecision']);
                        $breakEvenPrice = round((float) $position['breakEvenPrice'], (int) $symbolExchange['pricePrecision']);
                        $markPrice = round((float) $position['markPrice'], (int) $symbolExchange['pricePrecision']);
                        $liquidationPrice = round((float) $position['liquidationPrice'], (int) $symbolExchange['pricePrecision']);

                        $notional = (float) ($this->getSymbolFilter($symbolExchange['filters'], 'MIN_NOTIONAL')['notional'] ?? 0);
                        $stepSize = $this->getSymbolFilter($symbolExchange['filters'], 'LOT_SIZE')['stepSize'] ?? 0;
                        $minQuantity = round($notional / $markPrice, (int) $symbolExchange['quantityPrecision']);

                        while (($minQuantity * $markPrice) < $notional) {
                            $minQuantity += $stepSize;
                        }

                        if ($symbolInfo->min_quantity != $minQuantity) {
                            $this->updateSymbol($symbol, $minQuantity);
                        }

                        $data = [
                            'symbolId' => $symbolInfo->id,
                            'leverage' => (int) $position['leverage'],
                            'side' => $position['positionSide'],
                            'entryPrice' => $entryPrice,
                            'breakEvenPrice' => $breakEvenPrice,
                            'size' => $size,
                            'roiPercent' => $roiPercent,
                            'unRealizedProfit' => (float) $position['unRealizedProfit'],
                            'pnlAccountPercent' => $pnlAccountPercent,
                            'marginAccountPercent' => $marginAccountPercent,
                            'marginSymbolPercent' => $marginSymbolPercent,
                            'markPrice' => $markPrice,
                            'liquidationPrice' => $liquidationPrice,
                            'type' => $type,
                            'status' => $status,
                        ];

                        $this->updateOrCreate($data);
                    }
                }
            }
        }
    }

    /**
     * Get positions
     *
     * @param string $symbol
     * @return array
     */
    public function get(string $symbol): array
    {
        $positions = Positions::where([
            'user_id' => $this->bot->getUserId()
        ])
        ->whereHas('symbol', function (Builder $query) {
            $query->where('bot_id', '=', $this->bot->getId());
        })->get();
        $result = [];

        foreach ($positions as $position) {
            if ($position->symbol->pair === $symbol) {
                $result[] = $position;
            }
        }

        return $result;
    }

    /**
     * Get symbol
     *
     * @param string $symbol
     * @return object|null
     */
    public function getSymbol(string $symbol): ?object
    {
        $data = Symbols::where([
            'bot_id' => $this->bot->getId(),
            'pair' => $symbol
        ])->first();

        return $data ?? null;
    }

    /**
     * Update or create
     *
     * @param array $data
     * @return void
     */
    private function updateOrCreate(array $data): void
    {
        $position = Positions::where([
            'user_id' => $this->bot->getUserId(),
            'symbol_id' => $data['symbolId'],
            'side' => $data['side']
        ])->first();

        $extra = [];

        if ($position && $position?->status === 'closed' && $data['status'] === 'open') {
            $extra['open_at'] = date('Y-m-d H:i:s');
            $extra['close_at'] = null;
        }

        if ($position && $position?->status === 'open' && $data['status'] === 'closed') {
            $extra['close_at'] = date('Y-m-d H:i:s');
        }

        if (!$position) {
            $extra['open_at'] = date('Y-m-d H:i:s');
            $extra['close_at'] = null;
        }

        if ($position && !$position?->open_at && $position?->status === 'open' && $data['status'] === 'open') {
            $extra['open_at'] = date('Y-m-d H:i:s');
            $extra['close_at'] = null;
        }

        Positions::updateOrCreate(
            [
                'user_id' => $this->bot->getUserId(),
                'symbol_id' => $data['symbolId'],
                'side' => $data['side']
            ],
            [
                'leverage' => $data['leverage'],
                'entry_price' => (float) $data['entryPrice'],
                'break_even_price' => (float) $data['breakEvenPrice'],
                'size' => $data['size'],
                'pnl_roi_percent' => $data['roiPercent'],
                'pnl_roi_value' => (float) $data['unRealizedProfit'],
                'pnl_account_percent' => (float) $data['pnlAccountPercent'],
                'margin_account_percent' => $data['marginAccountPercent'],
                'margin_symbol_percent' => $data['marginSymbolPercent'],
                'mark_price' => (float) $data['markPrice'],
                'liquid_price' => (float) $data['liquidationPrice'],
                'margin_type' => $data['type'],
                'status' => $data['status'],
            ]+$extra
        );
    }

    /**
     * Update symbol
     *
     * @param string $symbol
     * @param float $minQuantity
     * @return object|null
     */
    public function updateSymbol(string $symbol, float $minQuantity): void
    {
        Symbols::where([
            'bot_id' => $this->bot->getId(),
            'pair' => $symbol,
        ])->update([
            'min_quantity' => $minQuantity
        ]);
    }

    /**
     * Update cycle
     *
     * @param float $currentValue
     * @param float $targetPercentage
     * @return void
     */
    private function updateCycle(float $currentValue, float $targetPercentage): void
    {
        $$targetPercentage /= 100;
        $targetValue = $currentValue + ($currentValue * $targetPercentage);

        if ($accountCycle = $this->getCurrentCycle()) {
            if (!$accountCycle->done) {
                $accountCycle->current_value = $currentValue;

                if ($accountCycle->current_value >= $accountCycle->target_value) {
                    $accountCycle->done = true;

                    $symbols = Symbols::where([
                        'bot_id' => $this->bot->getId(),
                        'status' => 'active'
                    ])->get();

                    foreach ($symbols as $symbol) {
                        if ($symbolExchange = $this->getSymbolExchange($symbol->pair)) {
                            $baseQuantity = (float) $symbol->base_quantity;
                            $baseQuantity = round($baseQuantity + ($baseQuantity * $targetPercentage), (int) $symbolExchange['quantityPrecision']);

                            $symbol->base_quantity = $baseQuantity;
                            $symbol->save();
                        }
                    }
                }

                $accountCycle->save();
            }
        } else {
            AccountValueCycle::create([
                'bot_id' => $this->bot->getId(),
                'period' => date('Y-m-d H:00:00'),
                'current_value' => $currentValue,
                'target_value' => $targetValue,
                'done' => false,
            ]);
        }
    }

    /**
     * Get current cycle
     *
     * @return mixed
     */
    public function getCurrentCycle(): mixed
    {
        $accountCycle = AccountValueCycle::where([
            'bot_id' => $this->bot->getId(),
            'period' => date('Y-m-d H:00:00')
        ])->first();

        return $accountCycle;
    }

    /**
     * Get symbol exchange
     *
     * @param string $symbol
     * @return array
     */
    private function getSymbolExchange(string $symbol): array
    {
        if (!$this->exchangeInfo) {
            $this->exchangeInfo = $this->bot->getExchange()->getExchangeInfo();
        }

        $symbolInfo = array_filter($this->exchangeInfo['symbols'], fn($info) => $info['symbol'] === $symbol);

        if ($symbolInfo) {
            return current($symbolInfo);
        }

        return [];
    }

    /**
     * Get symbol filter
     *
     * @param array $symbolInfo
     * @param string $type
     * @return array
     */
    private function getSymbolFilter(array $filters, string $type): array
    {
        $filters = array_filter($filters, fn($filter) => $filter['filterType'] === $type);

        if ($filters) {
            return current($filters);
        }

        return [];
    }
}
