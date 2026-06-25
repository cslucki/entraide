<?php

use App\Http\Controllers\Admin\AdminAiBenchmarkController;
use App\Http\Controllers\Admin\AdminAiConfigController;
use App\Http\Controllers\Admin\AdminAiInteractionController;
use App\Http\Controllers\Admin\AdminAiPromptController;
use App\Http\Controllers\Admin\AdminAiReviewQueueController;
use App\Http\Controllers\Admin\AdminAiSupervisionController;
use App\Http\Controllers\Admin\AdminAiUsageController;
use App\Http\Controllers\Admin\AdminBlogController;
use App\Http\Controllers\Admin\AdminBugReportController;
use App\Http\Controllers\Admin\AdminCategoryController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\AdminEmailController;
use App\Http\Controllers\Admin\AdminEmailLogsController;
use App\Http\Controllers\Admin\AdminEmailTemplatesController;
use App\Http\Controllers\Admin\AdminIaDesignLabController;
use App\Http\Controllers\Admin\AdminIaUsageByUserController;
use App\Http\Controllers\Admin\AdminLoopController;
use App\Http\Controllers\Admin\AdminMemberAiProfileController;
use App\Http\Controllers\Admin\AdminMessageController;
use App\Http\Controllers\Admin\AdminOrganizationController;
use App\Http\Controllers\Admin\AdminOrganizationRequestController;
use App\Http\Controllers\Admin\AdminOutilsController;
use App\Http\Controllers\Admin\AdminReferralController;
use App\Http\Controllers\Admin\AdminThemeController;
use App\Http\Controllers\Admin\AdminTranslationController;
use App\Http\Controllers\Admin\OrgAdminController;
use App\Http\Controllers\AiAgentLoopController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\BlogCommentController;
use App\Http\Controllers\BlogController;
use App\Http\Controllers\BugReportController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExplorerController;
use App\Http\Controllers\FavoriteController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\LikeController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\LoopController;
use App\Http\Controllers\MemberAiProfileInteractionController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\OrganizationLandingController;
use App\Http\Controllers\OrganizationRequestController;
use App\Http\Controllers\PointController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RequestController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\TransactionController;
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
Route::post('/locale/{locale}', [LocaleController::class, 'switch'])->name('locale.switch');
Route::get('/explorer', [ExplorerController::class, 'index'])->name('explorer');
Route::get('/membres', [HomeController::class, 'members'])->name('members.index');
Route::get('/echanges', [HomeController::class, 'exchanges'])->name('exchanges.index');
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
    Route::post('/blog/ai-remaining', [BlogController::class, 'aiRemaining'])->name('blog.ai-remaining');
    Route::post('/blog/creer-brouillon', [BlogController::class, 'createDraft'])->name('blog.create-draft');
});

// Blog — wildcard slug EN DERNIER
Route::get('/blog/{post:slug}', [BlogController::class, 'show'])->name('blog.show');

Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('/search', [SearchController::class, 'index'])->name('search');
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
    });
    Route::get('/services/{service}/edit', [ServiceController::class, 'edit'])->name('services.edit');
    Route::put('/services/{service}', [ServiceController::class, 'update'])->name('services.update');
    Route::delete('/services/{service}', [ServiceController::class, 'destroy'])->name('services.destroy');

    // Requests (demandes)
    Route::middleware('profile.complete')->group(function () {
        Route::get('/requests/create', [RequestController::class, 'create'])->name('requests.create');
        Route::post('/requests', [RequestController::class, 'store'])->name('requests.store');
    });
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

    // Favorites
    Route::get('/favorites', [FavoriteController::class, 'index'])->name('favorites.index');
    Route::post('/favorites/{service}/toggle', [FavoriteController::class, 'toggle'])->middleware('throttle:30,1')->name('favorites.toggle');

    // Reports
    Route::post('/reports/service/{service}', [ReportController::class, 'storeService'])->middleware('throttle:5,1')->name('reports.service');
    Route::post('/reports/request/{serviceRequest}', [ReportController::class, 'storeRequest'])->middleware('throttle:5,1')->name('reports.request');
    Route::post('/reports/user/{user}', [ReportController::class, 'storeUser'])->middleware('throttle:5,1')->name('reports.user');
    Route::post('/bugs', [BugReportController::class, 'store'])->middleware('throttle:5,1')->name('bug-reports.store');

    // Profile
    Route::get('/profile/edit', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('/profile/availability', [ProfileController::class, 'toggleAvailability'])->name('profile.availability');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Member AI Profile wizard
    Route::view('/agent-ia', 'agent-ia.wizard')->name('agent-ia.wizard');
    Route::get('/agent-ia/echanges', [MemberAiProfileInteractionController::class, 'index'])->name('agent-ia.interactions');

    Route::delete('/agent-ia/profile', function () {
        MemberAiProfile::where('user_id', auth()->id())->delete();

        return response()->json(['ok' => true]);
    })->name('agent-ia.profile.reset');

    // Bounded member AI agent
    Route::get('/agent-ia/member/{user}', BoundedMemberAgent::class)
        ->name('agent-ia.member.presentation');

    // Loops
    Route::middleware('loops.enabled')->group(function () {
        Route::get('/loops', [LoopController::class, 'index'])->name('loops.index');
        Route::get('/loops/create', [LoopController::class, 'create'])->name('loops.create');
        Route::post('/loops', [LoopController::class, 'store'])->name('loops.store');
        Route::get('/loops/{loop}', [LoopController::class, 'show'])->name('loops.show');
        Route::post('/loops/{loop}/join', [LoopController::class, 'join'])->name('loops.join');
        Route::post('/loops/{loop}/leave', [LoopController::class, 'leave'])->name('loops.leave');
        Route::post('/loops/{loop}/members', [LoopController::class, 'addMember'])->name('loops.members.add');
        Route::post('/loops/{loop}/messages', [LoopController::class, 'storeMessage'])->name('loops.messages.store');
        Route::post('/loops/{loop}/help-request/analyze', [LoopController::class, 'analyzeHelpIntention'])->name('loops.help-request.analyze');
        Route::post('/loops/{loop}/help-request/publish', [LoopController::class, 'publishHelpRequest'])->name('loops.help-request.publish');
    });
});

