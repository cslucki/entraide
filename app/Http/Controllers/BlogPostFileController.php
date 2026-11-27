<?php

namespace App\Http\Controllers;

use App\Models\BlogPostFile;
use App\Services\Blog\BlogPostReadAccess;
use App\Services\Dossiers\DossierFileTransport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * TASK-1907 — la SEULE porte vers les fichiers prives d'un article (PDF, audio).
 *
 * Le disque `blog_files` n'a ni `serve` ni `url`. Les droits de lecture sont
 * ceux de l'article (`BlogPostReadAccess`, la meme regle que la page) ; un
 * fichier pas encore rattache n'est lu que par son deposant. Tout refus rend
 * un 404, comme la page de l'article : un 403 confirmerait l'existence.
 */
class BlogPostFileController extends Controller
{
    /**
     * Types acceptes, reconnus sur le CONTENU du fichier (finfo), jamais sur
     * l'extension ni sur le type annonce par le navigateur.
     * Valeur : [type de fichier, extension stockee, type servi].
     */
    private const ACCEPTED = [
        'application/pdf' => [BlogPostFile::KIND_PDF, 'pdf', 'application/pdf'],
        'audio/mpeg' => [BlogPostFile::KIND_AUDIO, 'mp3', 'audio/mpeg'],
        'audio/mp3' => [BlogPostFile::KIND_AUDIO, 'mp3', 'audio/mpeg'],
        'audio/x-m4a' => [BlogPostFile::KIND_AUDIO, 'm4a', 'audio/mp4'],
        'audio/mp4' => [BlogPostFile::KIND_AUDIO, 'm4a', 'audio/mp4'],
    ];

    /** Plafonds decides par Cyril (27/11/2026), en Ko. */
    private const MAX_KB = [
        BlogPostFile::KIND_PDF => 20 * 1024,
        BlogPostFile::KIND_AUDIO => 30 * 1024,
    ];

    public function __construct(
        private readonly DossierFileTransport $transport,
        private readonly BlogPostReadAccess $readAccess,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'max:'.max(self::MAX_KB)],
        ]);

        $upload = $request->file('file');
        // finfo sur le fichier lui-meme : `getMimeType()` peut se fier au nom.
        $detected = (string) (new \finfo(FILEINFO_MIME_TYPE))->file((string) $upload->getRealPath());

        // Un .m4a est un conteneur MP4 : finfo le voit parfois en `video/mp4`.
        // Accepte seulement quand l'extension d'origine dit bien « m4a ».
        if ($detected === 'video/mp4' && strtolower($upload->getClientOriginalExtension()) === 'm4a') {
            $detected = 'audio/mp4';
        }

        $accepted = self::ACCEPTED[$detected] ?? null;

        if ($accepted === null) {
            return response()->json(['message' => __('Seuls les fichiers PDF, MP3 et M4A sont acceptés.')], 422);
        }

        [$kind, $extension, $mime] = $accepted;

        if ($upload->getSize() > self::MAX_KB[$kind] * 1024) {
            return response()->json([
                'message' => __('Fichier trop volumineux (maximum :max Mo).', ['max' => self::MAX_KB[$kind] / 1024]),
            ], 422);
        }

        $organization = currentOrganization();
        abort_unless($organization, 404);

        $path = sprintf('%s/%s.%s', $organization->id, Str::uuid(), $extension);
        $stored = $upload->storeAs(dirname($path), basename($path), BlogPostFile::DISK);
        abort_unless($stored, 500);

        $file = BlogPostFile::create([
            'organization_id' => $organization->id,
            'user_id' => $request->user()->id,
            'kind' => $kind,
            'disk' => BlogPostFile::DISK,
            'path' => $path,
            'original_name' => Str::limit($this->safeName($upload->getClientOriginalName()), 200, ''),
            'mime' => $mime,
            'size' => (int) $upload->getSize(),
        ]);

        return response()->json([
            'id' => $file->id,
            'kind' => $file->kind,
            'name' => $file->original_name,
            'size' => $file->size,
            'url' => $this->url($file),
        ], 201);
    }

    public function show(Request $request, BlogPostFile $file): RedirectResponse|StreamedResponse|BinaryFileResponse
    {
        $organization = currentOrganization();
        abort_unless($organization && $file->organization_id === $organization->id, 404);
        abort_unless($this->mayRead($file, $request), 404);

        $response = $this->pdfStreamedFromRemoteDisk($file)
            ?? $this->transport->apercuDepuis($file->disk, $file->path, $file->original_name, $file->mime);

        if (! $response instanceof RedirectResponse) {
            $response->headers->set('X-Content-Type-Options', 'nosniff');
            $response->headers->set('Cache-Control', 'private, max-age=0, must-revalidate');
        }

        return $response;
    }

    public function orgStore(Request $request, string $org): JsonResponse
    {
        return $this->store($request);
    }

    public function orgShow(Request $request, string $org, BlogPostFile $file): RedirectResponse|StreamedResponse|BinaryFileResponse
    {
        return $this->show($request, $file);
    }

    /**
     * Sur un disque distant (R2 en PROD), le transport commun REDIRIGE vers une
     * URL signee. Un lecteur `<audio>` suit sans souci ; PDF.js, lui, lit le
     * fichier en `fetch()` : une redirection vers un autre domaine exigerait
     * une regle CORS sur le bucket. Un PDF (20 Mo au plus) passe donc par
     * l'application, en flux : aucune configuration R2 a toucher.
     */
    private function pdfStreamedFromRemoteDisk(BlogPostFile $file): ?StreamedResponse
    {
        if ($file->kind !== BlogPostFile::KIND_PDF || config("filesystems.disks.{$file->disk}.driver") === 'local') {
            return null;
        }

        $disk = Storage::disk($file->disk);
        abort_unless($disk->exists($file->path), 404);

        return $disk->response($file->path, $file->original_name, [
            'Content-Type' => 'application/pdf',
        ], 'inline');
    }

    private function mayRead(BlogPostFile $file, Request $request): bool
    {
        $user = $request->user();

        if ($file->blog_post_id === null) {
            return $user !== null && $file->user_id === $user->id;
        }

        $post = $file->blogPost;

        return $post !== null && $this->readAccess->allows($post, $user);
    }

    private function url(BlogPostFile $file): string
    {
        $organization = request()->route('organization');

        return $organization
            ? route('organization.blog.files.show', ['organization' => $organization, 'file' => $file->id])
            : route('blog.files.show', ['file' => $file->id]);
    }

    /** Un nom d'origine sans chemin ni caracteres de controle. */
    private function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';

        return $name !== '' ? $name : 'fichier';
    }
}
