<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Illuminate\View\View;

class ExplorerController extends Controller
{
    public function index(): View
    {
        $organization = currentOrganization();
        $categories = $organization
            ? Category::where('organization_id', $organization->id)->get()
            : collect();

        return view('explorer', compact('categories'));
    }
}
