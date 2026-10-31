<?php

namespace Tests\Unit;

use App\Banking\BankTransaction;
use App\Enums\BankStatus;
use Tests\TestCase;

class BankTransactionTest extends TestCase
{
    public function test_amounts_become_integer_cents_without_floats(): void
    {
        $this->assertSame(1809, BankTransaction::cents('18.09'));
        $this->assertSame(1230, BankTransaction::cents('12.3'));
        $this->assertSame(10000, BankTransaction::cents('100'));
        $this->assertSame(-456, BankTransaction::cents('-4.56'));
        $this->assertSame(1, BankTransaction::cents('0.01'));
    }

    public function test_a_debit_is_an_outflow_and_a_credit_an_inflow(): void
    {
        $debit = BankTransaction::fromApi(['transaction_amount' => ['amount' => '18.09'], 'credit_debit_indicator' => 'DBIT', 'status' => 'BOOK', 'booking_date' => '2026-10-22', 'remittance_information' => ['X']]);
        $credit = BankTransaction::fromApi(['transaction_amount' => ['amount' => '-5.00'], 'credit_debit_indicator' => 'CRDT', 'status' => 'PDNG', 'transaction_date' => '2026-10-22', 'remittance_information' => ['Y']]);

        $this->assertSame(-1809, $debit?->amount);
        $this->assertSame(BankStatus::Booked, $debit->status);
        $this->assertSame(500, $credit?->amount);
        $this->assertSame(BankStatus::Pending, $credit->status);
    }

    public function test_cancelled_rejected_and_scheduled_transactions_are_skipped(): void
    {
        foreach (['CNCL', 'RJCT', 'SCHD'] as $status) {
            $this->assertNull(BankTransaction::fromApi(['transaction_amount' => ['amount' => '1.00'], 'status' => $status]));
        }
    }

    public function test_the_label_falls_back_to_the_counterparty(): void
    {
        $transaction = BankTransaction::fromApi([
            'transaction_amount' => ['amount' => '30.00'],
            'credit_debit_indicator' => 'DBIT',
            'status' => 'BOOK',
            'booking_date' => '2026-10-22',
            'creditor' => ['name' => 'PHARMACIE DU CENTRE'],
            'remittance_information' => [],
        ]);

        $this->assertSame('PHARMACIE DU CENTRE', $transaction?->label);
    }

    public function test_the_entry_reference_is_preferred_to_the_transaction_id(): void
    {
        $data = ['transaction_amount' => ['amount' => '1.00'], 'status' => 'BOOK', 'booking_date' => '2026-10-22'];

        $this->assertSame('E1', BankTransaction::fromApi([...$data, 'entry_reference' => 'E1', 'transaction_id' => 'T1'])?->externalId);
        $this->assertSame('T1', BankTransaction::fromApi([...$data, 'entry_reference' => null, 'transaction_id' => 'T1'])?->externalId);
        $this->assertNull(BankTransaction::fromApi($data)?->externalId);
    }
}
