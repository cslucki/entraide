<?php

use App\Http\Controllers\Admin\AdminAiBenchmarkController;
use App\Http\Controllers\Admin\AdminAiConfigController;
use App\Http\Controllers\Admin\AdminAiConstitutionController;
use App\Http\Controllers\Admin\AdminAiInteractionController;
use App\Http\Controllers\Admin\AdminAiMonetizationController;
use App\Http\Controllers\Admin\AdminAiOrganizationsController;
use App\Http\Controllers\Admin\AdminAiPromptController;
use App\Http\Controllers\Admin\AdminAiQualityController;
use App\Http\Controllers\Admin\AdminAiReviewQueueController;
use App\Http\Controllers\Admin\AdminAiSupervisionController;
use App\Http\Controllers\Admin\AdminAiUsageController;
use App\Http\Controllers\Admin\AdminBlogController;
use App\Http\Controllers\Admin\AdminBlogTodoController;
use App\Http\Controllers\Admin\AdminBugReportController;
use App\Http\Controllers\Admin\AdminCategoryController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\AdminCrmController;
use App\Http\Controllers\Admin\AdminCrmOverviewController;
use App\Http\Controllers\Admin\AdminEmailController;
use App\Http\Controllers\Admin\AdminEmailLogsController;
use App\Http\Controllers\Admin\AdminEmailTemplatesController;
use App\Http\Controllers\Admin\AdminGuestShellController;
use App\Http\Controllers\Admin\AdminIaDesignLabController;
use App\Http\Controllers\Admin\AdminIaUsageByUserController;
use App\Http\Controllers\Admin\AdminLoopController;
use App\Http\Controllers\Admin\AdminLoopPermissionController;
use App\Http\Controllers\Admin\AdminLoopTypeController;
use App\Http\Controllers\Admin\AdminMemberAiProfileController;
use App\Http\Controllers\Admin\AdminMessageController;
use App\Http\Controllers\Admin\AdminNotificationCockpitController;
use App\Http\Controllers\Admin\AdminOrganizationController;
use App\Http\Controllers\Admin\AdminDrivesController;
use App\Http\Controllers\Admin\AdminRootDestinationController;
use App\Http\Controllers\Admin\AdminOrganizationRequestController;
use App\Http\Controllers\Admin\AdminOutilsController;
use App\Http\Controllers\Admin\AdminReferralController;
use App\Http\Controllers\Admin\AdminScenarioPackController;
use App\Http\Controllers\Admin\AdminShortcutController;
use App\Http\Controllers\Admin\AdminSystemEmailTemplatesController;
use App\Http\Controllers\Admin\AdminTagController;
use App\Http\Controllers\Admin\AdminThemeController;
use App\Http\Controllers\Admin\AdminTranslationController;
use App\Http\Controllers\Admin\AdminUsageReferenceController;
use App\Http\Controllers\Admin\AdminWorkshopController;
use App\Http\Controllers\Admin\OrgAcquisitionController;
use App\Http\Controllers\Admin\OrgAdminController;
use App\Http\Controllers\Admin\OrgCrmController;
use App\Http\Controllers\Admin\OrgCrmTemplateController;
use App\Http\Controllers\Admin\OrgWorkshopController;
use App\Http\Controllers\Admin\OrgWorkshopRegistrantsController;
use App\Http\Controllers\Admin\OrgWorkshopSessionController;
use App\Http\Controllers\AgentIaController;
use App\Http\Controllers\AiAgentLoopController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\BlogAnnotationController;
use App\Http\Controllers\BlogAnnotationReplyController;
use App\Http\Controllers\BlogCoAuthorController;
use App\Http\Controllers\BlogCommentController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\BlogDossierApiController;
use App\Http\Controllers\BlogExplorerController;
use App\Http\Controllers\BlogInvitationController;
use App\Http\Controllers\BlogPostLoopController;
use App\Http\Controllers\BlogSnapshotController;
use App\Http\Controllers\BlogTodoController;
use App\Http\Controllers\BugReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DossierArticleController;
use App\Http\Controllers\DossierController;
use App\Http\Controllers\DossierFileController;
use App\Http\Controllers\DossierInsightsController;
use App\Http\Controllers\DossierMemberController;
use App\Http\Controllers\DossierSemanticSearchController;
use App\Http\Controllers\DossierSeriesController;
use App\Http\Controllers\ExplorerController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\GuestShellController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\LikeController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\LoopCatchUpController;
use App\Http\Controllers\LoopController;
use App\Http\Controllers\LoopDossierArticleController;
use App\Http\Controllers\LoopEventAgendaController;
use App\Http\Controllers\LoopInvitationController;
use App\Http\Controllers\LoopToolsController;
use App\Http\Controllers\MemberAiProfileConversationsController;
use App\Http\Controllers\MemberAiProfileInteractionController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\MyceliumController;
use App\Http\Controllers\NotificationCenterController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\OrganizationLandingController;
use App\Http\Controllers\OrganizationRequestController;
use App\Http\Controllers\PointController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RequestController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\ShortcutController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UserAiUsageController;
use App\Http\Controllers\WorkshopInterestController;
use App\Http\Controllers\WorkshopPageController;
use App\Http\Controllers\WorkshopRegistrationController;
use App\Http\Middleware\OrgAdminMiddleware;
use App\Livewire\BoundedMemberAgent;
use App\Livewire\CreateFeedPost;
use App\Livewire\EditFeedPost;
use App\Livewire\MyFeedPosts;
use App\Livewire\OrganizationFeed;
use App\Livewire\ViewFeedPost;
use App\Models\MemberAiProfile;
use Illuminate\Support\Facades\Route;

// Auth routes (loaded early so they take priority over {community} prefix)
require __DIR__.'/auth.php';

// Public routes
Route::get('/', [HomeController::class, 'index'])->name('home');

// TASK-1349 — la gouvernance IA, publique par conception. Aucune
// authentification : ce sont des principes, pas des donnees d'exploitation.
Route::get('/mycelium', [MyceliumController::class, 'index'])->name('mycelium');
// TASK-1447 — OrganizationShortcut : /s/{code} → 302 vers une destination canonique de l'Organization (Growth V2 §5).
Route::get('/s/{code}', ShortcutController::class)->middleware('throttle:60,1')->where('code', '[a-z0-9\\-]{3,32}')->name('shortcut');
Route::get('/launchpals', fn () => redirect()->to(route('organization.home', ['organization' => 'launchpals'], false), 301))
    ->name('public.launchpals');
Route::get('/demo', function () {
    $url = config('services.bouclepro_demo.url', 'https://lastprod.com/bouclepro-prototype/');
    $locale = request()->query('lang');

    if (in_array($locale, config('services.bouclepro_demo.supported_locales', []), true)) {
        $url .= (str_contains($url, '?') ? '&' : '?').'lang='.$locale;
    }

    return redirect()->away($url, 302);
})->name('public.demo');
Route::post('/locale/{locale}', [LocaleController::class, 'switch'])->name('locale.switch');
// TASK-1479 (P0 privacy) — l'annuaire et les echanges sont des surfaces
// INTERNES d'Organization. Mesure faite : elles etaient servies en HTTP 200 a
// un visiteur ANONYME, sur des Organizations privees comprises, avec noms,
// villes, biographies et affiliations. `auth` ferme l'acces anonyme,
// `organization.member` ferme le cross-tenant authentifie — les deux sont
// necessaires, et un sabotage le prouve.
Route::get('/explorer', [ExplorerController::class, 'index'])->middleware(['auth', 'organization.member'])->name('explorer');
Route::view('/about', 'about')->name('about');
Route::get('/membres', [HomeController::class, 'members'])->middleware(['auth', 'organization.member'])->name('members.index');
Route::get('/echanges', [HomeController::class, 'exchanges'])->middleware(['auth', 'organization.member'])->name('exchanges.index');
Route::redirect('/partners', '/partenaires');
Route::get('/partenaires', [HomeController::class, 'partners'])->name('partenaires.index');
Route::get('/partenaires/demande', [OrganizationRequestController::class, 'create'])->name('partenaires.request.create');
Route::post('/partenaires/demande', [OrganizationRequestController::class, 'store'])->name('partenaires.request.store');
Route::get('/boucles', [HomeController::class, 'boucles'])->name('boucles.index');
Route::redirect('/boucles/creer', '/partenaires/demande');

// Blog — public (routes fixes avant le wildcard)
Route::get('/blog', [BlogController::class, 'index'])->name('blog.index');
Route::get('/blog/categorie/{slug}', [BlogController::class, 'byCategory'])->name('blog.category');
Route::get('/blog/tag/{slug}', [BlogController::class, 'byTag'])->name('blog.tag');

// Blog — authentifié (chemins fixes AVANT le wildcard /blog/{slug})
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/blog/rediger/nouveau', [BlogController::class, 'create'])->name('blog.create');
    Route::get('/blog/mes-articles', [BlogController::class, 'myPosts'])->name('blog.my-posts');
    Route::post('/blog', [BlogController::class, 'store'])->name('blog.store');
    Route::get('/blog/rediger/{post:slug}/modifier', [BlogController::class, 'edit'])->name('blog.edit');
    Route::put('/blog/{post:slug}', [BlogController::class, 'update'])->name('blog.update');
    Route::patch('/blog/{post:slug}/publier', [BlogController::class, 'publish'])->name('blog.publish');
    Route::delete('/blog/{post:slug}', [BlogController::class, 'destroy'])->name('blog.destroy');
    Route::post('/blog/{post:slug}/commentaires', [BlogCommentController::class, 'store'])->name('blog.comment.store');
    Route::delete('/commentaires/{comment}', [BlogCommentController::class, 'destroy'])->name('blog.comment.destroy');

    // Blog editor AJAX endpoints
    Route::post('/blog/upload-image', [BlogController::class, 'uploadImage'])->name('blog.upload-image');
    Route::post('/blog/ai-generate', [BlogController::class, 'aiGenerate'])->name('blog.ai-generate');
    Route::post('/blog/ai-correct', [BlogController::class, 'aiCorrect'])->name('blog.ai-correct');
    Route::post('/blog/ai-method-selection', [BlogController::class, 'aiMethodSelection'])->name('blog.ai-method-selection');
    Route::post('/blog/ai-remaining', [BlogController::class, 'aiRemaining'])->name('blog.ai-remaining');
    Route::post('/blog/creer-brouillon', [BlogController::class, 'createDraft'])->name('blog.create-draft');

    // Blog annotation endpoints (root)
    Route::get('/blog/{post:slug}/annotations', [BlogAnnotationController::class, 'index'])->name('blog.annotations.index');
    Route::post('/blog/{post:slug}/annotations', [BlogAnnotationController::class, 'store'])->name('blog.annotations.store');
    Route::put('/blog/{post:slug}/annotations/{annotation}', [BlogAnnotationController::class, 'update'])->name('blog.annotations.update');
    Route::delete('/blog/{post:slug}/annotations/{annotation}', [BlogAnnotationController::class, 'destroy'])->name('blog.annotations.destroy');
    Route::patch('/blog/{post:slug}/annotations/{annotation}/resolve', [BlogAnnotationController::class, 'resolve'])->name('blog.annotations.resolve');

    // Blog annotation reply endpoints (root)
    Route::get('/blog/{post:slug}/annotations/{annotation}/replies', [BlogAnnotationReplyController::class, 'index'])->name('blog.annotations.replies.index');
    Route::post('/blog/{post:slug}/annotations/{annotation}/replies', [BlogAnnotationReplyController::class, 'store'])->name('blog.annotations.replies.store');
    Route::put('/blog/{post:slug}/annotations/{annotation}/replies/{reply}', [BlogAnnotationReplyController::class, 'update'])->name('blog.annotations.replies.update');
    Route::delete('/blog/{post:slug}/annotations/{annotation}/replies/{reply}', [BlogAnnotationReplyController::class, 'destroy'])->name('blog.annotations.replies.destroy');

    Route::put('/blog/{post:slug}/content', [BlogController::class, 'saveContent'])->name('blog.save-content');

    // Blog snapshot endpoints
    Route::post('/blog/{post:slug}/snapshots', [BlogSnapshotController::class, 'store'])->name('blog.snapshots.store');
    Route::get('/blog/{post:slug}/snapshots', [BlogSnapshotController::class, 'index'])->name('blog.snapshots.index');
    Route::post('/blog/{post:slug}/snapshots/{snapshot}/restore', [BlogSnapshotController::class, 'restore'])->name('blog.snapshots.restore');

    // Blog loop endpoints
    Route::post('/blog/{post:slug}/loops', [BlogPostLoopController::class, 'store'])->name('blog.loops.store');
    Route::delete('/blog/{post:slug}/loops/{loop}', [BlogPostLoopController::class, 'destroy'])->name('blog.loops.destroy');
    Route::get('/blog/{post:slug}/loop-messages', [BlogPostLoopController::class, 'messages'])->name('blog.loops.messages');
    Route::post('/blog/{post:slug}/loops/{loop}/messages', [BlogPostLoopController::class, 'storeMessage'])->name('blog.loops.messages.store');

    // Blog co-author endpoints
    Route::get('/blog/{post:slug}/co-authors', [BlogCoAuthorController::class, 'index'])->name('blog.co-authors.index');
    Route::post('/blog/{post:slug}/co-authors', [BlogCoAuthorController::class, 'store'])->name('blog.co-authors.store');
    Route::delete('/blog/{post:slug}/co-authors/{user}', [BlogCoAuthorController::class, 'destroy'])->name('blog.co-authors.destroy');
    Route::get('/blog/{post:slug}/co-authors/search', [BlogCoAuthorController::class, 'search'])->name('blog.co-authors.search');

    // Blog todo endpoints
    Route::get('/blog/{post:slug}/todos', [BlogTodoController::class, 'index'])->name('blog.todos.index');
    Route::post('/blog/{post:slug}/todos', [BlogTodoController::class, 'store'])->name('blog.todos.store');
    Route::put('/blog/{post:slug}/todos/{todo}', [BlogTodoController::class, 'update'])->name('blog.todos.update');
    Route::delete('/blog/{post:slug}/todos/{todo}', [BlogTodoController::class, 'destroy'])->name('blog.todos.destroy');
    Route::post('/blog/{post:slug}/todos/{todo}/threads', [BlogTodoController::class, 'threadStore'])->name('blog.todos.threads.store');
    Route::delete('/blog/{post:slug}/todos/{todo}/threads/{thread}', [BlogTodoController::class, 'threadDestroy'])->name('blog.todos.threads.destroy');

    // Blog Explorer endpoints
    Route::post('/blog/{post:slug}/explorer/chat', [BlogExplorerController::class, 'chat'])->name('blog.explorer.chat');
    Route::post('/blog/{post:slug}/explorer/note', [BlogExplorerController::class, 'generateNote'])->name('blog.explorer.note.generate');
    // TASK-1256 : feedback humain sur une reponse Explorer (Utile / A ameliorer)
    Route::post('/blog/{post:slug}/explorer/feedback', [BlogExplorerController::class, 'storeFeedback'])->name('blog.explorer.feedback.store');
    Route::get('/blog/{post:slug}/explorer/notes', [BlogExplorerController::class, 'indexNotes'])->name('blog.explorer.notes.index');
    Route::post('/blog/{post:slug}/explorer/notes', [BlogExplorerController::class, 'storeNote'])->name('blog.explorer.notes.store');
    Route::put('/blog/{post:slug}/explorer/notes/{note}', [BlogExplorerController::class, 'updateNote'])->name('blog.explorer.notes.update');
    Route::delete('/blog/{post:slug}/explorer/notes/{note}', [BlogExplorerController::class, 'destroyNote'])->name('blog.explorer.notes.destroy');

    // Blog plan endpoint
    Route::patch('/blog/{post:slug}/plan', [BlogController::class, 'updatePlan'])->name('blog.plan.update');

    // Blog dossier classification endpoints
    Route::get('/blog/dossiers', [BlogDossierApiController::class, 'listDossiers'])->name('blog.dossiers.index');
    Route::post('/blog/dossiers', [BlogDossierApiController::class, 'quickCreate'])->name('blog.dossiers.store');
    Route::get('/blog/{post:slug}/dossier', [BlogDossierApiController::class, 'currentDossier'])->name('blog.dossier.current');
    Route::post('/blog/{post:slug}/dossier', [BlogDossierApiController::class, 'attach'])->name('blog.dossier.attach');
    Route::delete('/blog/{post:slug}/dossier', [BlogDossierApiController::class, 'detach'])->name('blog.dossier.detach');

    // Blog invitation endpoints
    Route::get('/blog/{post:slug}/invitations', [BlogInvitationController::class, 'index'])->name('blog.invite.index');
    Route::post('/blog/{post:slug}/invite', [BlogInvitationController::class, 'store'])->name('blog.invite.store')->middleware('throttle:10,1');
});

