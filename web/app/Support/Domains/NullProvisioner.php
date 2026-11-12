<?php

namespace App\Support\Domains;

use App\Models\CustomDomain;

/**
 * For installs where nothing has to be set up per domain, for example when
 * the proxy in front issues certificates on demand.
 */
class NullProvisioner implements Provisioner
{
    public function provision(CustomDomain $domain): void
    {
        //
    }

    public function remove(CustomDomain $domain): void
    {
        //
    }

    public function issuesCertificates(): bool
    {
        return false;
    }
}
