<?php

namespace App\Http\Controllers;

use App\Models\BlogPost;
use App\Models\DossierBlogPost;
use App\Models\Loop;
use App\Services\Loops\LoopRootDocumentService;
use App\Support\Loops\LoopPermissionResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * « Ecrire un article » depuis la Card Dossiers d'une Boucle.
 *
 * Un seul geste, trois liens poses d'un coup : le brouillon nait dans
 * l'Organization de la Boucle, entre dans son Dossier racine
 * (`dossier_blog_posts`, la meme ligne que l'ecran du Dossier) et se lie a la
 * Boucle (`blog_post_loop`, la meme ligne que le panneau Boucle de l'editeur).
 * Puis l'editeur Blog **existant** s'ouvre — aucun second editeur, et l'ecran
 * d'apres montre deja le Dossier et la Boucle remplis.
 *
 * Les gardes sont celles des parcours reutilises : la capacite
 * `dossiers.create_article` du resolveur (celle que la Card consulte pour
 * montrer le bouton) et `update` sur le Dossier — qui, pour un Dossier racine,
 * delegue a la Boucle et refuse donc l'archivee.
 */
class LoopDossierArticleController extends Controller
{
    public function store(Request $request, string $organization, Loop $loop): RedirectResponse
    {
        $current = currentOrganization();

        abort_unless($current !== null && $loop->organization_id === $current->id, 404);

        $user = $request->user();

        abort_unless($user && $user->organization_id === $current->id, 404);

        /* TASK-1872 correctif 04 — UNE SEULE AUTORITE POUR CE GESTE.
           ==========================================================
           Avant : une lecture directe du resolveur
           (`dossiers.create_article`) PUIS `authorize('update')`. La seconde
           delegue a `LoopPolicy::update()`, qui exige strictement le role
           `owner` — un membre autorise a ecrire se serait donc fait refuser
           la ou le produit vient de lui ouvrir la porte.
           `DossierPolicy::createArticle()` porte desormais les deux : elle
           refuse la Boucle archivee comme le faisait `update()`, et elle
           laisse passer le membre actif qui tient `writing.compose`.

           Le pre-controle d'appartenance reste AVANT la resolution du
           Dossier : `ensureRootDossier()` ECRIT quand le Dossier racine
           n'existe pas encore, et cela ne doit pas etre declenchable par
           quelqu'un qui n'a rien a faire dans cette Boucle. */
        abort_unless(
            app(LoopPermissionResolver::class)->can($user, $loop, 'writing.compose')
            || app(LoopPermissionResolver::class)->can($user, $loop, 'dossiers.create_article'),
            403
        );

        $dossier = app(LoopRootDocumentService::class)->ensureRootDossier($loop);

        $this->authorize('createArticle', $dossier);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
        ]);

        $post = DB::transaction(function () use ($user, $current, $loop, $dossier, $data) {
            // La meme grammaire d'unicite que LoopRootDocumentService : deux
            // « Notes » dans deux Boucles ne doivent pas se heurter a l'index.
            $base = Str::slug($data['title']) ?: 'article';
            $slug = $base;

            while (BlogPost::withTrashed()->where('slug', $slug)->exists()) {
                $slug = $base.'-'.Str::lower(Str::random(6));
            }

            $post = BlogPost::create([
                'user_id' => $user->id,
                'organization_id' => $current->id,
                'title' => $data['title'],
                'slug' => $slug,
                'content' => '<p></p>',
                'status' => 'draft',
            ]);

            DossierBlogPost::create([
                'organization_id' => $current->id,
                'dossier_id' => $dossier->id,
                'blog_post_id' => $post->id,
                'added_by' => $user->id,
                'position' => ((int) $dossier->dossierBlogPosts()->max('position')) + 1,
            ]);

            $post->loops()->attach($loop->id);

            return $post;
        });

        return redirect()->route('organization.blog.edit', [
            'organization' => $organization,
            'post' => $post->slug,
        ]);
    }
}
