<?php

namespace Tests\Unit;

use App\Support\Access\Cidr;
use PHPUnit\Framework\TestCase;

class CidrTest extends TestCase
{
    public function test_addresses_and_ranges_are_normalised_like_the_tunnel_server_reads_them(): void
    {
        $this->assertSame('203.0.113.7/32', Cidr::normalize('203.0.113.7'));
        $this->assertSame('198.51.100.0/24', Cidr::normalize(' 198.51.100.9/24 '));
        $this->assertSame('2001:db8:42::/48', Cidr::normalize('2001:db8:42:1::5/48'));
        $this->assertSame('2001:db8::1/128', Cidr::normalize('2001:DB8::1'));
        $this->assertSame('203.0.113.0/24', Cidr::normalize('::ffff:203.0.113.9/120'));
        $this->assertSame('0.0.0.0/0', Cidr::normalize('1.2.3.4/0'));
    }

    public function test_nonsense_is_refused(): void
    {
        foreach (['', 'vpn.example.com', '203.0.113.300', '203.0.113.0/33', '2001:db8::/129', '203.0.113.0/x', '::ffff:1.2.3.4/90'] as $bad) {
            $this->assertNull(Cidr::normalize($bad), $bad);
        }
    }

    public function test_containment_and_private_ranges(): void
    {
        $this->assertTrue(Cidr::contains('198.51.100.0/24', '198.51.100.77'));
        $this->assertFalse(Cidr::contains('198.51.100.0/24', '198.51.101.1'));
        $this->assertFalse(Cidr::contains('198.51.100.0/24', '2001:db8::1'));
        $this->assertTrue(Cidr::isPrivate('10.0.0.0/8'));
        $this->assertTrue(Cidr::isPrivate('192.168.1.5/32'));
        $this->assertFalse(Cidr::isPrivate('203.0.113.0/24'));
        $this->assertTrue(Cidr::isEveryone('::/0'));
    }
}
