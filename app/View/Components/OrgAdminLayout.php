<?php

namespace App\View\Components;

use App\Models\Organization;
use Illuminate\View\Component;
use Illuminate\View\View;

class OrgAdminLayout extends Component
{
    public function __construct(
        public string $title,
        public Organization $organization,
    ) {}

    public function render(): View
    {
        return view('layouts.org-admin');
    }
}
