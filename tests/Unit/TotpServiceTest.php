<?php

namespace Tests\Unit;

use App\Services\TotpService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TotpServiceTest extends TestCase
{
    #[Test]
    public function it_verifies_the_rfc_6238_sha1_vector_using_six_digits(): void
    {
        $service = new TotpService;

        $this->assertTrue($service->verify('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '287082', 0, 59));
        $this->assertFalse($service->verify('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', '287083', 0, 59));
    }
}
