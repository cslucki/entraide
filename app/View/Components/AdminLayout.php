<?php

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\View\View;

class AdminLayout extends Component
{
    public function __construct(public string $title = 'Administration') {}

    public function render(): View
    {
        return view('layouts.admin');
    }
}