Route::get('/services/{service}', [ServiceController::class, 'show'])->name('services.show')->whereUuid('service');
Route::get('/requests/{request}', [RequestController::class, 'show'])->name('requests.show');
Route::get('/profile/{user}', [ProfileController::class, 'show'])->name('profile.show');
Route::get('/profile/{user}/agent-ia', [ProfileController::class, 'aiAgentChat'])->name('agent-ia.profile.chat');
Route::post('/profile/{user}/agent-ia/discuter', [AiAgentLoopController::class, 'startConversation'])->name('agent-ia.conversation.start');

// Admin routes
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/dashboard', [AdminController::class, 'dashboard'])->name('dashboard');
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
    Route::get('/organizations/create', [AdminOrganizationController::class, 'create'])->name('organizations.create');
    Route::post('/organizations', [AdminOrganizationController::class, 'store'])->name('organizations.store');
    Route::get('/organizations/{organization}/edit', [AdminOrganizationController::class, 'edit'])->name('organizations.edit');
    Route::put('/organizations/{organization}', [AdminOrganizationController::class, 'update'])->name('organizations.update');
    Route::post('/organizations/{organization}/toggle-active', [AdminOrganizationController::class, 'toggleActive'])->name('organizations.toggle-active');
    Route::delete('/organizations/{organization}', [AdminOrganizationController::class, 'destroy'])->name('organizations.destroy');
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

    // Email logs
    Route::get('/email-logs', [AdminEmailLogsController::class, 'index'])->name('email-logs');
    Route::get('/email-logs/{emailLog}', [AdminEmailLogsController::class, 'show'])->name('email-logs.show');

    // IA Design Lab (test interne)
    Route::get('/ia-design-lab', [AdminIaDesignLabController::class, 'index'])->name('ia-design-lab');
    Route::post('/ia-design-lab', [AdminIaDesignLabController::class, 'test'])->name('ia-design-lab.test');

    // Centre de supervision IA (T078.1) — appel réel OpenAI gpt-4o-mini
    Route::get('/ai-supervision', [AdminAiSupervisionController::class, 'index'])->name('ai-supervision');
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

    // IA Usage dashboard (TASK-306 Lot 3)
    Route::get('/ia-usage', [AdminAiUsageController::class, 'index'])->name('ia-usage');
    Route::get('/ia-usage/{interaction}', [AdminAiUsageController::class, 'show'])->name('ia-usage.show');
    Route::get('/ia-usage/admin/{interaction}', [AdminAiUsageController::class, 'showAdmin'])->name('ia-usage.show-admin');

    // IA Usage by user (TASK-306)
    Route::get('/ia-usage-by-user', [AdminIaUsageByUserController::class, 'index'])->name('ia-usage-by-user');

    // Blog moderation
    Route::get('/blog', [AdminBlogController::class, 'index'])->name('blog');
    Route::get('/blog/{post}/edit', [AdminBlogController::class, 'edit'])->name('blog.edit');
    Route::put('/blog/{post}', [AdminBlogController::class, 'update'])->name('blog.update');
    Route::patch('/blog/{post}/status', [AdminBlogController::class, 'updateStatus'])->name('blog.status');
    Route::post('/blog/preview-markdown', [AdminBlogController::class, 'previewMarkdown'])->name('blog.preview-markdown');
    Route::delete('/blog/{post}', [AdminBlogController::class, 'destroy'])->name('blog.destroy');

    // Loops Center
    Route::get('/loops', [AdminLoopController::class, 'index'])->name('loops');
    Route::get('/loops/create', [AdminLoopController::class, 'create'])->name('loops.create');
    Route::post('/loops', [AdminLoopController::class, 'store'])->name('loops.store');
    Route::get('/loops/{loop}/edit', [AdminLoopController::class, 'edit'])->name('loops.edit');
    Route::put('/loops/{loop}', [AdminLoopController::class, 'update'])->name('loops.update');
    Route::post('/loops/{loop}/members', [AdminLoopController::class, 'addMember'])->name('loops.members.add');
    Route::delete('/loops/{loop}/members/{member}', [AdminLoopController::class, 'removeMember'])->name('loops.members.remove');
    Route::get('/loops/{loop}/files', [AdminLoopController::class, 'files'])->name('loops.files');
    Route::post('/loops/{loop}/archive', [AdminLoopController::class, 'archive'])->name('loops.archive');
    Route::post('/loops/{loop}/restore', [AdminLoopController::class, 'restore'])->name('loops.restore');
    Route::delete('/loops/{loop}', [AdminLoopController::class, 'destroy'])->name('loops.destroy');

    // Outils
    Route::get('/outils/assign-data', [AdminOutilsController::class, 'assignData'])->name('outils.assign-data');
    Route::post('/outils/assign-data', [AdminOutilsController::class, 'doAssignData'])->name('outils.assign-data.do');
    Route::get('/outils/assign-data/detail', [AdminOutilsController::class, 'assignDataDetail'])->name('outils.assign-data.detail');
    Route::get('/outils/fix-categories', [AdminOutilsController::class, 'fixCategories'])->name('outils.fix-categories');
    Route::post('/outils/fix-categories', [AdminOutilsController::class, 'doFixCategories'])->name('outils.fix-categories.do');
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

        Route::middleware('auth')->group(function () {
            Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
            Route::get('/dashboard/requests', [DashboardController::class, 'requests'])->name('dashboard.requests');
            Route::get('/dashboard/requests/{serviceRequest}', [DashboardController::class, 'requestDetail'])->name('dashboard.requests.detail')->middleware('consume.org')->whereUuid('serviceRequest');
            Route::get('/dashboard/services', [DashboardController::class, 'services'])->name('dashboard.services');
            Route::get('/dashboard/services/{service}', [DashboardController::class, 'serviceDetail'])->name('dashboard.services.detail')->middleware('consume.org')->whereUuid('service');
            Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

            Route::middleware('profile.complete')->group(function () {
                Route::get('/services/create', [ServiceController::class, 'create'])->name('services.create');
                Route::post('/services', [ServiceController::class, 'store'])->name('services.store');
            });
            Route::get('/services/{service}/edit', [ServiceController::class, 'edit'])->middleware('consume.org')->name('services.edit');
            Route::put('/services/{service}', [ServiceController::class, 'update'])->middleware('consume.org')->name('services.update');
            Route::delete('/services/{service}', [ServiceController::class, 'destroy'])->middleware('consume.org')->name('services.destroy');

            Route::middleware('profile.complete')->group(function () {
                Route::get('/requests/create', [RequestController::class, 'create'])->name('requests.create');
                Route::post('/requests', [RequestController::class, 'store'])->name('requests.store');
            });
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

            Route::get('/favorites', [FavoriteController::class, 'index'])->name('favorites.index');
            Route::post('/favorites/{service}/toggle', [FavoriteController::class, 'toggle'])->middleware('throttle:30,1')->middleware('consume.org')->name('favorites.toggle');

            Route::post('/reports/service/{service}', [ReportController::class, 'orgStoreService'])->middleware('throttle:5,1')->name('reports.service');
            Route::post('/reports/request/{serviceRequest}', [ReportController::class, 'orgStoreRequest'])->middleware('throttle:5,1')->name('reports.request');
            Route::post('/reports/user/{user}', [ReportController::class, 'orgStoreUser'])->middleware('throttle:5,1')->name('reports.user');
            Route::post('/bugs', [BugReportController::class, 'store'])->middleware('throttle:5,1')->name('bug-reports.store');

            Route::get('/profile/edit', [ProfileController::class, 'edit'])->name('profile.edit');
            Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
            Route::patch('/profile/availability', [ProfileController::class, 'toggleAvailability'])->name('profile.availability');
            Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

            // Member AI Profile wizard
            Route::view('/agent-ia', 'agent-ia.wizard')->name('agent-ia.wizard');
            Route::get('/agent-ia/echanges', [MemberAiProfileInteractionController::class, 'index'])->name('agent-ia.interactions');

            Route::middleware('loops.enabled')->group(function () {
                Route::get('/loops', [LoopController::class, 'index'])->name('loops.index');
                Route::get('/loops/create', [LoopController::class, 'create'])->name('loops.create');
                Route::post('/loops', [LoopController::class, 'store'])->name('loops.store');
                Route::get('/loops/{loop}', [LoopController::class, 'show'])->name('loops.show');
                Route::post('/loops/{loop}/join', [LoopController::class, 'join'])->name('loops.join');
                Route::post('/loops/{loop}/leave', [LoopController::class, 'leave'])->name('loops.leave');
                Route::post('/loops/{loop}/members', [LoopController::class, 'addMember'])->name('loops.members.add');
                Route::post('/loops/{loop}/messages', [LoopController::class, 'storeMessage'])->name('loops.messages.store');
                Route::post('/loops/{loop}/help-request/analyze', [LoopController::class, 'analyzeHelpIntention'])->name('loops.help-request.analyze');
                Route::post('/loops/{loop}/help-request/publish', [LoopController::class, 'publishHelpRequest'])->name('loops.help-request.publish');
            });

            Route::middleware('verified')->group(function () {
                Route::post('/likes/toggle', [LikeController::class, 'toggle'])->name('likes.toggle');

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
                Route::post('/blog/ai-remaining', [BlogController::class, 'orgAiRemaining'])->name('blog.ai-remaining');
                Route::post('/blog/creer-brouillon', [BlogController::class, 'orgCreateDraft'])->name('blog.create-draft');
            });

            Route::get('/flux', OrganizationFeed::class)->name('flux');
            Route::get('/flux/creer', CreateFeedPost::class)->name('flux.create');
            Route::get('/flux/mes-annonces', MyFeedPosts::class)->name('flux.my');
            Route::get('/flux/modifier/{feedPost}', EditFeedPost::class)->name('flux.edit');
            Route::get('/flux/{feedPost}', ViewFeedPost::class)->name('flux.show')->whereUuid('feedPost');

        });

        // Public organization-scoped detail routes used by Explorer.
        Route::get('/services/{service}', [ServiceController::class, 'orgShow'])->name('services.show')->whereUuid('service');
        Route::get('/requests/{request}', [RequestController::class, 'orgShow'])->name('requests.show')->whereUuid('request');
        Route::get('/profile/{user}', [ProfileController::class, 'show'])->name('profile.show')->whereUuid('user');

        Route::get('/explorer', [ExplorerController::class, 'index'])->name('explorer');
        Route::get('/membres', [HomeController::class, 'members'])->name('members.index');
        Route::get('/echanges', [HomeController::class, 'exchanges'])->name('exchanges.index');

        // Organization admin dashboard (org-scoped)
        Route::middleware(['auth', OrgAdminMiddleware::class])
            ->prefix('admin')
            ->name('admin.')
            ->group(function () {
                Route::get('/', [OrgAdminController::class, 'dashboard'])->name('dashboard');

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
                Route::patch('/loops/{loop}/toggle-active', [OrgAdminController::class, 'toggleLoopActive'])->name('loops.toggle-active');
                Route::get('/messages', [OrgAdminController::class, 'messages'])->name('messages');
                Route::get('/users', [OrgAdminController::class, 'users'])->name('users');
                Route::patch('/users/{user}/toggle-ban', [OrgAdminController::class, 'toggleUserBan'])->name('users.toggle-ban');

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

                // AI
                Route::get('/ai-supervision', [OrgAdminController::class, 'aiSupervision'])->name('ai-supervision');
                Route::get('/member-ai-profiles', [OrgAdminController::class, 'memberAiProfiles'])->name('member-ai-profiles');
                Route::get('/ai-interactions', [OrgAdminController::class, 'aiInteractions'])->name('ai-interactions');
            });

        // Blog (org-scoped, en parallèle des routes /blog root)
        Route::get('/blog', [BlogController::class, 'index'])->name('blog.index');
        Route::get('/blog/categorie/{slug}', [BlogController::class, 'orgByCategory'])->name('blog.category');
        Route::get('/blog/tag/{slug}', [BlogController::class, 'orgByTag'])->name('blog.tag');
        Route::get('/blog/{post:slug}', [BlogController::class, 'orgShow'])->name('blog.show');
    });
