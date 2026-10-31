<?php

namespace Tests\Feature\Banking;

use App\Models\BankAccount;
use Tests\TestCase;

class BankAccountNameTest extends TestCase
{
    public function test_empty_names_fall_back_to_the_next_field_then_the_iban(): void
    {
        // As sent by Boursorama: empty name and details, cards only as a product code.
        $this->assertSame('Compte joint', BankAccount::nameFrom(['name' => '', 'details' => '', 'product' => 'Compte joint']));
        $this->assertSame('Immediate debit card', BankAccount::nameFrom(['name' => '', 'details' => '', 'product' => 'immediat_debit']));
        $this->assertSame('', BankAccount::nameFrom(['name' => '', 'details' => null]));

        $this->assertSame('Account FR76 •••• 7670', (new BankAccount(['name' => '', 'iban_masked' => 'FR76 •••• 7670']))->label());
        $this->assertSame('Immediate debit card', (new BankAccount(['name' => 'immediat_debit']))->label());
    }

    public function test_an_account_without_iban_is_a_card(): void
    {
        $this->assertTrue((new BankAccount(['name' => 'immediat_debit']))->isCard());
        $this->assertFalse((new BankAccount(['name' => '', 'iban_masked' => 'FR76 •••• 7670']))->isCard());
    }
}
