<?php

namespace app\service;

class AmountParser
{
    /**
     * @return array{amount:string,status:string,candidates:array<int,string>}
     */
    public function parse(array $ocrOutputs): array
    {
        $candidates = [];

        foreach ($ocrOutputs as $output) {
            $normalized = strtr((string) $output, [
                '。' => '.',
                '．' => '.',
                '，' => ',',
            ]);

            preg_match_all('/(?<!\d)(\d{1,6}[\.,]\d{2})(?!\d)/u', $normalized, $matches);
            foreach ($matches[1] ?? [] as $match) {
                $value = (float) str_replace(',', '.', $match);
                if ($value <= 0) {
                    continue;
                }

                $amount = number_format($value, 2, '.', '');
                if (!in_array($amount, $candidates, true)) {
                    $candidates[] = $amount;
                }
            }
        }

        return [
            'amount' => count($candidates) === 1 ? $candidates[0] : '',
            'status' => count($candidates) === 1 ? 'detected' : 'manual',
            'candidates' => $candidates,
        ];
    }
}
