<?php

namespace Tests\Feature\Accounting;

use App\Support\Accounting\ChartOfAccounts;
use Tests\TestCase;

/**
 * The indicative myDATA-category → ΕΓΛΣ-account default mapping.
 */
class ChartOfAccountsTest extends TestCase
{
    public function test_income_categories_map_to_group_7_accounts(): void
    {
        $this->assertSame('70', ChartOfAccounts::codeFor('category1_1')); // εμπορεύματα
        $this->assertSame('71', ChartOfAccounts::codeFor('category1_2')); // προϊόντα
        $this->assertSame('73', ChartOfAccounts::codeFor('category1_3')); // υπηρεσίες
    }

    public function test_expense_categories_map_to_their_accounts(): void
    {
        $this->assertSame('20', ChartOfAccounts::codeFor('category2_1')); // αγορές εμπορευμάτων
        $this->assertSame('61', ChartOfAccounts::codeFor('category2_3')); // λήψη υπηρεσιών
        $this->assertSame('60', ChartOfAccounts::codeFor('category2_6')); // μισθοδοσία
        $this->assertSame('14', ChartOfAccounts::codeFor('category2_7')); // πάγια
        $this->assertSame('66', ChartOfAccounts::codeFor('category2_8')); // αποσβέσεις
    }

    public function test_unmapped_and_empty_return_null(): void
    {
        $this->assertNull(ChartOfAccounts::codeFor(null));
        $this->assertNull(ChartOfAccounts::codeFor(''));
        $this->assertNull(ChartOfAccounts::codeFor('category1_95')); // informational, no account
        $this->assertNull(ChartOfAccounts::codeFor('nonsense'));
    }

    public function test_account_for_returns_code_and_name(): void
    {
        $this->assertSame(
            ['code' => '73', 'name' => 'Πωλήσεις υπηρεσιών'],
            ChartOfAccounts::accountFor('category1_3'),
        );
        $this->assertNull(ChartOfAccounts::accountFor('category1_95'));
    }

    public function test_chart_is_non_empty_and_well_formed(): void
    {
        $chart = ChartOfAccounts::chart();

        $this->assertNotEmpty($chart);
        foreach ($chart as $account) {
            $this->assertArrayHasKey('code', $account);
            $this->assertArrayHasKey('name', $account);
            $this->assertNotSame('', $account['name']);
        }
    }
}
