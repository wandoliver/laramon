<?php

namespace Tests\Unit;

use App\Support\Brand;
use PHPUnit\Framework\TestCase;

class BrandTest extends TestCase
{
    public function test_multi_word_names_accent_the_last_word(): void
    {
        $this->assertSame(['Acme ', 'Monitor'], Brand::parts('Acme Monitor'));
        $this->assertSame(['Acme Fleet ', 'Watch'], Brand::parts('Acme Fleet Watch'));
    }

    public function test_camel_case_names_accent_the_trailing_hump(): void
    {
        $this->assertSame(['Lara', 'Mon'], Brand::parts('LaraMon'));
    }

    public function test_single_plain_word_has_no_accent(): void
    {
        $this->assertSame(['Monitor', ''], Brand::parts('Monitor'));
        $this->assertSame(['laramon', ''], Brand::parts('laramon'));
    }
}
