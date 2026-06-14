<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class AdminEmailController extends Controller
{
    public function index(): View
    {
        $mailer = config('mail.default');
        $fromAddress = config('mail.from.address');

        return view('admin.email-test.index', compact('mailer', 'fromAddress'));
    }

    public function send(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'to' => 'required|email',
            'subject' => 'required|string|max:200',
            'body' => 'required|string|max:2000',
        ]);

        try {
            Mail::html(
                nl2br(e($data['body'])),
                function ($message) use ($data) {
                    $message
                        ->to($data['to'])
                        ->subject($data['subject']);
                }
            );

            EmailLog::create([
                'to_email' => $data['to'],
                'subject' => $data['subject'],
                'status' => 'sent',
                'data' => ['source' => 'admin-test', 'driver' => config('mail.default')],
            ]);

            $driver = config('mail.default');

            return back()->with('success', "Email envoyé à {$data['to']} via le driver « {$driver} ».");
        } catch (\Exception $e) {
            EmailLog::create([
                'to_email' => $data['to'],
                'subject' => $data['subject'],
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'data' => ['source' => 'admin-test', 'driver' => config('mail.default')],
            ]);

            return back()->with('error', 'Erreur lors de l\'envoi : '.$e->getMessage())->withInput();
        }
    }
}
