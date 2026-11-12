<?php

namespace App\Concerns;

use Attribute;
use Illuminate\Support\Facades\Auth;
use Livewire\Attribute as LivewireAttribute;

/**
 * For Livewire components of the admin area: checks the admin role on every
 * request, not only when the page is opened, so an admin whose role was taken
 * away cannot keep working through a page that is still open.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class RequiresAdmin extends LivewireAttribute
{
    public function boot(): void
    {
        // 404 like the admin middleware, so the area does not announce itself.
        abort_unless(Auth::user()?->isAdmin() ?? false, 404);
    }
}
