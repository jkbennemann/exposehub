<?php

namespace App\Support\Domains;

use App\Models\CustomDomain;

/**
 * Makes the infrastructure in front of the tunnel server accept a team's own
 * domain, which mostly means obtaining a certificate for it. What that takes
 * depends on how an install terminates TLS, so it is a driver.
 */
interface Provisioner
{
    /**
     * Start serving the domain. Safe to call again for a domain already served.
     */
    public function provision(CustomDomain $domain): void;

    /**
     * Stop serving the domain. Safe to call for a domain that is not served.
     */
    public function remove(CustomDomain $domain): void;

    /**
     * Whether this driver does anything, so pages know what to tell people.
     */
    public function issuesCertificates(): bool;
}
