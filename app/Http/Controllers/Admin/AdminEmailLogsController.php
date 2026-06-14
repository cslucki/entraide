<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminEmailLogsController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->input('status');
        $search = $request->input('search');

        $logs = EmailLog::with(['template:id,slug,name', 'user:id,name,email'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($search, fn ($q) => $q->where('to_email', 'like', "%{$search}%")
                ->orWhere('subject', 'like', "%{$search}%"))
            ->orderBy('created_at', 'desc')
            ->paginate(30);

        $stats = [
            'total' => EmailLog::count(),
            'sent' => EmailLog::where('status', 'sent')->count(),
            'failed' => EmailLog::where('status', 'failed')->count(),
        ];

        return view('admin.email-logs.index', compact('logs', 'stats', 'status', 'search'));
    }

    public function show(EmailLog $emailLog): View
    {
        $emailLog->load(['template', 'user']);

        return view('admin.email-logs.show', compact('emailLog'));
    }
}