// Blog — wildcard slug EN DERNIER
Route::get('/blog/{post:slug}', [BlogController::class, 'show'])->name('blog.show');

// Blog invitation public routes (no auth required)
Route::get('/blog-invitations/{token}', [BlogInvitationController::class, 'show'])->name('blog.invite.show');
Route::post('/blog-invitations/{token}/accept', [BlogInvitationController::class, 'accept'])->name('blog.invite.accept');
// POST, not a link: it parks the token in the session before handing over to
// login or registration, where the acceptance actually happens (TASK-1078).
Route::post('/blog-invitations/{token}/prepare', [BlogInvitationController::class, 'prepare'])->middleware('throttle:20,1')->name('blog.invite.prepare');

// Loop invitation public routes (no auth required). The GET is strictly
// read-only; `prepare` is a POST because it writes the token to the session
// before handing over to login or registration, where the acceptance actually
// happens. Accepting never rides on a GET.
Route::get('/loop-invitations/{token}', [LoopInvitationController::class, 'show'])->name('loop-invitations.show');
Route::post('/loop-invitations/{token}/prepare', [LoopInvitationController::class, 'prepare'])->middleware('throttle:20,1')->name('loop-invitations.prepare');

Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
// TASK-1488 (P0 privacy) — /search etait un CONTOURNEMENT vivant du correctif
// deja merge par TASK-1479. Mesure : un anonyme obtenait 200 avec le nom
// complet, la ville et la note de membres d'une Organization privee — les
// memes champs pour lesquels /membres a ete ferme —, plus les titres des
// Services et des Demandes, et des liens vers des fiches de profil desormais
// fermees. Plus grave que les fiches : aucun UUID n'est necessaire, la donnee
// est DECOUVRABLE par simple mot-cle.
Route::get('/search', [SearchController::class, 'index'])->middleware(['auth', 'organization.member'])->name('search');
Route::view('/aide', 'help')->name('help');
Route::view('/mentions-legales', 'mentions-legales')->name('mentions-legales');
Route::get('/bugs', [BugReportController::class, 'index'])->name('bug-reports.index');

// Authenticated routes
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/requests', [DashboardController::class, 'requests'])->name('dashboard.requests');
    Route::get('/dashboard/requests/{serviceRequest}', [DashboardController::class, 'requestDetail'])->name('dashboard.requests.detail')->whereUuid('serviceRequest');
    Route::get('/dashboard/services', [DashboardController::class, 'services'])->name('dashboard.services');
    Route::get('/dashboard/services/{service}', [DashboardController::class, 'serviceDetail'])->name('dashboard.services.detail')->whereUuid('service');
    Route::get('/flux', OrganizationFeed::class)->name('flux');
    Route::get('/flux/creer', CreateFeedPost::class)->name('flux.create');
    Route::get('/flux/mes-annonces', MyFeedPosts::class)->name('flux.my');
    Route::get('/flux/modifier/{feedPost}', EditFeedPost::class)->name('flux.edit');
    Route::get('/flux/{feedPost}', ViewFeedPost::class)->name('flux.show')->whereUuid('feedPost');

    // Services
    Route::middleware('profile.complete')->group(function () {
        Route::get('/services/create', [ServiceController::class, 'create'])->name('services.create');
        Route::post('/services', [ServiceController::class, 'store'])->name('services.store');
        Route::post('/services/ai-formulate', [ServiceController::class, 'formulate'])->name('services.ai-formulate');
    });
    Route::get('/services/{service}/edit', [ServiceController::class, 'edit'])->name('services.edit');
    Route::put('/services/{service}', [ServiceController::class, 'update'])->name('services.update');
    Route::delete('/services/{service}', [ServiceController::class, 'destroy'])->name('services.destroy');

    // Requests (demandes)
    Route::middleware('profile.complete')->group(function () {
        Route::get('/requests/create', [RequestController::class, 'create'])->name('requests.create');
        Route::post('/requests', [RequestController::class, 'store'])->name('requests.store');
        Route::post('/requests/ai-formulate', [RequestController::class, 'formulate'])->name('requests.ai-formulate');
    });
    Route::get('/requests/{request}/edit', [RequestController::class, 'edit'])->name('requests.edit');
    Route::put('/requests/{request}', [RequestController::class, 'update'])->name('requests.update');
    Route::delete('/requests/{request}', [RequestController::class, 'destroy'])->name('requests.destroy');

    // Transactions
    Route::get('/transactions/export', [TransactionController::class, 'exportCsv'])->name('transactions.export');
    Route::post('/transactions', [TransactionController::class, 'store'])->middleware('throttle:10,1')->name('transactions.store');
    Route::patch('/transactions/{transaction}/approve', [TransactionController::class, 'approve'])->name('transactions.approve');
    Route::patch('/transactions/{transaction}/refuse', [TransactionController::class, 'refuse'])->name('transactions.refuse');
    Route::patch('/transactions/{transaction}/adjust', [TransactionController::class, 'adjust'])->name('transactions.adjust');
    Route::patch('/transactions/{transaction}/cancel', [TransactionController::class, 'cancel'])->name('transactions.cancel');
    Route::patch('/transactions/{transaction}/complete', [TransactionController::class, 'complete'])->name('transactions.complete');
    Route::patch('/transactions/{transaction}/confirm', [TransactionController::class, 'confirm'])->name('transactions.confirm');
    Route::patch('/transactions/{transaction}/contest', [TransactionController::class, 'contest'])->name('transactions.contest');

    // Reviews
    Route::post('/transactions/{transaction}/review', [ReviewController::class, 'store'])->middleware('throttle:5,1')->name('reviews.store');

    // Messages
    Route::get('/messages', [MessageController::class, 'index'])->name('messages.index');
    Route::get('/messages/with/{user}', [MessageController::class, 'showWithUser'])->name('messages.with');
    Route::get('/messages/{transaction}', [MessageController::class, 'show'])->name('messages.show');

    // Points history
    Route::get('/points', [PointController::class, 'index'])->name('points.index');
    Route::post('/points/invitation', [PointController::class, 'sendInvitation'])->middleware('throttle:10,1')->name('points.invitation.send');
    Route::get('/invitations', [InvitationController::class, 'index'])->name('invitations.index');

    // Favorites
    Route::get('/favorites', [FavoriteController::class, 'index'])->name('favorites.index');
    Route::post('/favorites/{service}/toggle', [FavoriteController::class, 'toggle'])->middleware('throttle:30,1')->name('favorites.toggle');

    // TASK-1373 — Centre de notifications. La route RACINE doit exister meme
    // quand une version org-scopee est disponible : le helper d'URL du rail
    // retombe sur `route('notifications.index')` des qu'il n'y a pas de slug, et
    // une route absente y leverait une RouteNotFoundException sur TOUTE page.
    // Regime `auth` seul, comme Favoris, Points et Invitations.
    Route::get('/notifications', [NotificationCenterController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationCenterController::class, 'readAll'])->middleware('throttle:30,1')->name('notifications.read-all');
    Route::get('/notifications/preferences', [NotificationPreferenceController::class, 'edit'])->name('notifications.preferences.edit');
    Route::post('/notifications/preferences', [NotificationPreferenceController::class, 'update'])->middleware('throttle:30,1')->name('notifications.preferences.update');
    Route::post('/notifications/{notification}/read', [NotificationCenterController::class, 'read'])->middleware('throttle:60,1')->name('notifications.read');
    Route::post('/notifications/{notification}/open', [NotificationCenterController::class, 'open'])->middleware('throttle:60,1')->name('notifications.open');

    // Reports
    Route::post('/reports/service/{service}', [ReportController::class, 'storeService'])->middleware('throttle:5,1')->name('reports.service');
    Route::post('/reports/request/{serviceRequest}', [ReportController::class, 'storeRequest'])->middleware('throttle:5,1')->name('reports.request');
    Route::post('/reports/user/{user}', [ReportController::class, 'storeUser'])->middleware('throttle:5,1')->name('reports.user');
    Route::post('/bugs', [BugReportController::class, 'store'])->middleware('throttle:5,1')->name('bug-reports.store');

    // Profile
    Route::get('/profile/edit', [ProfileController::class, 'edit'])->name('profile.edit');
    // TASK-1223 : « Mes usages IA » — transparence du ledger canonique,
    // scope strict user courant + Organization courante.
    Route::get('/profile/ai-usage', [UserAiUsageController::class, 'index'])->name('profile.ai-usage');
    // TASK-1229 : « Voir les offres » — page d'information (aucun paiement).
    Route::get('/profile/ai-usage/offers', [UserAiUsageController::class, 'offers'])->name('profile.ai-offers');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/availability', [ProfileController::class, 'toggleAvailability'])->name('profile.availability');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Member AI Profile wizard
    Route::middleware('ai-profiles.enabled')->group(function () {
        Route::get('/agent-ia', [AgentIaController::class, 'index'])->name('agent-ia.index');
        Route::get('/agent-ia/edit', [AgentIaController::class, 'wizard'])->name('agent-ia.wizard');
        Route::get('/agent-ia/setup', [AgentIaController::class, 'setup'])->name('agent-ia.setup');
        Route::get('/agent-ia/test', [AgentIaController::class, 'test'])->name('agent-ia.test');
        Route::get('/agent-ia/echanges', [MemberAiProfileInteractionController::class, 'index'])->name('agent-ia.interactions');
        Route::get('/agent-ia/echanges/conversations', [MemberAiProfileConversationsController::class, 'index'])->name('agent-ia.conversations');
        Route::get('/agent-ia/echanges/conversations/{conversation}', [MemberAiProfileConversationsController::class, 'show'])->name('agent-ia.conversations.show');

        Route::delete('/agent-ia/profile', function () {
            MemberAiProfile::where('user_id', auth()->id())->delete();

            return response()->json(['ok' => true]);
        })->name('agent-ia.profile.reset');

        // Bounded member AI agent
        Route::get('/agent-ia/member/{user}', BoundedMemberAgent::class)
            ->name('agent-ia.member.presentation');
    });

    // Loops
    Route::middleware('loops.enabled')->group(function () {
        // `no_store` (TASK-1277) : le catalogue porte l'etat des demandes
        // d'adhesion (« Demande en attente »). Servi `no-cache`, le bouton
        // Precedent du navigateur rejoue la reponse en cache sans la revalider
        // et affiche une carte perimee ; `no-store` force la requete.
        Route::get('/loops', [LoopController::class, 'index'])->middleware('cache.headers:no_store')->name('loops.index');
        Route::get('/loops/create', [LoopController::class, 'create'])->name('loops.create');
        Route::post('/loops', [LoopController::class, 'store'])->middleware('throttle:5,1')->name('loops.store');
        Route::get('/loops/{loop}', [LoopController::class, 'show'])->name('loops.show');
        // L'agenda de l'Organization : lecture seule, il agrege ce qui a ete
        // organise dans les Boucles. Declare avant /loops/{loop} n'est pas
        // necessaire — le segment differe — mais reste groupe avec elles.
        Route::get('/agenda', [LoopEventAgendaController::class, 'index'])->name('events.agenda');
        Route::get('/loops/{loop}/edit', [LoopController::class, 'edit'])->name('loops.edit');
        Route::put('/loops/{loop}', [LoopController::class, 'update'])->name('loops.update');
        Route::post('/loops/{loop}/join', [LoopController::class, 'join'])->name('loops.join');
        Route::post('/loops/{loop}/leave', [LoopController::class, 'leave'])->name('loops.leave');
        // Archivage par le proprietaire (TASK-1086). Distinct de loops.update :
        // celle-ci refuse une Boucle archivee, ce qui rendrait la reactivation
        // inaccessible a la seule personne censee pouvoir la demander.
        Route::post('/loops/{loop}/archive', [LoopController::class, 'archive'])->name('loops.archive');
        Route::post('/loops/{loop}/reactivate', [LoopController::class, 'reactivate'])->name('loops.reactivate');
        Route::post('/loops/{loop}/join-requests', [LoopController::class, 'storeJoinRequest'])->middleware('throttle:5,1')->name('loops.join-requests.store');
        Route::get('/loops/{loop}/invite', [LoopController::class, 'invite'])->name('loops.invite');
        Route::post('/loops/{loop}/invite/members', [LoopController::class, 'storeMembers'])->middleware('throttle:10,1')->name('loops.invite.members');
        // Flat route (not nested under /loops/{loop}/...): a join request id is
        // already globally unique, and the dual path-based/org-prefixed union-typed
        // $loopOrOrganization/$loop controller pattern used everywhere else in this
        // controller only reserves the first two positional route slots — a third
        // nested {joinRequest} segment breaks that resolution. The Loop is resolved
        // from the request's own relation instead of a route segment.
        // Flat, like the join-request routes above and for the same reason: a
        // membership id is globally unique, and the union-typed
        // $loopOrOrganization/$loop signature only reserves two positional
        // slots — a third nested segment raises a TypeError (TASK-1075).
        Route::put('/loop-members/{member}/role', [LoopController::class, 'updateMemberRole'])->name('loops.members.role');
        Route::delete('/join-requests/{joinRequest}', [LoopController::class, 'cancelJoinRequest'])->name('loop-join-requests.cancel');
        Route::post('/join-requests/{joinRequest}/accept', [LoopController::class, 'acceptJoinRequest'])->name('loop-join-requests.accept');
        Route::post('/join-requests/{joinRequest}/reject', [LoopController::class, 'rejectJoinRequest'])->name('loop-join-requests.reject');
        // Targeted e-mail invitations (TASK-1077). Sending and revoking need the
        // Loop, so they keep the nested shape; the recipient-facing routes are
        // flat and public — see the group below.
        Route::post('/loops/{loop}/invitations', [LoopInvitationController::class, 'store'])->middleware('throttle:10,1')->name('loops.invitations.store');
        Route::post('/loop-invitations/{invitation}/revoke', [LoopInvitationController::class, 'revoke'])->name('loop-invitations.revoke');
        Route::post('/loop-invitations/{token}/accept', [LoopInvitationController::class, 'accept'])->middleware('throttle:10,1')->name('loop-invitations.accept');
        Route::post('/loops/{loop}/members', [LoopController::class, 'addMember'])->name('loops.members.add');
        Route::post('/loops/{loop}/messages', [LoopController::class, 'storeMessage'])->name('loops.messages.store');
        Route::post('/loops/{loop}/ask-ai', [LoopController::class, 'askAi'])->middleware('throttle:5,1')->name('loops.ai');
        Route::post('/loops/{loop}/help-request/analyze', [LoopController::class, 'analyzeHelpIntention'])->name('loops.help-request.analyze');
        Route::post('/loops/{loop}/help-request/continue', [LoopController::class, 'prepareHelpRequest'])->name('loops.help-request.continue');
        // TASK-1213 : reponse documentaire sourcee (RAG V1), read-only, JSON.
        Route::post('/loops/{loop}/knowledge', [LoopController::class, 'knowledge'])->middleware('throttle:5,1')->name('loops.knowledge.ask');
    });
});

