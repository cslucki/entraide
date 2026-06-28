<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OrganizationRequest;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminOrganizationRequestController extends Controller
{
    public function index(Request $request): View
    {
        $query = OrganizationRequest::with('user:id,name,email');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('boucle_name', 'like', '%'.$search.'%')
                    ->orWhere('contact_name', 'like', '%'.$search.'%')
                    ->orWhere('contact_email', 'like', '%'.$search.'%')
                    ->orWhere('contact_phone', 'like', '%'.$search.'%');
            });
        }

        $requests = $query->latest()->paginate(25)->withQueryString();

        return view('admin.organization-requests.index', compact('requests'));
    }
}
