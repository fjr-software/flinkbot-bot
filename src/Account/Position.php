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

            foreach ($positions as $position) {
                $marginSymbol = abs((float) $position['notional']) / $position['leverage'];
                $marginSymbolPercent = 100 - $this->bot->getExchange()->percentage((float) $account['totalWalletBalance'], (float) $marginSymbol);

                $type = $position['marginType'] === 'cross' ? 'CROSSED' : 'ISOLATED';
                $size = abs((float) $position['positionAmt']);
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

                $data = [
                    'symbolId' => $symbolInfo->id,
                    'side' => $position['positionSide'],
                    'entryPrice' => (float) $position['entryPrice'],
                    'size' => $size,
                    'roiPercent' => $roiPercent,
                    'unRealizedProfit' => (float) $position['unRealizedProfit'],
                    'marginAccountPercent' => $marginAccountPercent,
                    'marginSymbolPercent' => $marginSymbolPercent,
                    'markPrice' => (float) $position['markPrice'],
                    'liquidationPrice' => (float) $position['liquidationPrice'],
                    'type' => $type,
                    'status' => $status,
                ];

                $this->updateOrCreate($data);
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
                'side' => $data['positionSide']
            ],
            [
                'entry_price' => (float) $data['entryPrice'],
                'size' => $data['size'],
                'pnl_roi_percent' => $data['roiPercent'],
                'pnl_roi_value' => (float) $data['unRealizedProfit'],
                'margin_account_percent' => $data['marginAccountPercent'],
                'margin_symbol_percent' => $data['marginSymbolPercent'],
                'mark_price' => (float) $data['markPrice'],
                'liquid_price' => (float) $data['liquidationPrice'],
                'margin_type' => $data['type'],
                'status' => $data['status'],
            ]
        );
    }
}