// TASK-1488 (P0 privacy) — la fiche d'un Service et celle d'une Demande
// rejoignent la frontiere posee par TASK-1479. Mesure faite au HEAD 497d934b,
// sans aucun cookie, sur une Organization `is_public = false` : ces deux routes
// rendaient 200 avec le NOM REEL de la personne, le titre et le contenu metier.
// Le commentaire « Public ... used by Explorer » qui les couvrait ne suffisait
// pas : l'Explorer lui-meme est member-only depuis TASK-1479, et « Public »
// designe le tenant resolu, pas le lecteur autorise (docs/05, « Public != global »).
Route::get('/services/{service}', [ServiceController::class, 'show'])->middleware(['auth', 'organization.member'])->name('services.show')->whereUuid('service');
Route::get('/requests/{request}', [RequestController::class, 'show'])->middleware(['auth', 'organization.member'])->name('requests.show');
// TASK-1479 (P0 privacy, extension arbitree par MASTER) — « profil public »
// veut dire visible des AUTRES MEMBRES de l'Organization, pas ouvert au Web
// anonyme. Mesure : sur une Organization is_public = false, cette page rendait
// 200 a un anonyme avec nom, ville, biographie, disponibilite et points.
// Fermer l'annuaire en laissant chaque fiche accessible serait un demi-correctif.
Route::get('/profile/{user}', [ProfileController::class, 'show'])->middleware(['auth', 'organization.member'])->name('profile.show');
Route::middleware('ai-profiles.enabled')->group(function () {
    Route::get('/profile/{user}/agent-ia', [ProfileController::class, 'aiAgentChat'])->name('agent-ia.profile.chat');
    Route::post('/profile/{user}/agent-ia/discuter', [AiAgentLoopController::class, 'startConversation'])->name('agent-ia.conversation.start');
});

// Abonnements (TASK-354 corrective)
Route::get('/abonnements', [SubscriptionController::class, 'index'])->name('subscriptions');

