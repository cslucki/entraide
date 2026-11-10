<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EmailLog;
use App\Support\Notifications\EmailLogContextResolver;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminEmailLogsController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->input('status');
        $search = $request->input('search');
        // TASK-1659 : arriver directement sur les emails d'UNE fonctionnalite
        // (ex. « Voir l'historique des emails » depuis /admin/users/bulk-create).
        // `data->source` est pose par chaque mailer du depot.
        $source = $request->input('source');

        // TASK-1800 correctif 3 — `banned_at`/`is_admin` AU SELECT : le
        // partial `se-connecter-sous` (TASK-1795 correctif 7) en a besoin
        // pour sa PROPRE garde. Les en omettre les rendrait `null` et la
        // garde laisserait alors passer un lien qu'elle doit refuser — voir
        // le docblock du partial.
        $logs = EmailLog::with(['template:id,slug,name', 'user:id,name,first_name,email,banned_at,is_admin'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($source, fn ($q) => $q->where('data->source', $source))
            // Groupe a dessein : sans parentheses, le `orWhere` s'evaderait
            // des filtres ci-dessus et ramenerait des lignes hors perimetre.
            ->when($search, fn ($q) => $q->where(fn ($sub) => $sub->where('to_email', 'like', "%{$search}%")
                ->orWhere('subject', 'like', "%{$search}%")))
            ->orderBy('created_at', 'desc')
            ->paginate(30)
            ->withQueryString();

        // TASK-1800 — le "Contexte" (Boucle, article, message prive,
        // entraide, ou autre evenement) est resolu EN MASSE pour la page
        // affichee, jamais ligne par ligne : voir le docblock de
        // `EmailLogContextResolver`. L'admin COURANT est transmis : certains
        // liens ne sont offerts que s'il appartient a la meme Organization
        // que la ligne (voir `urlSiJoignable()`).
        $contextes = app(EmailLogContextResolver::class)->resoudrePour(collect($logs->items()), $request->user());

        $stats = [
            'total' => EmailLog::count(),
            'sent' => EmailLog::where('status', 'sent')->count(),
            'failed' => EmailLog::where('status', 'failed')->count(),
        ];

        return view('admin.email-logs.index', compact('logs', 'stats', 'status', 'search', 'source', 'contextes'));
    }

    public function show(EmailLog $emailLog): View
    {
        $emailLog->load(['template', 'user']);

        return view('admin.email-logs.show', compact('emailLog'));
    }
}
