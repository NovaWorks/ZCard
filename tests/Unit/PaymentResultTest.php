<?php

namespace Tests\Unit;

use App\Payment\PaymentResult;
use Tests\TestCase;

class PaymentResultTest extends TestCase
{
    public function test_holds_currency_and_amount_sent(): void
    {
        $r = new PaymentResult(PaymentResult::TYPE_FORM, formHtml: '<x/>', currencySent: 'USD', amountSent: 175);
        $arr = $r->toArray();
        $this->assertSame('USD', $arr['currency_sent']);
        $this->assertSame(175, $arr['amount_sent']);
    }

    public function test_currency_amount_default_null(): void
    {
        $r = PaymentResult::redirect('http://x');
        $this->assertNull($r->currencySent);
        $this->assertNull($r->amountSent);
    }
}