// Admin routes
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('dashboard');

    // TASK-1348/1349 — le MYCELIUM : nom public de la Constitution IA de la
    // PLATEFORME. Zone admin GLOBALE : la garde est `is_admin` (attribut), pas
    // l'appartenance a une Organization. Aucun administrateur d'organisation
    // n'atteint ces routes.
    Route::get('/mycelium', [AdminAiConstitutionController::class, 'index'])->name('mycelium');
    Route::put('/mycelium', [AdminAiConstitutionController::class, 'update'])->name('mycelium.update');
    Route::delete('/mycelium', [AdminAiConstitutionController::class, 'withdraw'])->name('mycelium.withdraw');

    // L'ancienne URL de TASK-1348 ne devient PAS une seconde autorite : elle
    // redirige. Un alias qui rendrait la meme vue creerait deux chemins
    // vivants pour un seul ecran, et c'est ainsi que deux logiques finissent
    // par diverger.
    Route::get('/ai-constitution', fn () => redirect()->route('admin.mycelium'))->name('ai-constitution');
    Route::get('/themes', [AdminThemeController::class, 'index'])->name('themes');
    Route::get('/themes/create', [AdminThemeController::class, 'create'])->name('themes.create');
    Route::post('/themes', [AdminThemeController::class, 'store'])->name('themes.store');
    Route::get('/themes/{theme}/edit', [AdminThemeController::class, 'edit'])->name('themes.edit');
    Route::put('/themes/{theme}', [AdminThemeController::class, 'update'])->name('themes.update');
    Route::delete('/themes/{theme}', [AdminThemeController::class, 'destroy'])->name('themes.destroy');

    // Users
    Route::get('/users', [AdminController::class, 'users'])->name('users');
    Route::get('/users/create', [AdminController::class, 'createUser'])->name('users.create');
    Route::post('/users', [AdminController::class, 'storeUser'])->name('users.store');
    Route::get('/users/{user}/edit', [AdminController::class, 'editUser'])->name('users.edit');
    Route::put('/users/{user}', [AdminController::class, 'updateUser'])->name('users.update');
    Route::patch('/users/{user}/toggle-availability', [AdminController::class, 'toggleUserAvailability'])->name('users.toggle-availability');
    Route::patch('/users/{user}/toggle-admin', [AdminController::class, 'toggleUserAdmin'])->name('users.toggle-admin');
    Route::patch('/users/{user}/ban', [AdminController::class, 'banUser'])->name('users.ban');
    Route::patch('/users/{user}/unban', [AdminController::class, 'unbanUser'])->name('users.unban');
    Route::post('/users/{user}/adjust-points', [AdminController::class, 'adjustPoints'])->name('users.adjust-points');
    Route::post('/users/{user}/password', [AdminController::class, 'changePassword'])->name('users.password');
    Route::post('/users/{user}/send-password-reset', [AdminController::class, 'sendPasswordResetLink'])->name('users.send-password-reset');
    Route::patch('/users/{user}/assign-organization', [AdminController::class, 'assignOrganization'])->name('users.assign-organization');
    Route::post('/users/{user}/login-as', [AdminController::class, 'loginAsUser'])->name('users.login-as');
    Route::get('/users/{user}/delete-preview', [AdminController::class, 'deletePreview'])->name('users.delete-preview');
    Route::post('/users/{user}/delete', [AdminController::class, 'deleteUser'])->name('users.delete');

    // Services
    Route::get('/services', [AdminController::class, 'services'])->name('services');
    Route::get('/services/{service}/edit', [AdminController::class, 'editService'])->name('services.edit');
    Route::put('/services/{service}', [AdminController::class, 'updateService'])->name('services.update');
    Route::delete('/services/{id}/force', [AdminController::class, 'forceDeleteService'])->name('services.force-delete');
    Route::patch('/services/{id}/restore', [AdminController::class, 'restoreService'])->name('services.restore');

    // Transactions
    Route::get('/transactions', [AdminController::class, 'transactions'])->name('transactions');
    Route::delete('/transactions/{transactionId}', [AdminController::class, 'destroyTransaction'])->name('transactions.destroy');

    // Requests
    Route::get('/requests', [AdminController::class, 'requests'])->name('requests');
    Route::get('/requests/{serviceRequest}/edit', [AdminController::class, 'editRequest'])->name('requests.edit');
    Route::put('/requests/{serviceRequest}', [AdminController::class, 'updateRequest'])->name('requests.update');
    Route::patch('/requests/{serviceRequest}/close', [AdminController::class, 'closeRequest'])->name('requests.close');
    Route::delete('/requests/{requestId}', [AdminController::class, 'destroyRequest'])->name('requests.destroy');

    // Categories & Skills
    Route::get('/categories', [AdminCategoryController::class, 'index'])->name('categories');
    Route::get('/categories/create', [AdminCategoryController::class, 'create'])->name('categories.create');
    Route::post('/categories', [AdminCategoryController::class, 'store'])->name('categories.store');
    Route::get('/categories/{category}/edit', [AdminCategoryController::class, 'edit'])->name('categories.edit');
    Route::put('/categories/{category}', [AdminCategoryController::class, 'update'])->name('categories.update');
    Route::delete('/categories/{category}', [AdminCategoryController::class, 'destroy'])->name('categories.destroy');
    Route::post('/categories/{category}/skills', [AdminCategoryController::class, 'storeSkill'])->name('categories.skills.store');
    Route::delete('/skills/{skill}', [AdminCategoryController::class, 'destroySkill'])->name('skills.destroy');

    // Organizations
    Route::get('/organizations', [AdminOrganizationController::class, 'index'])->name('organizations');
    // TASK-1425 (CRM-15) : « Relations » cote plateforme, agregats par Organization, lecture seule.
    Route::get('/relations', [AdminCrmOverviewController::class, 'index'])->name('crm.overview');
    // TASK-1427 : decision Cyril — le SuperAdmin voit TOUT (contacts, echeances, faits de toutes les Organizations).
    Route::get('/relations/aujourdhui', [AdminCrmOverviewController::class, 'today'])->name('crm.overview.today');
    Route::get('/relations/faits', [AdminCrmOverviewController::class, 'facts'])->name('crm.overview.facts');
    // TASK-1431 (CRM CORE FIX B) : le SuperAdmin ADMINISTRE les Relations de chaque Organization —
    // Organization explicite dans l'URL, memes services metier, SoftDelete/restore plateforme.
    Route::post('/relations/{organization}/contacts', [AdminCrmController::class, 'storeContact'])->name('crm.contacts.store');
    Route::get('/relations/{organization}/contacts/{contact}', [AdminCrmController::class, 'show'])->name('crm.contacts.show');
    Route::put('/relations/{organization}/contacts/{contact}', [AdminCrmController::class, 'updateContact'])->name('crm.contacts.update');
    Route::delete('/relations/{organization}/contacts/{contact}', [AdminCrmController::class, 'destroy'])->name('crm.contacts.destroy');
    Route::post('/relations/{organization}/contacts/{contact}/restore', [AdminCrmController::class, 'restore'])->name('crm.contacts.restore');
    Route::post('/relations/{organization}/contacts/{contact}/status', [AdminCrmController::class, 'changeStatus'])->name('crm.contacts.status');
    Route::post('/relations/{organization}/contacts/{contact}/notes', [AdminCrmController::class, 'storeNote'])->name('crm.contacts.notes.store');
    Route::post('/relations/{organization}/contacts/{contact}/next-action', [AdminCrmController::class, 'planNextAction'])->name('crm.contacts.next-action.plan');
    Route::post('/relations/{organization}/contacts/{contact}/next-action/complete', [AdminCrmController::class, 'completeNextAction'])->name('crm.contacts.next-action.complete');
    Route::post('/relations/{organization}/contacts/{contact}/policy', [AdminCrmController::class, 'changePolicy'])->name('crm.contacts.policy');
    Route::get('/relations/{organization}/contacts/{contact}/email', [AdminCrmController::class, 'pickEmailTemplate'])->name('crm.contacts.email.pick');
    Route::get('/relations/{organization}/contacts/{contact}/email/{template}', [AdminCrmController::class, 'previewEmail'])->name('crm.contacts.email.preview');
    Route::post('/relations/{organization}/contacts/{contact}/email/{template}', [AdminCrmController::class, 'sendEmail'])->name('crm.contacts.email.send');
    Route::get('/relations/{organization}/contacts/{contact}/emails/{log}', [AdminCrmController::class, 'showEmail'])->name('crm.contacts.emails.show');
    Route::get('/organizations/create', [AdminOrganizationController::class, 'create'])->name('organizations.create');
    Route::post('/organizations', [AdminOrganizationController::class, 'store'])->name('organizations.store');
    Route::get('/organizations/{organization}/edit', [AdminOrganizationController::class, 'edit'])->name('organizations.edit');
    Route::put('/organizations/{organization}', [AdminOrganizationController::class, 'update'])->name('organizations.update');
    Route::post('/organizations/{organization}/toggle-active', [AdminOrganizationController::class, 'toggleActive'])->name('organizations.toggle-active');
    Route::delete('/organizations/{organization}', [AdminOrganizationController::class, 'destroy'])->name('organizations.destroy');
    Route::get('/organizations/{organization}/homepage', [AdminOrganizationController::class, 'homepage'])->name('organizations.homepage');
    Route::put('/organizations/{organization}/homepage', [AdminOrganizationController::class, 'updateHomepage'])->name('organizations.homepage.update');
    Route::get('/homepages', [AdminOrganizationController::class, 'homepages'])->name('homepages');
    // TASK-1506 — ce que sert la RACINE (`/`) : accueil, Shell Welcome, blog,
    // annuaire ou boucles. Aucun nom de domaine n'entre dans ce choix.
    Route::get('/homepage', [AdminRootDestinationController::class, 'edit'])->name('homepage');

    // TASK-1514 — les fichiers de TOUTES les Organizations, avec filtre.
    // L'ecriture porte l'Organization DANS l'URL : `DossierFile` n'a aucun
    // scope global, le binding accepterait sinon un fichier d'un autre tenant.
    Route::get('/drives', [AdminDrivesController::class, 'index'])->name('drives');
    Route::post('/drives/{organization}/{file}/reindex', [AdminDrivesController::class, 'reindex'])
        ->middleware('throttle:20,1')
        ->name('drives.reindex');
    // TASK-1515 — lire les extraits indexes d'un fichier, et supprimer un
    // fichier. Meme regle de perimetre que la reindexation : l'Organization
    // est DANS l'URL et le controleur la compare, faute de scope global sur
    // `DossierFile`. La suppression est en DELETE, jamais en GET : aucune
    // destruction ne doit etre atteignable par une simple navigation.
    Route::get('/drives/{organization}/{file}/chunks', [AdminDrivesController::class, 'chunks'])
        ->middleware('throttle:60,1')
        ->name('drives.chunks');
    Route::delete('/drives/{organization}/{file}', [AdminDrivesController::class, 'destroy'])
        ->middleware('throttle:20,1')
        ->name('drives.destroy');
    Route::put('/homepage', [AdminRootDestinationController::class, 'update'])->name('homepage.update');
    Route::get('/organization-requests', [AdminOrganizationRequestController::class, 'index'])->name('organization-requests');

    // Messages moderation
    Route::get('/messages', [AdminMessageController::class, 'index'])->name('messages');
    Route::get('/messages/{message}', [AdminMessageController::class, 'show'])->name('messages.show');
    Route::delete('/messages/{message}', [AdminMessageController::class, 'destroy'])->name('messages.destroy');
    Route::delete('/loop-messages/{loopMessage}', [AdminMessageController::class, 'destroyLoopMessage'])->name('loop-messages.destroy');

    // Reports
    Route::get('/reports', [AdminController::class, 'reports'])->name('reports');
    Route::patch('/reports/{report}/dismiss', [AdminController::class, 'dismissReport'])->name('reports.dismiss');
    Route::patch('/reports/{report}/review', [AdminController::class, 'reviewReport'])->name('reports.review');

    // Bug reports
    Route::get('/bugs-reports', [AdminBugReportController::class, 'index'])->name('bug-reports');
    Route::patch('/bugs-reports/{bugReport}/fix', [AdminBugReportController::class, 'fix'])->name('bug-reports.fix');
    Route::patch('/bugs-reports/{bugReport}/dismiss', [AdminBugReportController::class, 'dismiss'])->name('bug-reports.dismiss');
    Route::delete('/bugs-reports/{bugReport}', [AdminBugReportController::class, 'destroy'])->name('bug-reports.destroy');

    // Referral invitations
    Route::get('/referrals', [AdminReferralController::class, 'index'])->name('referrals');

    // Translations
    Route::get('/translations', [AdminTranslationController::class, 'index'])->name('translations');
    Route::get('/translations/overrides/create', [AdminTranslationController::class, 'createOverride'])->name('translations.overrides.create');
    Route::post('/translations/overrides', [AdminTranslationController::class, 'store'])->name('translations.overrides.store');
    Route::get('/translations/overrides/{translationOverride}/edit', [AdminTranslationController::class, 'editOverride'])->name('translations.overrides.edit');
    Route::put('/translations/overrides/{translationOverride}', [AdminTranslationController::class, 'updateOverride'])->name('translations.overrides.update');
    Route::patch('/translations/overrides/{translationOverride}/deactivate', [AdminTranslationController::class, 'deactivateOverride'])->name('translations.overrides.deactivate');
    Route::post('/translations/reset', [AdminTranslationController::class, 'resetOverride'])->name('translations.overrides.reset');

    Route::get('/email-test', [AdminEmailController::class, 'index'])->name('email-test');
    Route::post('/email-test', [AdminEmailController::class, 'send'])->name('email-test.send');

    // Email templates
    Route::get('/email-templates', [AdminEmailTemplatesController::class, 'index'])->name('email-templates');
    Route::get('/email-templates/create', [AdminEmailTemplatesController::class, 'create'])->name('email-templates.create');
    Route::post('/email-templates', [AdminEmailTemplatesController::class, 'store'])->name('email-templates.store');
    Route::get('/email-templates/{emailTemplate}', [AdminEmailTemplatesController::class, 'show'])->name('email-templates.show');
    Route::get('/email-templates/{emailTemplate}/edit', [AdminEmailTemplatesController::class, 'edit'])->name('email-templates.edit');
    Route::put('/email-templates/{emailTemplate}', [AdminEmailTemplatesController::class, 'update'])->name('email-templates.update');
    Route::delete('/email-templates/{emailTemplate}', [AdminEmailTemplatesController::class, 'destroy'])->name('email-templates.destroy');
    Route::post('/email-templates/preview', [AdminEmailTemplatesController::class, 'preview'])->name('email-templates.preview');

    // Emailer send
    Route::get('/email-templates/{emailTemplate}/send', [AdminEmailTemplatesController::class, 'sendForm'])->name('email-templates.send');
    Route::get('/email-templates/{emailTemplate}/send/confirm', [AdminEmailTemplatesController::class, 'sendConfirm'])->name('email-templates.send.confirm');
    Route::post('/email-templates/{emailTemplate}/send', [AdminEmailTemplatesController::class, 'sendExecute'])->name('email-templates.send.execute');

    // Email logs
    // TASK-1380 — supervision des notifications. Il COMPTE, il ne lit pas :
    // aucun destinataire, aucun corps de message, aucune adresse. La garde est
    // le groupe ['auth','admin'] ci-dessus — `is_admin` est un attribut de
    // plateforme, pas une appartenance a une Organization.
    Route::get('/notifications-cockpit', [AdminNotificationCockpitController::class, 'index'])->name('notifications-cockpit');

    Route::get('/email-logs', [AdminEmailLogsController::class, 'index'])->name('email-logs');
    Route::get('/email-logs/{emailLog}', [AdminEmailLogsController::class, 'show'])->name('email-logs.show');

    // System email templates (notifications overrides)
    // Loop permission matrix — global settings by type x role x permission.
    // Never per-Loop: there is no loop_id anywhere in this flow (TASK-1079).
    Route::get('/loop-permissions', [AdminLoopPermissionController::class, 'index'])->name('loop-permissions');
    Route::put('/loop-permissions', [AdminLoopPermissionController::class, 'update'])->name('loop-permissions.update');
    Route::delete('/loop-permissions', [AdminLoopPermissionController::class, 'reset'])->name('loop-permissions.reset');

    // Composition des types de Boucles (super-admin). Le contrôleur refuse
    // lui-même tout non super-admin : le groupe admin ne suffit pas.
    Route::get('/loop-types', [AdminLoopTypeController::class, 'index'])->name('loop-types');
    Route::post('/loop-types', [AdminLoopTypeController::class, 'store'])->name('loop-types.store');
    // Avant `{type}` : sans cela, « custom » serait avale comme une cle de type.
    Route::delete('/loop-types/custom/{customLoopType}', [AdminLoopTypeController::class, 'destroy'])->name('loop-types.destroy');
    Route::put('/loop-types/{type}', [AdminLoopTypeController::class, 'update'])->name('loop-types.update');
    Route::delete('/loop-types/{type}', [AdminLoopTypeController::class, 'reset'])->name('loop-types.reset');
    Route::get('/system-email-templates', [AdminSystemEmailTemplatesController::class, 'index'])->name('system-email-templates');
    Route::get('/system-email-templates/{systemEmailTemplate}/edit', [AdminSystemEmailTemplatesController::class, 'edit'])->name('system-email-templates.edit');
    Route::put('/system-email-templates/{systemEmailTemplate}', [AdminSystemEmailTemplatesController::class, 'update'])->name('system-email-templates.update');

    // IA Design Lab (test interne)
    Route::get('/ia-design-lab', [AdminIaDesignLabController::class, 'index'])->name('ia-design-lab');
    Route::post('/ia-design-lab', [AdminIaDesignLabController::class, 'test'])->name('ia-design-lab.test');

    // Centre de supervision IA (T078.1) — appel réel OpenAI gpt-4o-mini
    Route::get('/ai-supervision', [AdminAiSupervisionController::class, 'index'])->name('ai-supervision');
    // TASK-1438 (SW-10) : observabilite plateforme du Shell Welcome — lecture seule, jamais un contenu de conversation.
    Route::get('/shell-welcome', [AdminGuestShellController::class, 'index'])->name('guest-shell');
    // TASK-1439 : UsageReference V1 — plateforme-only, brouillon -> publication humaine -> retrait (V3 §9).
    Route::get('/usage-references', [AdminUsageReferenceController::class, 'index'])->name('usage-references');
    Route::get('/usage-references/create', [AdminUsageReferenceController::class, 'create'])->name('usage-references.create');
    Route::post('/usage-references', [AdminUsageReferenceController::class, 'store'])->name('usage-references.store');
    // TASK-1480 : LIRE une reference — le geste qui manquait. `edit` etait la
    // seule vue du texte et rend 404 sur une version publiee : on ne pouvait
    // donc pas relire ce qui etait en ligne. Declaree APRES `/create` pour que
    // le segment litteral ne soit pas capture comme un identifiant.
    Route::get('/usage-references/{usageReference}', [AdminUsageReferenceController::class, 'show'])->name('usage-references.show');
    Route::get('/usage-references/{usageReference}/edit', [AdminUsageReferenceController::class, 'edit'])->name('usage-references.edit');
    Route::put('/usage-references/{usageReference}', [AdminUsageReferenceController::class, 'update'])->name('usage-references.update');
    Route::post('/usage-references/{usageReference}/publish', [AdminUsageReferenceController::class, 'publish'])->name('usage-references.publish');
    Route::delete('/usage-references/{usageReference}', [AdminUsageReferenceController::class, 'retire'])->name('usage-references.retire');
    // TASK-1447 : OrganizationShortcut, SuperAdmin-managed V1.
    Route::get('/shortcuts', [AdminShortcutController::class, 'index'])->name('shortcuts');
    Route::post('/shortcuts', [AdminShortcutController::class, 'store'])->name('shortcuts.store');
    Route::patch('/shortcuts/{shortcut}/toggle', [AdminShortcutController::class, 'toggle'])->name('shortcuts.toggle');
    // TASK-1456 : SuperAdmin Workshops — cockpit transversal LECTURE SEULE (V3 §14, MASTER Q81).
    Route::get('/workshops', [AdminWorkshopController::class, 'index'])->name('workshops');
    Route::post('/ai-supervision', [AdminAiSupervisionController::class, 'analyze'])->name('ai-supervision.analyze');

    // Historique des interactions IA (TASK-249)
    Route::get('/ai-interactions', [AdminAiInteractionController::class, 'index'])->name('ai-interactions');
    Route::get('/ai-interactions/{interaction}', [AdminAiInteractionController::class, 'show'])->name('ai-interactions.show');

    // Admin AI prompts registry (TASK-252)
    Route::get('/ai-prompts', [AdminAiPromptController::class, 'index'])->name('ai-prompts');
    Route::get('/ai-prompts/create', [AdminAiPromptController::class, 'create'])->name('ai-prompts.create');
    Route::post('/ai-prompts', [AdminAiPromptController::class, 'store'])->name('ai-prompts.store');
    Route::get('/ai-prompts/{prompt}', [AdminAiPromptController::class, 'show'])->name('ai-prompts.show');
    Route::get('/ai-prompts/{prompt}/edit', [AdminAiPromptController::class, 'edit'])->name('ai-prompts.edit');
    Route::put('/ai-prompts/{prompt}', [AdminAiPromptController::class, 'update'])->name('ai-prompts.update');
    Route::delete('/ai-prompts/{prompt}', [AdminAiPromptController::class, 'destroy'])->name('ai-prompts.destroy');

    // Admin AI costs & benchmark dashboard (TASK-253)
    Route::get('/ai-benchmark', [AdminAiBenchmarkController::class, 'index'])->name('ai-benchmark');

    // File de modération IA (TASK-255)
    Route::get('/ai-review-queue', [AdminAiReviewQueueController::class, 'index'])->name('ai-review-queue');
    Route::patch('/ai-review-queue/{interaction}', [AdminAiReviewQueueController::class, 'update'])->name('ai-review-queue.update');

    // Agents profil IA
    Route::get('/member-ai-profiles', [AdminMemberAiProfileController::class, 'index'])->name('member-ai-profiles');
    Route::get('/member-ai-profiles/{memberAiProfile}/edit', [AdminMemberAiProfileController::class, 'edit'])->name('member-ai-profiles.edit');
    Route::put('/member-ai-profiles/{memberAiProfile}', [AdminMemberAiProfileController::class, 'update'])->name('member-ai-profiles.update');
    Route::post('/member-ai-profiles/{memberAiProfile}/test-llm', [AdminMemberAiProfileController::class, 'testLlm'])->name('member-ai-profiles.test-llm');
    Route::patch('/member-ai-profiles/{memberAiProfile}/publish', [AdminMemberAiProfileController::class, 'publish'])->name('member-ai-profiles.publish');
    Route::patch('/member-ai-profiles/{memberAiProfile}/disable', [AdminMemberAiProfileController::class, 'disable'])->name('member-ai-profiles.disable');

    // Réglages IA (TASK-258)
    Route::get('/ai-config', [AdminAiConfigController::class, 'index'])->name('ai-config');
    Route::post('/ai-config', [AdminAiConfigController::class, 'update'])->name('ai-config.update');
    Route::post('/ai-config/blog', [AdminAiConfigController::class, 'updateBlogConfig'])->name('ai-config.blog');
    Route::post('/ai-config/profile', [AdminAiConfigController::class, 'updateProfileConfig'])->name('ai-config.profile');
    // TASK-1429 (SW-1) : politique Shell Welcome par Organization (SuperAdmin).
    Route::post('/ai-config/guest-shell', [AdminAiConfigController::class, 'updateGuestShellConfig'])->name('ai-config.guest-shell');
    // TASK-1500 : la configuration Shell Welcome par Organization a SA page (decision Cyril 10/09) ; le POST ci-dessus reste l'unique ecriture.
    Route::get('/shell-welcome-config', [AdminAiConfigController::class, 'guestShellConfig'])->name('shell-welcome-config');

    // Scenario packs (TASK-1240/TASK-1241) : un seul couple (pack, Organization)
    // a la fois, jamais d'action globale non bornee.
    Route::get('/scenario-packs', [AdminScenarioPackController::class, 'index'])->name('scenario-packs');
    Route::post('/scenario-packs/load', [AdminScenarioPackController::class, 'load'])->name('scenario-packs.load');
    Route::post('/scenario-packs/reset', [AdminScenarioPackController::class, 'reset'])->name('scenario-packs.reset');
    Route::post('/scenario-packs/delete', [AdminScenarioPackController::class, 'delete'])->name('scenario-packs.delete');

    // IA Usage dashboard (TASK-306 Lot 3)
    Route::get('/ia-usage', [AdminAiUsageController::class, 'index'])->name('ia-usage');
    Route::get('/ia-usage/{interaction}', [AdminAiUsageController::class, 'show'])->name('ia-usage.show');
    Route::get('/ia-usage/admin/{interaction}', [AdminAiUsageController::class, 'showAdmin'])->name('ia-usage.show-admin');

    // IA Usage by user (TASK-306)
    Route::get('/ia-usage-by-user', [AdminIaUsageByUserController::class, 'index'])->name('ia-usage-by-user');
    // TASK-1223 : cockpit IA/RAG plateforme — metadonnees par Organization,
    // jamais un contenu tenant ni une cle.
    Route::get('/ai-organizations', [AdminAiOrganizationsController::class, 'index'])->name('ai-organizations');
    // TASK-1487 (AI Quality Q2) : la MEME lecture, agregee plateforme, avec un
    // filtre Organization. Aucune conversation, aucune cle, aucun secret.
    Route::get('/ai-quality', [AdminAiQualityController::class, 'index'])->name('ai-quality');
    // TASK-1229 : « Monetisation IA » — credit IA par utilisateur (plateforme) :
    // IA gratuite, quota mensuel en utilisations, seuil d'alerte, offre.
    Route::get('/ai-monetization', [AdminAiMonetizationController::class, 'index'])->name('ai-monetization');
    Route::post('/ai-monetization', [AdminAiMonetizationController::class, 'update'])->name('ai-monetization.update');

    // Blog moderation
    Route::get('/blog', [AdminBlogController::class, 'index'])->name('blog');
    Route::get('/todo', [AdminBlogTodoController::class, 'index'])->name('todo');
    Route::patch('/todo/{todo}', [AdminBlogTodoController::class, 'update'])->name('todo.update');
    Route::delete('/todo/{todo}', [AdminBlogTodoController::class, 'destroy'])->name('todo.destroy');
    Route::get('/blog/{post}/edit', [AdminBlogController::class, 'edit'])->name('blog.edit');
    Route::put('/blog/{post}', [AdminBlogController::class, 'update'])->name('blog.update');
    Route::patch('/blog/{post}/status', [AdminBlogController::class, 'updateStatus'])->name('blog.status');
    Route::post('/blog/preview-markdown', [AdminBlogController::class, 'previewMarkdown'])->name('blog.preview-markdown');
    Route::delete('/blog/{post}', [AdminBlogController::class, 'destroy'])->name('blog.destroy');

    // Tags
    Route::get('/tags', [AdminTagController::class, 'index'])->name('tags');
    Route::get('/tags/{tag}/edit', [AdminTagController::class, 'edit'])->name('tags.edit');
    Route::put('/tags/{tag}', [AdminTagController::class, 'update'])->name('tags.update');
    Route::delete('/tags/{tag}', [AdminTagController::class, 'destroy'])->name('tags.destroy');

    // Loops Center
    Route::get('/loops', [AdminLoopController::class, 'index'])->name('loops');
    Route::get('/loops/create', [AdminLoopController::class, 'create'])->name('loops.create');
    Route::post('/loops', [AdminLoopController::class, 'store'])->name('loops.store');
    Route::get('/loops/{loop}', [AdminLoopController::class, 'show'])->name('loops.show');
    Route::get('/loops/{loop}/edit', [AdminLoopController::class, 'edit'])->name('loops.edit');
    Route::put('/loops/{loop}', [AdminLoopController::class, 'update'])->name('loops.update');
    Route::post('/loops/{loop}/members', [AdminLoopController::class, 'addMember'])->name('loops.members.add');
    Route::put('/loops/{loop}/members/{member}/role', [AdminLoopController::class, 'updateMemberRole'])->name('loops.members.role');
    Route::delete('/loops/{loop}/members/{member}', [AdminLoopController::class, 'removeMember'])->name('loops.members.remove');
    Route::get('/loops/{loop}/files', [AdminLoopController::class, 'files'])->name('loops.files');
    Route::put('/loops/{loop}/type', [AdminLoopController::class, 'updateType'])->name('loops.type.update');
    // Composition locale des Cards (TASK-1083). Le controleur verifie
    // loops.manage_cards ; la permission existait depuis TASK-1079 sans aucun
    // consommateur.
    Route::put('/loops/{loop}/cards', [AdminLoopController::class, 'updateCards'])->name('loops.cards.update');
    Route::post('/loops/{loop}/archive', [AdminLoopController::class, 'archive'])->name('loops.archive');
    Route::post('/loops/{loop}/restore', [AdminLoopController::class, 'restore'])->name('loops.restore');
    // Le configurateur de presets (TASK-1090). Etend l'ecran de composition de
    // TASK-1083 ; il ne le remplace pas.
    Route::get('/loops/{loop}/configure', [AdminLoopController::class, 'configure'])->name('loops.configure');
    Route::post('/loops/{loop}/compose', [AdminLoopController::class, 'composeCards'])->name('loops.compose');
    Route::post('/loops/{loop}/preset', [AdminLoopController::class, 'applyPreset'])->name('loops.preset.apply');
    Route::delete('/loops/{loop}', [AdminLoopController::class, 'destroy'])->name('loops.destroy');

    // Outils
    Route::get('/outils/assign-data', [AdminOutilsController::class, 'assignData'])->name('outils.assign-data');
    Route::post('/outils/assign-data', [AdminOutilsController::class, 'doAssignData'])->name('outils.assign-data.do');
    Route::get('/outils/assign-data/detail', [AdminOutilsController::class, 'assignDataDetail'])->name('outils.assign-data.detail');
    Route::get('/outils/fix-categories', [AdminOutilsController::class, 'fixCategories'])->name('outils.fix-categories');
    Route::post('/outils/fix-categories', [AdminOutilsController::class, 'doFixCategories'])->name('outils.fix-categories.do');

    // Stats
    Route::get('/stats/login-history', [AdminController::class, 'loginHistory'])->name('stats.login-history');
    Route::get('/stats/login-history/user/{user}', [AdminController::class, 'loginHistoryUser'])->name('stats.login-history.user');
});

