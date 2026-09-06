<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailLog;
use App\Models\Organization;
use App\Support\Ops\MailDiagnostics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

class AdminEmailController extends Controller
{
    public function index(MailDiagnostics $diagnostics): View
    {
        $transport = $diagnostics->transport();

        return view('admin.email-test.index', [
            'transport' => $transport,
            // Conserves pour ne rien casser de l'existant : la vue s'en sert
            // encore, et les remplacer n'apporterait rien a cette tranche.
            'mailer' => $transport['mailer'],
            'fromAddress' => $transport['from_address'],
            'mailhogCount' => $diagnostics->mailhogMessageCount(),
            'mailhogUrl' => $diagnostics->mailhogUrl(),
        ]);
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
                'organization_id' => Organization::where('is_default', true)->value('id'),
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
                'organization_id' => Organization::where('is_default', true)->value('id'),
                'data' => ['source' => 'admin-test', 'driver' => config('mail.default')],
            ]);

            return back()->with('error', 'Erreur lors de l\'envoi : '.$e->getMessage())->withInput();
        }
    }
}
