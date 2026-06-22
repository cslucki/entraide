<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\ServiceRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class RequestController extends Controller
{
    public function show(ServiceRequest $request): View
    {
        $organization = currentOrganization();
        if (! $organization || $request->organization_id !== $organization->id) {
            abort(404);
        }

        $request->load(['user', 'category', 'attachments']);

        $ogTitle = $request->title;
        $ogDescription = Str::limit(strip_tags($request->description), 160);
        $ogImage = null;

        return view('requests.show', compact('request', 'ogTitle', 'ogDescription', 'ogImage'));
    }

    public function create(): View
    {
        $organization = currentOrganization();
        $categories = Category::where('organization_id', $organization?->id)->with('pointGuidelines')->get();

        return view('requests.create', compact('categories'));
    }

    public function store(Request $httpRequest): RedirectResponse
    {
        $organization = currentOrganization();
        if (! $organization) {
            abort(404);
        }

        $data = $httpRequest->validate([
            'title' => 'required|string|min:10|max:255',
            'description' => 'required|string|min:100',
            'category_id' => 'required|uuid|exists:categories,id',
            'delivery_mode' => 'required|in:remote,onsite,both',
            'budget_min' => 'required|integer|min:1',
            'budget_max' => 'nullable|integer|gte:budget_min',
            'deadline' => 'nullable|date|after:today',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx|max:10240',
        ]);

        $serviceRequest = ServiceRequest::create([
            'user_id' => auth()->id(),
            'organization_id' => $organization->id,
            'title' => $data['title'],
            'description' => $data['description'],
            'category_id' => $data['category_id'],
            'delivery_mode' => $data['delivery_mode'],
            'budget_min' => $data['budget_min'],
            'budget_max' => $data['budget_max'] ?? null,
            'deadline' => $data['deadline'] ?? null,
            'status' => 'open',
        ]);

        $this->storeAttachments($httpRequest, $serviceRequest);

        return redirect()->route('dashboard')->with('success', 'Demande publiée avec succès.');
    }

    private function storeAttachments(Request $httpRequest, ServiceRequest $serviceRequest): void
    {
        if (! $httpRequest->hasFile('attachments')) {
            return;
        }
        foreach ($httpRequest->file('attachments') as $index => $file) {
            $path = $file->store('request-attachments', 'public');
            $serviceRequest->attachments()->create([
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'order' => $index,
            ]);
        }
    }

    public function destroy(ServiceRequest $request): RedirectResponse
    {
        $organization = currentOrganization();
        if (! $organization || $request->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('delete', $request);

        $request->update(['status' => 'closed']);

        return redirect()->route('dashboard')->with('success', 'Demande fermée.');
    }
}