Route::get('/admin/back-to-admin', [AdminController::class, 'backToAdmin'])
    ->middleware('auth')
    ->name('admin.back-to-admin');

// Organization route constraint
$organizationConstraint = '(?!login|register|admin|api|sitemap|search|explorer|profile|password|membres|echanges|partenaires|partners|boucles|loops)[a-z0-9][a-z0-9\-]*';

// Organization-prefixed routes (/org/{organization}/...) — en parallèle des routes legacy /{community}
Route::prefix('/org/{organization}')
    ->middleware(['web', 'organization'])
    ->where(['organization' => $organizationConstraint])
    ->name('organization.')
    ->group(function () {
        Route::get('/', [OrganizationLandingController::class, '__invoke'])->name('home');
        // TASK-1442 — SW-8a : la surface publique du Shell Welcome (lecture pure + premier geste). Distincte du Shell membre.
        Route::get('/shell', [GuestShellController::class, 'show'])->middleware('throttle:60,1')->name('shell.show');
        Route::post('/shell/message', [GuestShellController::class, 'message'])->middleware('throttle:30,1')->name('shell.message');
        Route::get('/about', [OrganizationLandingController::class, 'about'])->name('about');
        // TASK-1450 — Workshop domain foundation : la page PUBLIQUE d'un atelier publie de CETTE Organization (404 sinon). Pas de Shell ici.
        Route::get('/ateliers/{workshop}', [WorkshopPageController::class, 'show'])->name('workshop.show')->where('workshop', '[a-z0-9][a-z0-9\-]{2,79}');
        // TASK-1452 (B4-B) : « je choisis cette session » — geste Guest (interet != inscription), throttle anti-rafale.
        Route::post('/ateliers/{workshop}/sessions/{session}/interest', [WorkshopInterestController::class, 'select'])->middleware('throttle:30,1')->name('workshop.session.interest')->where('workshop', '[a-z0-9][a-z0-9\-]{2,79}')->whereUuid('session');
        Route::delete('/ateliers/{workshop}/sessions/{session}/interest', [WorkshopInterestController::class, 'withdraw'])->middleware('throttle:30,1')->name('workshop.session.interest.withdraw')->where('workshop', '[a-z0-9][a-z0-9\-]{2,79}')->whereUuid('session');
        // TASK-1453 : « je confirme ma participation » — geste MEMBRE (auth) ; l'email verifie et la meme Organization sont exiges par le controller.
        Route::post('/ateliers/{workshop}/sessions/{session}/register', [WorkshopRegistrationController::class, 'register'])->middleware(['auth', 'throttle:30,1'])->name('workshop.session.register')->where('workshop', '[a-z0-9][a-z0-9\-]{2,79}')->whereUuid('session');
        Route::delete('/ateliers/{workshop}/sessions/{session}/register', [WorkshopRegistrationController::class, 'cancel'])->middleware(['auth', 'throttle:30,1'])->name('workshop.session.register.cancel')->where('workshop', '[a-z0-9][a-z0-9\-]{2,79}')->whereUuid('session');
        // TASK-1349 — publique UNIQUEMENT sur opt-in explicite. Sans opt-in,
        // ou sans version active, la route rend 404 : publiquement, la
        // ressource n'existe pas.
        Route::get('/constitution', [MyceliumController::class, 'organization'])->name('constitution');
        Route::get('/bugs', [BugReportController::class, 'index'])->name('bug-reports.index');

        Route::middleware('guest')->group(function () {
            Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
            Route::post('/login', [AuthenticatedSessionController::class, 'store']);
            Route::get('/register', [RegisteredUserController::class, 'create'])->name('register');
            Route::post('/register', [RegisteredUserController::class, 'store']);
            Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
            Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->name('password.email');
            Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
            Route::post('/reset-password', [NewPasswordController::class, 'store'])->name('password.store');
        });

        Route::get('/abonnements', [SubscriptionController::class, 'orgIndex'])->name('subscriptions');

        // Boucles landing (guest-friendly, org-scoped explanation page)
        Route::get('/boucles', [HomeController::class, 'boucles'])->name('boucles.index');

        Route::middleware('auth')->group(function () {
            // TASK-1483 (P1 tenant) — le tableau de bord d'une Organization
            // repondait 200 a un membre d'une AUTRE Organization. Rien du
            // tenant vise n'y fuyait : la page ne montre que les donnees du
            // visiteur. Le defaut est ailleurs — elle les montrait sous
            // l'identite, le theme et le `header_javascript` d'un tenant dont
            // il n'est pas membre, en laissant croire qu'il y avait sa place.
            //
            // `explain` plutot que le 404 par defaut : la personne est
            // connectee et a tape ce slug elle-meme. Voir
            // `EnsureOrganizationMember`.
            Route::middleware('organization.member:explain')->group(function () {
                Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
                Route::get('/dashboard/requests', [DashboardController::class, 'requests'])->name('dashboard.requests');
                Route::get('/dashboard/requests/{serviceRequest}', [DashboardController::class, 'requestDetail'])->name('dashboard.requests.detail')->middleware('consume.org')->whereUuid('serviceRequest');
                Route::get('/dashboard/services', [DashboardController::class, 'services'])->name('dashboard.services');
                Route::get('/dashboard/services/{service}', [DashboardController::class, 'serviceDetail'])->name('dashboard.services.detail')->middleware('consume.org')->whereUuid('service');
            });
            Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

            Route::middleware('profile.complete')->group(function () {
                Route::get('/services/create', [ServiceController::class, 'create'])->name('services.create');
                Route::post('/services', [ServiceController::class, 'store'])->name('services.store');
                Route::post('/services/ai-formulate', [ServiceController::class, 'formulate'])->name('services.ai-formulate');
            });
            Route::get('/services/{service}/edit', [ServiceController::class, 'edit'])->middleware('consume.org')->name('services.edit');
            Route::put('/services/{service}', [ServiceController::class, 'update'])->middleware('consume.org')->name('services.update');
            Route::delete('/services/{service}', [ServiceController::class, 'destroy'])->middleware('consume.org')->name('services.destroy');

            Route::middleware('profile.complete')->group(function () {
                Route::get('/requests/create', [RequestController::class, 'create'])->name('requests.create');
                Route::post('/requests', [RequestController::class, 'store'])->name('requests.store');
                Route::post('/requests/ai-formulate', [RequestController::class, 'formulate'])->name('requests.ai-formulate');
            });
            Route::get('/requests/{request}/edit', [RequestController::class, 'edit'])->middleware('consume.org')->name('requests.edit');
            Route::put('/requests/{request}', [RequestController::class, 'update'])->middleware('consume.org')->name('requests.update');
            Route::delete('/requests/{request}', [RequestController::class, 'destroy'])->middleware('consume.org')->name('requests.destroy');

            Route::get('/transactions/export', [TransactionController::class, 'exportCsv'])->name('transactions.export');
            Route::post('/transactions', [TransactionController::class, 'orgStore'])->middleware('throttle:10,1')->name('transactions.store');
            Route::patch('/transactions/{transaction}/approve', [TransactionController::class, 'orgApprove'])->name('transactions.approve');
            Route::patch('/transactions/{transaction}/refuse', [TransactionController::class, 'orgRefuse'])->name('transactions.refuse');
            Route::patch('/transactions/{transaction}/adjust', [TransactionController::class, 'orgAdjust'])->name('transactions.adjust');
            Route::patch('/transactions/{transaction}/cancel', [TransactionController::class, 'orgCancel'])->name('transactions.cancel');
            Route::patch('/transactions/{transaction}/complete', [TransactionController::class, 'orgComplete'])->name('transactions.complete');
            Route::patch('/transactions/{transaction}/confirm', [TransactionController::class, 'orgConfirm'])->name('transactions.confirm');
            Route::patch('/transactions/{transaction}/contest', [TransactionController::class, 'orgContest'])->name('transactions.contest');

            Route::post('/transactions/{transaction}/review', [ReviewController::class, 'store'])->middleware('throttle:5,1')->middleware('consume.org')->name('reviews.store');

            Route::get('/messages', [MessageController::class, 'index'])->name('messages.index');
            Route::get('/messages/{transaction}', [MessageController::class, 'orgShow'])->name('messages.show');

            Route::get('/points', [PointController::class, 'index'])->name('points.index');
            Route::post('/points/invitation', [PointController::class, 'sendInvitation'])->middleware('throttle:10,1')->middleware('consume.org')->name('points.invitation.send');
            Route::get('/invitations', [InvitationController::class, 'index'])->name('invitations.index');

            Route::get('/favorites', [FavoriteController::class, 'index'])->name('favorites.index');
            Route::post('/favorites/{service}/toggle', [FavoriteController::class, 'toggle'])->middleware('throttle:30,1')->middleware('consume.org')->name('favorites.toggle');

            Route::get('/notifications', [NotificationCenterController::class, 'index'])->name('notifications.index');
            Route::post('/notifications/read-all', [NotificationCenterController::class, 'readAll'])->middleware('throttle:30,1')->name('notifications.read-all');
            Route::get('/notifications/preferences', [NotificationPreferenceController::class, 'edit'])->name('notifications.preferences.edit');
            Route::post('/notifications/preferences', [NotificationPreferenceController::class, 'update'])->middleware('throttle:30,1')->name('notifications.preferences.update');
            Route::post('/notifications/{notification}/read', [NotificationCenterController::class, 'read'])->middleware('throttle:60,1')->name('notifications.read');
            Route::post('/notifications/{notification}/open', [NotificationCenterController::class, 'open'])->middleware('throttle:60,1')->name('notifications.open');

            Route::post('/reports/service/{service}', [ReportController::class, 'orgStoreService'])->middleware('throttle:5,1')->name('reports.service');
            Route::post('/reports/request/{serviceRequest}', [ReportController::class, 'orgStoreRequest'])->middleware('throttle:5,1')->name('reports.request');
            Route::post('/reports/user/{user}', [ReportController::class, 'orgStoreUser'])->middleware('throttle:5,1')->name('reports.user');
            Route::post('/bugs', [BugReportController::class, 'store'])->middleware('throttle:5,1')->name('bug-reports.store');

            Route::get('/profile/edit', [ProfileController::class, 'edit'])->name('profile.edit');
            // TASK-1223 : « Mes usages IA » — transparence du ledger canonique,
            // scope strict user courant + Organization courante.
            Route::get('/profile/ai-usage', [UserAiUsageController::class, 'index'])->name('profile.ai-usage');
            Route::get('/profile/ai-usage/offers', [UserAiUsageController::class, 'offers'])->name('profile.ai-offers');
            Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
            Route::patch('/profile/availability', [ProfileController::class, 'toggleAvailability'])->name('profile.availability');
            Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

            // Member AI Profile wizard
            Route::middleware('ai-profiles.enabled')->group(function () {
                Route::get('/agent-ia', [AgentIaController::class, 'index'])->name('agent-ia.index');
                Route::get('/agent-ia/edit', [AgentIaController::class, 'wizard'])->name('agent-ia.wizard');
                Route::get('/agent-ia/setup', [AgentIaController::class, 'setup'])->name('agent-ia.setup');
                Route::get('/agent-ia/test', [AgentIaController::class, 'test'])->name('agent-ia.test');
                Route::get('/agent-ia/echanges', [MemberAiProfileInteractionController::class, 'index'])->name('agent-ia.interactions');
                Route::get('/agent-ia/echanges/conversations', [MemberAiProfileConversationsController::class, 'index'])->middleware('consume.org')->name('agent-ia.conversations');
                Route::get('/agent-ia/echanges/conversations/{conversation}', [MemberAiProfileConversationsController::class, 'show'])->middleware('consume.org')->name('agent-ia.conversations.show');
            });

            Route::middleware('loops.enabled')->group(function () {
                // `no_store` : voir la route courte `loops.index` (TASK-1277).
                Route::get('/loops', [LoopController::class, 'index'])->middleware('cache.headers:no_store')->name('loops.index');
                Route::get('/loops/create', [LoopController::class, 'create'])->name('loops.create');
                Route::post('/loops', [LoopController::class, 'store'])->middleware('throttle:5,1')->name('loops.store');
                Route::get('/loops/{loop}', [LoopController::class, 'show'])->name('loops.show');
                // L'agenda de l'Organization : lecture seule, il agrege ce qui a ete
                // organise dans les Boucles. Declare avant /loops/{loop} n'est pas
                // necessaire — le segment differe — mais reste groupe avec elles.
                Route::get('/agenda', [LoopEventAgendaController::class, 'index'])->name('events.agenda');
                Route::get('/loops/{loop}/edit', [LoopController::class, 'edit'])->name('loops.edit');
                Route::put('/loops/{loop}', [LoopController::class, 'update'])->name('loops.update');
                Route::post('/loops/{loop}/join', [LoopController::class, 'join'])->name('loops.join');
                Route::post('/loops/{loop}/leave', [LoopController::class, 'leave'])->name('loops.leave');
                // Archivage par le proprietaire (TASK-1086). Distinct de loops.update :
                // celle-ci refuse une Boucle archivee, ce qui rendrait la reactivation
                // inaccessible a la seule personne censee pouvoir la demander.
                Route::post('/loops/{loop}/archive', [LoopController::class, 'archive'])->name('loops.archive');
                Route::post('/loops/{loop}/reactivate', [LoopController::class, 'reactivate'])->name('loops.reactivate');
                Route::post('/loops/{loop}/join-requests', [LoopController::class, 'storeJoinRequest'])->middleware('throttle:5,1')->name('loops.join-requests.store');
                Route::get('/loops/{loop}/invite', [LoopController::class, 'invite'])->name('loops.invite');
                Route::post('/loops/{loop}/invite/members', [LoopController::class, 'storeMembers'])->middleware('throttle:10,1')->name('loops.invite.members');
                Route::post('/loops/{loop}/invitations', [LoopInvitationController::class, 'store'])->middleware('throttle:10,1')->name('loops.invitations.store');
                Route::post('/loops/{loop}/members', [LoopController::class, 'addMember'])->name('loops.members.add');
                Route::post('/loops/{loop}/messages', [LoopController::class, 'storeMessage'])->name('loops.messages.store');
                Route::post('/loops/{loop}/ask-ai', [LoopController::class, 'askAi'])->middleware('throttle:5,1')->name('loops.ai');
                Route::post('/loops/{loop}/help-request/analyze', [LoopController::class, 'analyzeHelpIntention'])->name('loops.help-request.analyze');
                Route::post('/loops/{loop}/help-request/continue', [LoopController::class, 'prepareHelpRequest'])->name('loops.help-request.continue');
                Route::post('/loops/{loop}/knowledge', [LoopController::class, 'knowledge'])->middleware('throttle:5,1')->name('loops.knowledge.ask');
                // « Ecrire un article » depuis la Card Dossiers : un brouillon
                // lie d'un coup au Dossier racine ET a la Boucle, puis
                // l'editeur Blog existant. Contexte Organization seulement,
                // comme tout le systeme documentaire.
                Route::post('/loops/{loop}/dossier/articles', [LoopDossierArticleController::class, 'store'])->middleware('throttle:10,1')->name('loops.dossier.articles.store');
                // « Personnaliser ma Boucle » — l'ecran du proprietaire. Meme
                // service que l'administration (LoopPresetConfigurator), donc
                // memes gardes ; seul le langage change. Contexte Organization
                // seulement : la Boucle appartient a un tenant.
                Route::get('/loops/{loop}/outils', [LoopToolsController::class, 'index'])->name('loops.tools');
                // TASK-1476 — « Rattrape-moi depuis… » : lecture pure, periode explicite.
                // L'acces est celui de l'espace de travail (LoopPolicy::viewWorkspace),
                // demande dans le controleur : aucune seconde autorite ici.
                Route::get('/loops/{loop}/rattrapage', LoopCatchUpController::class)->name('loops.catch-up');
                Route::post('/loops/{loop}/outils', [LoopToolsController::class, 'update'])->middleware('throttle:30,1')->name('loops.tools.update');
            });

            Route::middleware('verified')->group(function () {
                Route::post('/likes/toggle', [LikeController::class, 'toggle'])->name('likes.toggle');

                // Dossiers (org-scoped, private foundation)
                Route::get('/dossiers', [DossierController::class, 'index'])->name('dossiers.index');
                Route::get('/dossiers/create', [DossierController::class, 'create'])->name('dossiers.create');
                Route::post('/dossiers', [DossierController::class, 'store'])->name('dossiers.store');
                Route::get('/dossiers/{dossier}', [DossierController::class, 'show'])->name('dossiers.show');
                Route::get('/dossiers/{dossier}/semantic-search', DossierSemanticSearchController::class)->name('dossiers.semantic-search');
                Route::post('/dossiers/{dossier}/insights', DossierInsightsController::class)->middleware('throttle:5,1')->name('dossiers.insights');
                Route::post('/dossiers/{dossier}/articles', [DossierArticleController::class, 'store'])->name('dossiers.articles.store');
                Route::post('/dossiers/{dossier}/articles/create-and-attach', [DossierArticleController::class, 'createAndAttach'])->name('dossiers.articles.create-and-attach');
                Route::patch('/dossiers/{dossier}/articles/{post}/move', [DossierArticleController::class, 'move'])->name('dossiers.articles.move');
                Route::delete('/dossiers/{dossier}/articles/{post}', [DossierArticleController::class, 'destroy'])->name('dossiers.articles.destroy');
                Route::patch('/dossiers/{dossier}/articles/reorder', [DossierArticleController::class, 'reorder'])->name('dossiers.articles.reorder');
                Route::get('/dossiers/{dossier}/articles/search', [DossierArticleController::class, 'search'])->name('dossiers.articles.search');
                Route::get('/dossiers/{dossier}/members', [DossierMemberController::class, 'index'])->name('dossiers.members.index');
                Route::post('/dossiers/{dossier}/members', [DossierMemberController::class, 'store'])->name('dossiers.members.store');
                Route::patch('/dossiers/{dossier}/members/{member}', [DossierMemberController::class, 'update'])->name('dossiers.members.update');
                Route::delete('/dossiers/{dossier}/members/{member}', [DossierMemberController::class, 'destroy'])->name('dossiers.members.destroy');
                Route::get('/dossiers/{dossier}/members/search', [DossierMemberController::class, 'search'])->name('dossiers.members.search');
                Route::get('/dossiers/{dossier}/series', [DossierSeriesController::class, 'show'])->name('dossiers.series.show');
                Route::post('/dossiers/{dossier}/series', [DossierSeriesController::class, 'store'])->name('dossiers.series.store');
                Route::patch('/dossiers/{dossier}/series', [DossierSeriesController::class, 'update'])->name('dossiers.series.update');
                Route::delete('/dossiers/{dossier}/series', [DossierSeriesController::class, 'destroy'])->name('dossiers.series.destroy');
                Route::post('/dossiers/{dossier}/series/annexes', [DossierSeriesController::class, 'addAnnex'])->name('dossiers.series.annexes.store');
                Route::delete('/dossiers/{dossier}/series/annexes/{item}', [DossierSeriesController::class, 'removeAnnex'])->name('dossiers.series.annexes.destroy');
                Route::patch('/dossiers/{dossier}/series/annexes/reorder', [DossierSeriesController::class, 'reorderAnnexes'])->name('dossiers.series.annexes.reorder');
                Route::get('/dossiers/{dossier}/edit', [DossierController::class, 'edit'])->name('dossiers.edit');
                Route::patch('/dossiers/{dossier}', [DossierController::class, 'update'])->name('dossiers.update');
                Route::patch('/dossiers/{dossier}/unshare', [DossierController::class, 'unshare'])->name('dossiers.unshare');
                Route::delete('/dossiers/{dossier}', [DossierController::class, 'destroy'])->name('dossiers.destroy');
                Route::get('/dossiers/{dossier}/files', [DossierFileController::class, 'index'])->name('dossiers.files.index');
                Route::post('/dossiers/{dossier}/files', [DossierFileController::class, 'store'])->name('dossiers.files.store');
                Route::get('/dossiers/{dossier}/files/{file}', [DossierFileController::class, 'show'])->name('dossiers.files.show');
                Route::get('/dossiers/{dossier}/files/{file}/preview', [DossierFileController::class, 'preview'])->name('dossiers.files.preview');
                Route::delete('/dossiers/{dossier}/files/{file}', [DossierFileController::class, 'destroy'])->name('dossiers.files.destroy');
                Route::patch('/dossiers/{dossier}/files/{file}/move', [DossierFileController::class, 'move'])->name('dossiers.files.move');
                Route::patch('/dossiers/{dossier}/files/{file}/rename', [DossierFileController::class, 'rename'])->name('dossiers.files.rename');
                Route::get('/dossiers/{dossier}/files/{file}/markdown', [DossierFileController::class, 'markdown'])->name('dossiers.files.markdown');
                Route::patch('/dossiers/{dossier}/files/{file}/markdown', [DossierFileController::class, 'updateMarkdown'])->name('dossiers.files.markdown.update');

                // Blog (org-scoped)
                Route::get('/blog/rediger/nouveau', [BlogController::class, 'orgCreate'])->name('blog.create');
                Route::get('/blog/mes-articles', [BlogController::class, 'orgMyPosts'])->name('blog.my-posts');
                Route::post('/blog', [BlogController::class, 'orgStore'])->name('blog.store');
                Route::get('/blog/rediger/{post:slug}/modifier', [BlogController::class, 'orgEdit'])->name('blog.edit');
                Route::put('/blog/{post:slug}', [BlogController::class, 'orgUpdate'])->name('blog.update');
                Route::patch('/blog/{post:slug}/publier', [BlogController::class, 'orgPublish'])->name('blog.publish');
                Route::delete('/blog/{post:slug}', [BlogController::class, 'orgDestroy'])->name('blog.destroy');
                Route::post('/blog/{post:slug}/commentaires', [BlogCommentController::class, 'orgStore'])->name('blog.comment.store');
                Route::delete('/commentaires/{comment}', [BlogCommentController::class, 'orgDestroy'])->name('blog.comment.destroy');
                Route::post('/blog/upload-image', [BlogController::class, 'orgUploadImage'])->name('blog.upload-image');
                Route::post('/blog/ai-generate', [BlogController::class, 'orgAiGenerate'])->name('blog.ai-generate');
                Route::post('/blog/ai-correct', [BlogController::class, 'orgAiCorrect'])->name('blog.ai-correct');
                Route::post('/blog/ai-method-selection', [BlogController::class, 'orgAiMethodSelection'])->name('blog.ai-method-selection');
                Route::post('/blog/ai-remaining', [BlogController::class, 'orgAiRemaining'])->name('blog.ai-remaining');
                Route::post('/blog/creer-brouillon', [BlogController::class, 'orgCreateDraft'])->name('blog.create-draft');

                // Blog annotation endpoints (org-scoped)
                Route::get('/blog/{post:slug}/annotations', [BlogAnnotationController::class, 'orgIndex'])->name('blog.annotations.index');
                Route::post('/blog/{post:slug}/annotations', [BlogAnnotationController::class, 'orgStore'])->name('blog.annotations.store');
                Route::put('/blog/{post:slug}/annotations/{annotation}', [BlogAnnotationController::class, 'orgUpdate'])->name('blog.annotations.update');
                Route::delete('/blog/{post:slug}/annotations/{annotation}', [BlogAnnotationController::class, 'orgDestroy'])->name('blog.annotations.destroy');
                Route::patch('/blog/{post:slug}/annotations/{annotation}/resolve', [BlogAnnotationController::class, 'orgResolve'])->name('blog.annotations.resolve');

                // Blog annotation reply endpoints (org-scoped)
                Route::get('/blog/{post:slug}/annotations/{annotation}/replies', [BlogAnnotationReplyController::class, 'orgIndex'])->name('blog.annotations.replies.index');
                Route::post('/blog/{post:slug}/annotations/{annotation}/replies', [BlogAnnotationReplyController::class, 'orgStore'])->name('blog.annotations.replies.store');
                Route::put('/blog/{post:slug}/annotations/{annotation}/replies/{reply}', [BlogAnnotationReplyController::class, 'orgUpdate'])->name('blog.annotations.replies.update');
                Route::delete('/blog/{post:slug}/annotations/{annotation}/replies/{reply}', [BlogAnnotationReplyController::class, 'orgDestroy'])->name('blog.annotations.replies.destroy');

                Route::put('/blog/{post:slug}/content', [BlogController::class, 'orgSaveContent'])->name('blog.save-content');

                // Blog snapshot endpoints (org-scoped)
                Route::post('/blog/{post:slug}/snapshots', [BlogSnapshotController::class, 'orgStore'])->name('blog.snapshots.store');
                Route::get('/blog/{post:slug}/snapshots', [BlogSnapshotController::class, 'orgIndex'])->name('blog.snapshots.index');
                Route::post('/blog/{post:slug}/snapshots/{snapshot}/restore', [BlogSnapshotController::class, 'orgRestore'])->name('blog.snapshots.restore');

                // Blog loop endpoints (org-scoped)
                Route::post('/blog/{post:slug}/loops', [BlogPostLoopController::class, 'orgStore'])->name('blog.loops.store');
                Route::delete('/blog/{post:slug}/loops/{loop}', [BlogPostLoopController::class, 'orgDestroy'])->name('blog.loops.destroy');
                Route::get('/blog/{post:slug}/loop-messages', [BlogPostLoopController::class, 'orgMessages'])->name('blog.loops.messages');
                Route::post('/blog/{post:slug}/loops/{loop}/messages', [BlogPostLoopController::class, 'orgStoreMessage'])->name('blog.loops.messages.store');

                // Blog co-author endpoints (org-scoped)
                Route::get('/blog/{post:slug}/co-authors', [BlogCoAuthorController::class, 'orgIndex'])->name('blog.co-authors.index');
                Route::post('/blog/{post:slug}/co-authors', [BlogCoAuthorController::class, 'orgStore'])->name('blog.co-authors.store');
                Route::delete('/blog/{post:slug}/co-authors/{user}', [BlogCoAuthorController::class, 'orgDestroy'])->name('blog.co-authors.destroy');
                Route::get('/blog/{post:slug}/co-authors/search', [BlogCoAuthorController::class, 'orgSearch'])->name('blog.co-authors.search');

                // Blog todo endpoints (org-scoped)
                Route::get('/blog/{post:slug}/todos', [BlogTodoController::class, 'orgIndex'])->name('blog.todos.index');
                Route::post('/blog/{post:slug}/todos', [BlogTodoController::class, 'orgStore'])->name('blog.todos.store');
                Route::put('/blog/{post:slug}/todos/{todo}', [BlogTodoController::class, 'orgUpdate'])->name('blog.todos.update');
                Route::delete('/blog/{post:slug}/todos/{todo}', [BlogTodoController::class, 'orgDestroy'])->name('blog.todos.destroy');
                Route::post('/blog/{post:slug}/todos/{todo}/threads', [BlogTodoController::class, 'orgThreadStore'])->name('blog.todos.threads.store');
                Route::delete('/blog/{post:slug}/todos/{todo}/threads/{thread}', [BlogTodoController::class, 'orgThreadDestroy'])->name('blog.todos.threads.destroy');

                // Blog Explorer endpoints (org-scoped)
                Route::post('/blog/{post:slug}/explorer/chat', [BlogExplorerController::class, 'orgChat'])->name('blog.explorer.chat');
                Route::post('/blog/{post:slug}/explorer/note', [BlogExplorerController::class, 'orgGenerateNote'])->name('blog.explorer.note.generate');
                Route::post('/blog/{post:slug}/explorer/feedback', [BlogExplorerController::class, 'orgStoreFeedback'])->name('blog.explorer.feedback.store');
                Route::get('/blog/{post:slug}/explorer/notes', [BlogExplorerController::class, 'orgIndexNotes'])->name('blog.explorer.notes.index');
                Route::post('/blog/{post:slug}/explorer/notes', [BlogExplorerController::class, 'orgStoreNote'])->name('blog.explorer.notes.store');
                Route::put('/blog/{post:slug}/explorer/notes/{note}', [BlogExplorerController::class, 'orgUpdateNote'])->name('blog.explorer.notes.update');
                Route::delete('/blog/{post:slug}/explorer/notes/{note}', [BlogExplorerController::class, 'orgDestroyNote'])->name('blog.explorer.notes.destroy');

                // Blog plan endpoint (org-scoped)
                Route::patch('/blog/{post:slug}/plan', [BlogController::class, 'orgUpdatePlan'])->name('blog.plan.update');

                // Blog dossier classification endpoints (org-scoped)
                Route::get('/blog/dossiers', [BlogDossierApiController::class, 'orgListDossiers'])->name('blog.dossiers.index');
                Route::post('/blog/dossiers', [BlogDossierApiController::class, 'orgQuickCreate'])->name('blog.dossiers.store');
                Route::get('/blog/{post:slug}/dossier', [BlogDossierApiController::class, 'orgCurrentDossier'])->name('blog.dossier.current');
                Route::post('/blog/{post:slug}/dossier', [BlogDossierApiController::class, 'orgAttach'])->name('blog.dossier.attach');
                Route::delete('/blog/{post:slug}/dossier', [BlogDossierApiController::class, 'orgDetach'])->name('blog.dossier.detach');

                // Blog invitation endpoints (org-scoped)
                Route::get('/blog/{post:slug}/invitations', [BlogInvitationController::class, 'orgIndex'])->name('blog.invite.index');
                Route::post('/blog/{post:slug}/invite', [BlogInvitationController::class, 'orgStore'])->name('blog.invite.store')->middleware('throttle:10,1');
            });

            Route::get('/flux', OrganizationFeed::class)->name('flux');
            Route::get('/flux/creer', CreateFeedPost::class)->name('flux.create');
            Route::get('/flux/mes-annonces', MyFeedPosts::class)->name('flux.my');
            Route::get('/flux/modifier/{feedPost}', EditFeedPost::class)->name('flux.edit');
            Route::get('/flux/{feedPost}', ViewFeedPost::class)->name('flux.show')->whereUuid('feedPost');

        });

        // TASK-1488 (P0 privacy) — ce bloc s'annoncait « Public organization-scoped
        // detail routes used by Explorer ». Les trois membres de la phrase sont
        // faux depuis TASK-1479 : l'Explorer est member-only, la fiche de profil
        // juste en dessous porte deja la garde, et « Public » n'a jamais voulu
        // dire « ouvert au Web anonyme ».
        //
        // Mesure : `orgShow()` delegue a `show()`, qui ne verifiait que la
        // coherence de tenant — donc l'Organization que l'URL designe. Resultat,
        // un membre de l'Organization B obtenait 200 sur la fiche d'un Service de
        // l'Organization A. Les deux gardes sont necessaires et un sabotage le
        // prouve : `auth` seul laisserait ce cross-tenant AUTHENTIFIE ouvert.
        Route::get('/services/{service}', [ServiceController::class, 'orgShow'])->middleware(['auth', 'organization.member'])->name('services.show')->whereUuid('service');
        Route::get('/requests/{request}', [RequestController::class, 'orgShow'])->middleware(['auth', 'organization.member'])->name('requests.show')->whereUuid('request');
        Route::get('/profile/{user}', [ProfileController::class, 'show'])->middleware(['auth', 'organization.member'])->name('profile.show')->whereUuid('user');
        Route::middleware('ai-profiles.enabled')->group(function () {
            Route::get('/profile/{user}/agent-ia', [ProfileController::class, 'aiAgentChat'])->middleware('consume.org')->name('agent-ia.profile.chat')->whereUuid('user');
        });

        // TASK-1479 (P0 privacy) — l'annuaire et les echanges sont des surfaces
        // INTERNES d'Organization. Mesure faite : elles etaient servies en HTTP 200 a
        // un visiteur ANONYME, sur des Organizations privees comprises, avec noms,
        // villes, biographies et affiliations. `auth` ferme l'acces anonyme,
        // `organization.member` ferme le cross-tenant authentifie — les deux sont
        // necessaires, et un sabotage le prouve.
        Route::get('/explorer', [ExplorerController::class, 'index'])->middleware(['auth', 'organization.member'])->name('explorer');
        Route::get('/membres', [HomeController::class, 'members'])->middleware(['auth', 'organization.member'])->name('members.index');
        Route::get('/echanges', [HomeController::class, 'exchanges'])->middleware(['auth', 'organization.member'])->name('exchanges.index');

        // Organization admin dashboard (org-scoped)
        Route::middleware(['auth', OrgAdminMiddleware::class])
            ->prefix('admin')
            ->name('admin.')
            ->group(function () {
                Route::get('/', [OrgAdminController::class, 'dashboard'])->name('dashboard');
                // TASK-1513 — la supervision des fichiers d'une Organization.
                // Elle vit dans la CONSOLE et non sous `/org/{org}/drives` : la
                // page liste le NOM de tous les fichiers de tous les Dossiers,
                // prives compris. Hors console, elle tomberait sous
                // `organization.member` et tout membre y verrait les titres des
                // documents prives des autres — une fuite.
                Route::get('/drives', [OrgAdminController::class, 'drives'])->name('drives');
                Route::post('/drives/{file}/reindex', [OrgAdminController::class, 'reindexDriveFile'])
                    ->middleware('throttle:20,1')
                    ->name('drives.reindex');

                // Exchanges
                Route::get('/services', [OrgAdminController::class, 'services'])->name('services');
                Route::get('/requests', [OrgAdminController::class, 'requests'])->name('requests');
                Route::patch('/requests/{serviceRequest}/close', [OrgAdminController::class, 'closeRequest'])->name('requests.close');
                Route::get('/transactions', [OrgAdminController::class, 'transactions'])->name('transactions');

                // Content
                Route::get('/blog', [OrgAdminController::class, 'blog'])->name('blog');
                Route::patch('/blog/{blogPost}/publish', [OrgAdminController::class, 'publishBlogPost'])->name('blog.publish');
                Route::get('/categories', [OrgAdminController::class, 'categories'])->name('categories');
                Route::get('/categories/create', [OrgAdminController::class, 'createCategory'])->name('categories.create');
                Route::post('/categories', [OrgAdminController::class, 'storeCategory'])->name('categories.store');
                Route::get('/categories/{category}/edit', [OrgAdminController::class, 'editCategory'])->name('categories.edit');
                Route::put('/categories/{category}', [OrgAdminController::class, 'updateCategory'])->name('categories.update');
                Route::delete('/categories/{category}', [OrgAdminController::class, 'destroyCategory'])->name('categories.destroy');
                Route::post('/categories/{category}/skills', [OrgAdminController::class, 'storeCategorySkill'])->name('categories.skills.store');
                Route::delete('/skills/{skill}', [OrgAdminController::class, 'destroyCategorySkill'])->name('skills.destroy');

                // Community
                Route::get('/loops', [OrgAdminController::class, 'loops'])->name('loops');
                Route::get('/loops/{loop}/edit', [OrgAdminController::class, 'editLoop'])->name('loops.edit');
                Route::put('/loops/{loop}', [OrgAdminController::class, 'updateLoop'])->name('loops.update');
                Route::put('/loops/{loop}/cards', [OrgAdminController::class, 'updateLoopCards'])->name('loops.cards.update');
                Route::patch('/loops/{loop}/toggle-active', [OrgAdminController::class, 'toggleLoopActive'])->name('loops.toggle-active');
                // Le configurateur, cote Organization (TASK-1090). Meme service
                // et meme vue que l'ecran plateforme.
                Route::get('/loops/{loop}/configure', [OrgAdminController::class, 'configureLoop'])->name('loops.configure');
                Route::post('/loops/{loop}/compose', [OrgAdminController::class, 'composeLoopCards'])->name('loops.compose');
                Route::post('/loops/{loop}/preset', [OrgAdminController::class, 'applyLoopPreset'])->name('loops.preset.apply');
                Route::patch('/composition-policy', [OrgAdminController::class, 'updateCompositionPolicy'])->name('composition-policy.update');
                Route::post('/loops/{loop}/members', [OrgAdminController::class, 'addLoopMember'])->name('loops.members.add');
                Route::put('/loops/{loop}/members/{member}/role', [OrgAdminController::class, 'updateLoopMemberRole'])->name('loops.members.role');
                Route::delete('/loops/{loop}/members/{member}', [OrgAdminController::class, 'removeLoopMember'])->name('loops.members.remove');
                Route::get('/messages', [OrgAdminController::class, 'messages'])->name('messages');
                Route::get('/users', [OrgAdminController::class, 'users'])->name('users');
                Route::patch('/users/{user}/toggle-ban', [OrgAdminController::class, 'toggleUserBan'])->name('users.toggle-ban');
                Route::get('/users/{user}/delete-preview', [OrgAdminController::class, 'deletePreview'])->name('users.delete-preview');
                Route::post('/users/{user}/delete', [OrgAdminController::class, 'deleteUser'])->name('users.delete');

                // TASK-1416 (CRM-4) : « Relations », le Mini-CRM de l'Organization.
                // {contact} est resolu DANS l'Organization par le controller (404
                // pour un Contact d'ailleurs), jamais par un binding global.
                // TASK-1424 (CRM-14) : « Aujourd'hui » est la page d'entree de Relations ; la
                // liste des Contacts vit sous /relations/contacts, son nom de route est inchange.
                Route::get('/relations', [OrgCrmController::class, 'today'])->name('crm.today');
                Route::get('/relations/contacts', [OrgCrmController::class, 'contacts'])->name('crm.contacts');
                Route::post('/relations/contacts', [OrgCrmController::class, 'storeContact'])->name('crm.contacts.store');
                // TASK-1446 : AcquisitionJourney foundation — {journey} est resolu DANS l'Organization par le controller (404 ailleurs).
                Route::get('/acquisition', [OrgAcquisitionController::class, 'index'])->name('acquisition');
                Route::get('/acquisition/create', [OrgAcquisitionController::class, 'create'])->name('acquisition.create');
                Route::post('/acquisition', [OrgAcquisitionController::class, 'store'])->name('acquisition.store');
                Route::get('/acquisition/{journey}/edit', [OrgAcquisitionController::class, 'edit'])->name('acquisition.edit')->whereUuid('journey');
                Route::put('/acquisition/{journey}', [OrgAcquisitionController::class, 'update'])->name('acquisition.update')->whereUuid('journey');
                Route::post('/acquisition/{journey}/publish', [OrgAcquisitionController::class, 'publish'])->name('acquisition.publish')->whereUuid('journey');
                Route::delete('/acquisition/{journey}', [OrgAcquisitionController::class, 'retire'])->name('acquisition.retire')->whereUuid('journey');
                // TASK-1450 : Workshop domain foundation — {workshop} est resolu DANS l'Organization par le controller (404 ailleurs).
                Route::get('/ateliers', [OrgWorkshopController::class, 'index'])->name('workshops');
                Route::get('/ateliers/create', [OrgWorkshopController::class, 'create'])->name('workshops.create');
                Route::post('/ateliers', [OrgWorkshopController::class, 'store'])->name('workshops.store');
                Route::get('/ateliers/{workshop}/edit', [OrgWorkshopController::class, 'edit'])->name('workshops.edit')->whereUuid('workshop');
                Route::put('/ateliers/{workshop}', [OrgWorkshopController::class, 'update'])->name('workshops.update')->whereUuid('workshop');
                Route::post('/ateliers/{workshop}/publish', [OrgWorkshopController::class, 'publish'])->name('workshops.publish')->whereUuid('workshop');
                Route::delete('/ateliers/{workshop}', [OrgWorkshopController::class, 'retire'])->name('workshops.retire')->whereUuid('workshop');
                // TASK-1451 (B4-A) : les sessions d'un atelier — {workshop} dans l'Organization, {session} dans le Workshop.
                Route::get('/ateliers/{workshop}/sessions', [OrgWorkshopSessionController::class, 'index'])->name('workshops.sessions')->whereUuid('workshop');
                Route::get('/ateliers/{workshop}/sessions/create', [OrgWorkshopSessionController::class, 'create'])->name('workshops.sessions.create')->whereUuid('workshop');
                Route::post('/ateliers/{workshop}/sessions', [OrgWorkshopSessionController::class, 'store'])->name('workshops.sessions.store')->whereUuid('workshop');
                Route::get('/ateliers/{workshop}/sessions/{session}/edit', [OrgWorkshopSessionController::class, 'edit'])->name('workshops.sessions.edit')->whereUuid('workshop')->whereUuid('session');
                Route::put('/ateliers/{workshop}/sessions/{session}', [OrgWorkshopSessionController::class, 'update'])->name('workshops.sessions.update')->whereUuid('workshop')->whereUuid('session');
                Route::post('/ateliers/{workshop}/sessions/{session}/publish', [OrgWorkshopSessionController::class, 'publish'])->name('workshops.sessions.publish')->whereUuid('workshop')->whereUuid('session');
                Route::delete('/ateliers/{workshop}/sessions/{session}', [OrgWorkshopSessionController::class, 'cancel'])->name('workshops.sessions.cancel')->whereUuid('workshop')->whereUuid('session');
                // TASK-1455 : inscrits & interets d'un atelier — LECTURE SEULE (V3 §13, MASTER Q81).
                Route::get('/ateliers/{workshop}/inscrits', [OrgWorkshopRegistrantsController::class, 'show'])->name('workshops.registrants')->whereUuid('workshop');
                // TASK-1419 (CRM-4b) : gestion du pipeline. Declare AVANT /relations/{contact}
                // pour que « statuts » ne soit jamais pris pour un id de Contact.
                Route::get('/relations/statuts', [OrgCrmController::class, 'statuses'])->name('crm.statuses');
                Route::post('/relations/statuts', [OrgCrmController::class, 'storeStatus'])->name('crm.statuses.store');
                Route::put('/relations/statuts/{status}', [OrgCrmController::class, 'updateStatus'])->name('crm.statuses.update');
                Route::post('/relations/statuts/{status}/move', [OrgCrmController::class, 'moveStatus'])->name('crm.statuses.move');
                Route::post('/relations/statuts/{status}/toggle', [OrgCrmController::class, 'toggleStatus'])->name('crm.statuses.toggle');
                Route::post('/relations/statuts/{status}/default', [OrgCrmController::class, 'defaultStatus'])->name('crm.statuses.default');
                // TASK-1420 (CRM-7a) : modeles d'email de l'Organization. Declare AVANT
                // /relations/{contact} : « modeles » n'est jamais un id de Contact.
                Route::get('/relations/modeles', [OrgCrmTemplateController::class, 'index'])->name('crm.templates');
                Route::get('/relations/modeles/nouveau', [OrgCrmTemplateController::class, 'create'])->name('crm.templates.create');
                Route::post('/relations/modeles', [OrgCrmTemplateController::class, 'store'])->name('crm.templates.store');
                Route::get('/relations/modeles/{template}/modifier', [OrgCrmTemplateController::class, 'edit'])->name('crm.templates.edit');
                Route::put('/relations/modeles/{template}', [OrgCrmTemplateController::class, 'update'])->name('crm.templates.update');
                Route::get('/relations/modeles/{template}/apercu', [OrgCrmTemplateController::class, 'preview'])->name('crm.templates.preview');
                // TASK-1417 (CRM-5) : la fiche Contact et l'edition tracee de ses coordonnees.
                Route::get('/relations/{contact}', [OrgCrmController::class, 'show'])->name('crm.contacts.show');
                Route::put('/relations/{contact}', [OrgCrmController::class, 'updateContact'])->name('crm.contacts.update');
                Route::post('/relations/{contact}/status', [OrgCrmController::class, 'changeStatus'])->name('crm.contacts.status');
                Route::post('/relations/{contact}/notes', [OrgCrmController::class, 'storeNote'])->name('crm.contacts.notes.store');
                // TASK-1418 (CRM-6) : la prochaine action — planifier, marquer faite.
                Route::post('/relations/{contact}/next-action', [OrgCrmController::class, 'planNextAction'])->name('crm.contacts.next-action.plan');
                Route::post('/relations/{contact}/next-action/complete', [OrgCrmController::class, 'completeNextAction'])->name('crm.contacts.next-action.complete');
                // TASK-1421 (CRM-7b) : envoyer un modele d'email a un Contact — choisir,
                // confirmer (jeton one-shot), envoyer. L'humain declenche tout.
                Route::get('/relations/{contact}/email', [OrgCrmController::class, 'pickEmailTemplate'])->name('crm.contacts.email.pick');
                Route::get('/relations/{contact}/email/{template}', [OrgCrmController::class, 'previewEmail'])->name('crm.contacts.email.preview');
                Route::post('/relations/{contact}/email/{template}', [OrgCrmController::class, 'sendEmail'])->name('crm.contacts.email.send');
                // TASK-1426 (CRM-8) : relire l'email reellement envoye/tente (lecture seule, 404 hors tenant/Contact).
                Route::get('/relations/{contact}/emails/{log}', [OrgCrmController::class, 'showEmail'])->name('crm.contacts.emails.show');
                // TASK-1422 (CRM-13) : contactabilite, decidee explicitement avec une raison.
                Route::post('/relations/{contact}/policy', [OrgCrmController::class, 'changePolicy'])->name('crm.contacts.policy');
                Route::post('/users/{user}/follow', [OrgCrmController::class, 'followMember'])->name('crm.members.follow');

                // Administration
                Route::get('/reports', [OrgAdminController::class, 'reports'])->name('reports');
                Route::patch('/bug-reports/{bugReport}/resolve', [OrgAdminController::class, 'resolveBugReport'])->name('reports.resolve');
                Route::get('/invitations', [OrgAdminController::class, 'invitations'])->name('invitations');
                Route::get('/translations', [OrgAdminController::class, 'translations'])->name('translations');
                Route::post('/translations', [OrgAdminController::class, 'storeOverride'])->name('translations.store');
                Route::patch('/translations/{translationOverride}/deactivate', [OrgAdminController::class, 'deactivateOverride'])->name('translations.deactivate');
                Route::post('/translations/reset', [OrgAdminController::class, 'resetOverride'])->name('translations.reset');

                // Identity / Branding
                Route::get('/identity', [OrgAdminController::class, 'identity'])->name('identity');
                Route::post('/identity', [OrgAdminController::class, 'updateIdentity'])->name('identity.update');

                // TASK-1212 : configuration IA du tenant (provider, modele, credential, budget)
                Route::get('/ai', [OrgAdminController::class, 'ai'])->name('ai');
                Route::put('/ai', [OrgAdminController::class, 'updateAi'])->name('ai.update');
                // TASK-1229 : override d'Organization du credit IA par utilisateur
                // (reglage plateforme / valeur propre / illimite), trace.
                Route::put('/ai/user-credit', [OrgAdminController::class, 'updateAiUserCredit'])->name('ai.user-credit.update');
                // TASK-1306 : qui gere le credential (plateforme / Organization).
                // Reserve au SuperAdmin, protection serveur dans le controller —
                // jamais seulement un masquage cote vue.
                Route::put('/ai/credential-mode', [OrgAdminController::class, 'updateAiCredentialMode'])->name('ai.credential-mode.update');

                // Design
                Route::get('/homepage', [OrgAdminController::class, 'homepage'])->name('homepage');
                Route::put('/homepage', [OrgAdminController::class, 'updateHomepage'])->name('homepage.update');

                // Themes
                Route::get('/themes', [OrgAdminController::class, 'themes'])->name('themes');
                Route::get('/themes/create', [OrgAdminController::class, 'themesCreate'])->name('themes.create');
                Route::post('/themes', [OrgAdminController::class, 'themesStore'])->name('themes.store');
                Route::get('/themes/{theme}/edit', [OrgAdminController::class, 'themesEdit'])->name('themes.edit');
                Route::put('/themes/{theme}', [OrgAdminController::class, 'themesUpdate'])->name('themes.update');
                Route::delete('/themes/{theme}', [OrgAdminController::class, 'themesDestroy'])->name('themes.destroy');
                Route::post('/themes/{theme}/assign', [OrgAdminController::class, 'themesAssign'])->name('themes.assign');

                // AI
                // TASK-1223 : hub « IA & connaissances » — l'etat du systeme
                // IA de l'Organization en une page, liens vers les consoles.
                Route::get('/ai-cockpit', [OrgAdminController::class, 'aiCockpit'])->name('ai-cockpit');
                // TASK-1227 : « Comportement IA » — Constitution (lecture
                // seule), doctrine de l'Organization (versionnee), couverture
                // du systeme nerveux, bac a sable reel « tester sans publier ».
                // TASK-1481 — le PLAN de la gouvernance IA : une page READ ONLY
                // qui nomme chaque autorite, son niveau, si cet Admin peut la
                // changer, et l'ecran qui la gouverne. Aucune ecriture, aucune
                // autorite nouvelle — un plan de situation.
                Route::get('/ai-map', [OrgAdminController::class, 'aiMap'])->name('ai-map');
                Route::get('/ai-behavior', [OrgAdminController::class, 'aiBehavior'])->name('ai-behavior');
                Route::put('/ai-behavior/doctrine', [OrgAdminController::class, 'updateAiDoctrine'])->name('ai-behavior.doctrine.update');
                Route::delete('/ai-behavior/doctrine', [OrgAdminController::class, 'withdrawAiDoctrine'])->name('ai-behavior.doctrine.withdraw');
                // TASK-1348 — Constitution de CETTE Organization. L'Organization
                // vient du route model binding : la cible reste explicite meme
                // pour un Super Admin, qui ne peut ecrire que sur celle qu'il a
                // ouverte.
                Route::put('/ai-behavior/constitution', [OrgAdminController::class, 'updateAiConstitution'])->name('ai-behavior.constitution.update');
                Route::delete('/ai-behavior/constitution', [OrgAdminController::class, 'withdrawAiConstitution'])->name('ai-behavior.constitution.withdraw');
                // TASK-1349 — page DEDIEE a la Constitution de l'organisation.
                // Elle partage l'autorite d'ecriture ci-dessus : seul l'ecran
                // change, jamais la logique de versionnement.
                Route::get('/constitution', [OrgAdminController::class, 'aiConstitution'])->name('constitution');
                Route::put('/constitution/publication', [OrgAdminController::class, 'updateAiConstitutionPublication'])->name('constitution.publication');
                Route::post('/ai-behavior/sandbox', [OrgAdminController::class, 'sandboxAiDoctrine'])->middleware('throttle:ai-doctrine-sandbox')->name('ai-behavior.sandbox');
                Route::get('/ai-supervision', [OrgAdminController::class, 'aiSupervision'])->name('ai-supervision');
                Route::get('/member-ai-profiles', [OrgAdminController::class, 'memberAiProfiles'])->name('member-ai-profiles');
                Route::get('/ai-interactions', [OrgAdminController::class, 'aiInteractions'])->name('ai-interactions');
                // TASK-1217 : console RAG read-only — ce que l'IA connait des
                // Dossiers de cette Organization, et si l'index est sain.
                Route::get('/ai-knowledge', [OrgAdminController::class, 'aiKnowledge'])->name('ai-knowledge');
                // TASK-1226 : fragment de rafraichissement de l'Observatoire
                // (polling leger, read-only, meme middleware que la page).
                Route::get('/ai-knowledge/live', [OrgAdminController::class, 'aiKnowledgeLive'])->middleware('throttle:120,1')->name('ai-knowledge.live');
                // TASK-1307 : inspecter les extraits REELLEMENT indexes d'une
                // source (metadonnees + contenu des chunks, jamais le vecteur
                // embedding) — read-only, 0 appel provider.
                Route::get('/ai-knowledge/sources/{type}/{source}', [OrgAdminController::class, 'aiKnowledgeSourceChunks'])
                    ->whereIn('type', ['article', 'file'])
                    ->name('ai-knowledge.source');
                // TASK-1307 : recherche documentaire BRUTE (pgvector, sans
                // generation LLM) depuis la console — diagnostic deterministe
                // du retrieval reel, borne a un embedding de requete par appel.
                Route::get('/ai-knowledge/search', [OrgAdminController::class, 'aiKnowledgeSearch'])
                    ->middleware('throttle:20,1')
                    ->name('ai-knowledge.search');
                // TASK-1219 : console de consommation IA read-only — ce que la
                // garde economique compte deja pour cette Organization.
                Route::get('/ai-consumption', [OrgAdminController::class, 'aiConsumption'])->name('ai-consumption');
                // TASK-1487 (AI Quality Q2) : « Qualite IA » — la console
                // SŒUR de la consommation. L'une dit COMBIEN, l'autre dit si
                // l'on SAIT que ca aide. Read-only, borne a cette
                // Organization, jamais une conversation.
                Route::get('/ai-quality', [OrgAdminController::class, 'aiQuality'])->name('ai-quality');

                // Stats
                Route::get('/stats/login-history', [OrgAdminController::class, 'loginHistory'])->name('stats.login-history');
                Route::get('/stats/login-history/user/{user}', [OrgAdminController::class, 'loginHistoryUser'])->name('stats.login-history.user');

                // System email templates (org-scoped)
                Route::get('/system-email-templates', [OrgAdminController::class, 'systemEmailTemplates'])->name('system-email-templates');
                Route::get('/system-email-templates/{systemEmailTemplate}/edit', [OrgAdminController::class, 'editSystemEmailTemplate'])->name('system-email-templates.edit');
                Route::put('/system-email-templates/{systemEmailTemplate}', [OrgAdminController::class, 'updateSystemEmailTemplate'])->name('system-email-templates.update');
            });

        // Blog (org-scoped, en parallèle des routes /blog root)
        Route::get('/blog', [BlogController::class, 'index'])->name('blog.index');
        Route::get('/blog/categorie/{slug}', [BlogController::class, 'orgByCategory'])->name('blog.category');
        Route::get('/blog/tag/{slug}', [BlogController::class, 'orgByTag'])->name('blog.tag');
        Route::get('/blog/{post:slug}', [BlogController::class, 'orgShow'])->name('blog.show');
    });
