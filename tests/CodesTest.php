<?php

namespace Oriclab\EInvoice\Tests;

use Oriclab\EInvoice\Codes;

class CodesTest extends TestCase
{
    public function test_tables(): void
    {
        $this->assertSame('Others', Codes::classifications()['022']);
        $this->assertSame('Service Tax', Codes::taxTypes()['02']);
    }

    public function test_state_resolves_loose_names(): void
    {
        $this->assertSame('10', Codes::state('selangor'));
        $this->assertSame('10', Codes::state('10'));
        $this->assertSame('14', Codes::state('W.P. Kuala Lumpur'));
        $this->assertSame('14', Codes::state('Kuala Lumpur'));
        $this->assertSame('07', Codes::state('Penang'));
        $this->assertSame('16', Codes::state('Wilayah Persekutuan Putrajaya'));
        $this->assertNull(Codes::state('Bavaria'));
        $this->assertNull(Codes::state(null));
    }
}
