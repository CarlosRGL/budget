<?php

namespace Tests\Unit;

use App\Banking\BankLabel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BankLabelTest extends TestCase
{
    /**
     * @return array<string, array{string, string, string|null}>
     */
    public static function labels(): array
    {
        return [
            'card payment' => ['CARTE 17/10/26 BONNEAU BOULANG CB*2594', 'BONNEAU BOULANG', '2594'],
            'card payment, four-digit year' => ['CARTE 17/10/2026 LECLERC  CB*7812', 'LECLERC', '7812'],
            'direct debit' => ['PRLV SEPA EDF CLIENTS PARTICULIERS', 'EDF CLIENTS PARTICULIERS', null],
            'transfer' => ['VIR SEPA EMPLOYEUR SAS', 'EMPLOYEUR SAS', null],
            'transfer without SEPA' => ['VIR CAMILLE', 'CAMILLE', null],
            'plain label' => ['COTISATION CARTE', 'COTISATION CARTE', null],
            'nothing left keeps the raw label' => ['VIR ', 'VIR', null],
        ];
    }

    #[DataProvider('labels')]
    public function test_default_cleaning(string $raw, string $label, ?string $cardSuffix): void
    {
        $this->assertSame(['label' => $label, 'card_suffix' => $cardSuffix], BankLabel::clean($raw));
    }

    public function test_normalized_labels_ignore_case_and_spacing(): void
    {
        $this->assertSame('CARTE BONNEAU BOULANG', BankLabel::normalize(' carte  bonneau   Boulang '));
    }
}
