<?php

declare(strict_types=1);

namespace FjrSoftware\Flinkbot\Bot\Account;

use FjrSoftware\Flinkbot\Bot\Model\Positions;
use FjrSoftware\Flinkbot\Bot\Model\Symbols;

class Position
{
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
            $marginAccountPercent = 100 - $this->bot->getExchange()->percentage((float) $account['totalMarginBalance'], (float) $account['totalMaintMargin']);
            $pnlAccountPercent = 100 - $this->bot->getExchange()->percentage((float) $account['totalWalletBalance'], (float) $account['totalUnrealizedProfit']);

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
        $positions = Positions::where(['user_id' => $this->bot->getUserId()])->get();
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
            ]
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
