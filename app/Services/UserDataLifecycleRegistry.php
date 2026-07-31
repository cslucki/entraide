<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UserDataLifecycleRegistry
{
    public const POLICY_TRANSFER = 'transfer';

    public const POLICY_DETACH = 'detach';

    public const POLICY_DELETE = 'delete';

    public const POLICY_ANONYMIZE = 'anonymize';

    public const POLICY_RETAIN = 'retain';

    public const POLICY_BLOCK = 'block';

    /**
     * Central declarative registry. It classifies data only; it never deletes,
     * transfers, detaches or anonymizes records.
     */
    public static function entries(): array
    {
        return [
            ['key' => 'admin_ai_interactions_reviewed_by', 'type' => 'sql', 'table' => 'admin_ai_interactions', 'column' => 'reviewed_by', 'policy' => self::POLICY_RETAIN, 'org_scope' => 'none', 'justification' => 'Admin AI review audit trail is retained.'],
            ['key' => 'admin_ai_interactions_user_id', 'type' => 'sql', 'table' => 'admin_ai_interactions', 'column' => 'user_id', 'policy' => self::POLICY_RETAIN, 'org_scope' => 'none', 'justification' => 'Admin AI activity is platform audit data.'],
            ['key' => 'ai_interactions', 'type' => 'sql', 'table' => 'ai_interactions', 'column' => 'user_id', 'policy' => self::POLICY_ANONYMIZE, 'org_scope' => 'direct', 'justification' => 'User AI usage may contain personal prompts.'],
            ['key' => 'article_series_created_by', 'type' => 'sql', 'table' => 'article_series', 'column' => 'created_by', 'policy' => self::POLICY_DETACH, 'org_scope' => 'direct', 'justification' => 'Series can survive with creator detached.'],
            ['key' => 'article_series_items_added_by', 'type' => 'sql', 'table' => 'article_series_items', 'column' => 'added_by', 'policy' => self::POLICY_DETACH, 'org_scope' => 'through_article_series', 'justification' => 'Series item audit can be detached.'],
            ['key' => 'badge_user', 'type' => 'sql', 'table' => 'badge_user', 'column' => 'user_id', 'policy' => self::POLICY_DELETE, 'org_scope' => 'user_organization', 'justification' => 'Badge assignment is user-specific derived data.'],
            ['key' => 'blog_analysis_notes', 'type' => 'sql', 'table' => 'blog_analysis_notes', 'column' => 'user_id', 'policy' => self::POLICY_DELETE, 'org_scope' => 'through_blog_post', 'justification' => 'Analysis notes are tied to the user/blog lifecycle.'],
            ['key' => 'blog_annotation_replies', 'type' => 'sql', 'table' => 'blog_annotation_replies', 'column' => 'user_id', 'policy' => self::POLICY_ANONYMIZE, 'org_scope' => 'through_blog_annotation', 'justification' => 'Reply actor needs anonymization.'],
            ['key' => 'blog_comments', 'type' => 'sql', 'table' => 'blog_comments', 'column' => 'user_id', 'policy' => self::POLICY_ANONYMIZE, 'org_scope' => 'direct', 'justification' => 'Comment authorship is never transferred; retained content needs anonymized attribution.'],
            ['key' => 'blog_post_annotations_resolved_by', 'type' => 'sql', 'table' => 'blog_post_annotations', 'column' => 'resolved_by', 'policy' => self::POLICY_DETACH, 'org_scope' => 'through_blog_post', 'justification' => 'Resolver audit can be detached.'],
            ['key' => 'blog_post_annotations_user_id', 'type' => 'sql', 'table' => 'blog_post_annotations', 'column' => 'user_id', 'policy' => self::POLICY_ANONYMIZE, 'org_scope' => 'through_blog_post', 'justification' => 'Annotation actor needs anonymization.'],
            ['key' => 'blog_post_invitations_accepted_by_user_id', 'type' => 'sql', 'table' => 'blog_post_invitations', 'column' => 'accepted_by_user_id', 'policy' => self::POLICY_RETAIN, 'org_scope' => 'through_blog_post', 'justification' => 'Invitation acceptance is audit/history.'],
            ['key' => 'blog_post_invitations_sender_id', 'type' => 'sql', 'table' => 'blog_post_invitations', 'column' => 'sender_id', 'policy' => self::POLICY_RETAIN, 'org_scope' => 'through_blog_post', 'justification' => 'Invitation sender is audit/history.'],
            ['key' => 'blog_post_user_added_by', 'type' => 'sql', 'table' => 'blog_post_user', 'column' => 'added_by', 'policy' => self::POLICY_DETACH, 'org_scope' => 'through_blog_post', 'justification' => 'Co-author grant audit can be detached.'],
            ['key' => 'blog_post_user', 'type' => 'sql', 'table' => 'blog_post_user', 'column' => 'user_id', 'policy' => self::POLICY_DELETE, 'org_scope' => 'through_blog_post', 'justification' => 'Co-author membership is user-specific participation.'],
            ['key' => 'blog_posts', 'type' => 'sql', 'table' => 'blog_posts', 'column' => 'user_id', 'policy' => self::POLICY_TRANSFER, 'org_scope' => 'direct', 'justification' => 'Existing dry-run considered blog posts transferable.'],
            ['key' => 'blog_snapshots_created_by', 'type' => 'sql', 'table' => 'blog_snapshots', 'column' => 'created_by', 'policy' => self::POLICY_RETAIN, 'org_scope' => 'through_blog_post', 'justification' => 'Snapshot creator is editorial audit.'],
            ['key' => 'blog_snapshots_updated_by', 'type' => 'sql', 'table' => 'blog_snapshots', 'column' => 'updated_by', 'policy' => self::POLICY_RETAIN, 'org_scope' => 'through_blog_post', 'justification' => 'Snapshot updater is editorial audit.'],
            ['key' => 'blog_todo_threads', 'type' => 'sql', 'table' => 'blog_todo_threads', 'column' => 'user_id', 'policy' => self::POLICY_ANONYMIZE, 'org_scope' => 'through_blog_post', 'justification' => 'Todo thread actor needs anonymization.'],
            ['key' => 'blog_todos_assigned_to', 'type' => 'sql', 'table' => 'blog_todos', 'column' => 'assigned_to', 'policy' => self::POLICY_DETACH, 'org_scope' => 'through_blog_post', 'justification' => 'Assignment can be detached.'],
            ['key' => 'blog_todos_user_id', 'type' => 'sql', 'table' => 'blog_todos', 'column' => 'user_id', 'policy' => self::POLICY_ANONYMIZE, 'org_scope' => 'through_blog_post', 'justification' => 'Todo author identity needs anonymization.'],
            ['key' => 'bug_reports', 'type' => 'sql', 'table' => 'bug_reports', 'column' => 'reporter_id', 'policy' => self::POLICY_RETAIN, 'org_scope' => 'none', 'justification' => 'Bug reports are platform support records.'],
            ['key' => 'community_requests', 'type' => 'sql', 'table' => 'community_requests', 'column' => 'user_id', 'policy' => self::POLICY_RETAIN, 'org_scope' => 'legacy', 'justification' => 'Legacy table only; do not add Community code.'],
            ['key' => 'dossier_blog_posts_added_by', 'type' => 'sql', 'table' => 'dossier_blog_posts', 'column' => 'added_by', 'policy' => self::POLICY_DETACH, 'org_scope' => 'through_dossier', 'justification' => 'Dossier article audit can be detached.'],
            ['key' => 'dossier_files_uploaded_by', 'type' => 'sql', 'table' => 'dossier_files', 'column' => 'uploaded_by', 'policy' => self::POLICY_RETAIN, 'org_scope' => 'through_dossier', 'justification' => 'Dossier file metadata is retained until file lifecycle policy exists.'],
            ['key' => 'dossier_members_added_by', 'type' => 'sql', 'table' => 'dossier_members', 'column' => 'added_by', 'policy' => self::POLICY_DETACH, 'org_scope' => 'through_dossier', 'justification' => 'Membership grant audit can be detached.'],
            ['key' => 'dossier_members', 'type' => 'sql', 'table' => 'dossier_members', 'column' => 'user_id', 'policy' => self::POLICY_DELETE, 'org_scope' => 'through_dossier', 'justification' => 'Dossier membership is user-specific participation.'],
            ['key' => 'dossiers', 'type' => 'sql', 'table' => 'dossiers', 'column' => 'owner_id', 'policy' => self::POLICY_BLOCK, 'org_scope' => 'direct', 'justification' => 'Owned dossiers require explicit transfer/ownership decision before deletion.'],
            ['key' => 'email_logs', 'type' => 'sql', 'table' => 'email_logs', 'column' => 'user_id', 'policy' => self::POLICY_RETAIN, 'org_scope' => 'direct', 'justification' => 'Email logs are operational audit.'],
            ['key' => 'favorites', 'type' => 'sql', 'table' => 'favorites', 'column' => 'user_id', 'policy' => self::POLICY_DELETE, 'org_scope' => 'user_organization', 'justification' => 'Favorites are personal user preferences and are not transferable.'],
            ['key' => 'feed_post_comments', 'type' => 'sql', 'table' => 'feed_post_comments', 'column' => 'user_id', 'policy' => self::POLICY_ANONYMIZE, 'org_scope' => 'direct', 'justification' => 'Comment authorship is never transferred; retained feed discussion needs anonymized attribution.'],
            ['key' => 'feed_posts_pinned_by_id', 'type' => 'sql', 'table' => 'feed_posts', 'column' => 'pinned_by_id', 'policy' => self::POLICY_DETACH, 'org_scope' => 'direct', 'justification' => 'Pin attribution can be detached.'],
            ['key' => 'feed_posts', 'type' => 'sql', 'table' => 'feed_posts', 'column' => 'user_id', 'policy' => self::POLICY_TRANSFER, 'org_scope' => 'direct', 'justification' => 'Existing dry-run considered feed posts transferable.'],
            ['key' => 'likes', 'type' => 'sql', 'table' => 'likes', 'column' => 'user_id', 'policy' => self::POLICY_DELETE, 'org_scope' => 'user_organization', 'justification' => 'Likes are user-specific signals.'],
            ['key' => 'login_logs', 'type' => 'sql', 'table' => 'login_logs', 'column' => 'user_id', 'policy' => self::POLICY_RETAIN, 'org_scope' => 'direct', 'justification' => 'Login history is security audit data.'],
            ['key' => 'loop_memberships', 'type' => 'sql', 'table' => 'loop_members', 'column' => 'user_id', 'policy' => self::POLICY_DETACH, 'org_scope' => 'through_loop', 'justification' => 'Loop membership can be detached.'],
            ['key' => 'loop_messages_pinned_by_id', 'type' => 'sql', 'table' => 'loop_messages', 'column' => 'pinned_by_id', 'policy' => self::POLICY_DETACH, 'org_scope' => 'through_loop', 'justification' => 'Pin attribution can be detached.'],
            ['key' => 'loop_messages_sent', 'type' => 'sql', 'table' => 'loop_messages', 'column' => 'sender_id', 'policy' => self::POLICY_ANONYMIZE, 'org_scope' => 'through_loop', 'justification' => 'Conversation sender can be anonymized.'],
            ['key' => 'loops_created', 'type' => 'sql', 'table' => 'loops', 'column' => 'created_by', 'policy' => self::POLICY_DETACH, 'org_scope' => 'direct', 'justification' => 'Loop creator attribution can be detached.'],
            ['key' => 'member_ai_profile_interactions_owner', 'type' => 'sql', 'table' => 'member_ai_profile_interactions', 'column' => 'profile_owner_user_id', 'policy' => self::POLICY_ANONYMIZE, 'org_scope' => 'direct', 'justification' => 'AI profile interaction content may include personal data.'],
            ['key' => 'member_ai_profile_interactions_visitor', 'type' => 'sql', 'table' => 'member_ai_profile_interactions', 'column' => 'visitor_user_id', 'policy' => self::POLICY_ANONYMIZE, 'org_scope' => 'direct', 'justification' => 'Visitor AI profile interaction content may include personal data.'],
            ['key' => 'member_ai_profile', 'type' => 'sql', 'table' => 'member_ai_profiles', 'column' => 'user_id', 'policy' => self::POLICY_BLOCK, 'org_scope' => 'direct', 'justification' => 'Structured personal profile needs explicit product decision.'],
            ['key' => 'messages_pinned_by_id', 'type' => 'sql', 'table' => 'messages', 'column' => 'pinned_by_id', 'policy' => self::POLICY_DETACH, 'org_scope' => 'through_transaction', 'justification' => 'Pin attribution can be detached.'],
            ['key' => 'messages_sent', 'type' => 'sql', 'table' => 'messages', 'column' => 'sender_id', 'policy' => self::POLICY_ANONYMIZE, 'org_scope' => 'through_transaction', 'justification' => 'Conversation sender can be anonymized.'],
            ['key' => 'organization_requests', 'type' => 'sql', 'table' => 'organization_requests', 'column' => 'user_id', 'policy' => self::POLICY_RETAIN, 'org_scope' => 'none', 'justification' => 'Organization request history is retained.'],
            ['key' => 'orgs_as_admin', 'type' => 'sql', 'table' => 'organizations', 'column' => 'admin_id', 'policy' => self::POLICY_BLOCK, 'org_scope' => 'self', 'justification' => 'Organization admin ownership must be reassigned before deletion.'],
            ['key' => 'point_ledger', 'type' => 'sql', 'table' => 'point_ledger', 'column' => 'user_id', 'policy' => self::POLICY_BLOCK, 'org_scope' => 'direct', 'justification' => 'Point ledger is historical accounting data and blocks deletion until a dedicated decision exists.'],
            ['key' => 'profile_agent_conversations_owner', 'type' => 'sql', 'table' => 'profile_agent_conversations', 'column' => 'profile_owner_user_id', 'policy' => self::POLICY_ANONYMIZE, 'org_scope' => 'direct', 'justification' => 'Profile agent conversation content may include personal data.'],
            ['key' => 'profile_agent_conversations_visitor', 'type' => 'sql', 'table' => 'profile_agent_conversations', 'column' => 'visitor_user_id', 'policy' => self::POLICY_ANONYMIZE, 'org_scope' => 'direct', 'justification' => 'Visitor profile agent content may include personal data.'],
            ['key' => 'reactions', 'type' => 'sql', 'table' => 'reactions', 'column' => 'user_id', 'policy' => self::POLICY_DELETE, 'org_scope' => 'user_organization', 'justification' => 'Reactions are user-specific signals.'],
            ['key' => 'referral_rewards_source', 'type' => 'sql', 'table' => 'referral_rewards', 'column' => 'source_user_id', 'policy' => self::POLICY_RETAIN, 'org_scope' => 'user_organization', 'justification' => 'Reward source is referral audit.'],
            ['key' => 'referral_rewards', 'type' => 'sql', 'table' => 'referral_rewards', 'column' => 'user_id', 'policy' => self::POLICY_RETAIN, 'org_scope' => 'user_organization', 'justification' => 'Reward records are accounting/audit data.'],
            ['key' => 'referrals_received', 'type' => 'sql', 'table' => 'referrals', 'column' => 'referred_user_id', 'policy' => self::POLICY_RETAIN, 'org_scope' => 'user_organization', 'justification' => 'Referral history is retained.'],
            ['key' => 'referrals_made', 'type' => 'sql', 'table' => 'referrals', 'column' => 'referrer_user_id', 'policy' => self::POLICY_RETAIN, 'org_scope' => 'user_organization', 'justification' => 'Referral history is retained.'],
            ['key' => 'reports_filed', 'type' => 'sql', 'table' => 'reports', 'column' => 'reporter_id', 'policy' => self::POLICY_RETAIN, 'org_scope' => 'none', 'justification' => 'Reports are moderation audit data.'],
            ['key' => 'reviews_received', 'type' => 'sql', 'table' => 'reviews', 'column' => 'reviewed_id', 'policy' => self::POLICY_ANONYMIZE, 'org_scope' => 'user_organization', 'justification' => 'Reviews may need retained content with anonymized user.'],
            ['key' => 'reviews_given', 'type' => 'sql', 'table' => 'reviews', 'column' => 'reviewer_id', 'policy' => self::POLICY_ANONYMIZE, 'org_scope' => 'user_organization', 'justification' => 'Reviews may need retained content with anonymized author.'],
            ['key' => 'service_requests', 'type' => 'sql', 'table' => 'service_requests', 'column' => 'user_id', 'policy' => self::POLICY_TRANSFER, 'org_scope' => 'direct', 'justification' => 'Existing dry-run considered service requests transferable.'],
            ['key' => 'services', 'type' => 'sql', 'table' => 'services', 'column' => 'user_id', 'policy' => self::POLICY_TRANSFER, 'org_scope' => 'direct', 'justification' => 'Existing dry-run considered services transferable.'],
            ['key' => 'transactions_as_buyer', 'type' => 'sql', 'table' => 'transactions', 'column' => 'buyer_id', 'policy' => self::POLICY_TRANSFER, 'org_scope' => 'direct', 'justification' => 'Existing dry-run grouped buyer transactions as owned data.'],
            ['key' => 'transactions_as_seller', 'type' => 'sql', 'table' => 'transactions', 'column' => 'seller_id', 'policy' => self::POLICY_TRANSFER, 'org_scope' => 'direct', 'justification' => 'Existing dry-run grouped seller transactions as owned data.'],
            ['key' => 'translation_overrides_created_by', 'type' => 'sql', 'table' => 'translation_overrides', 'column' => 'created_by', 'policy' => self::POLICY_RETAIN, 'org_scope' => 'direct', 'justification' => 'Translation admin audit is retained.'],
            ['key' => 'translation_overrides_updated_by', 'type' => 'sql', 'table' => 'translation_overrides', 'column' => 'updated_by', 'policy' => self::POLICY_RETAIN, 'org_scope' => 'direct', 'justification' => 'Translation admin audit is retained.'],
            ['key' => 'sessions', 'type' => 'non_sql', 'surface' => 'sessions.user_id', 'policy' => self::POLICY_DELETE, 'org_scope' => 'user_organization', 'count' => ['table' => 'sessions', 'column' => 'user_id'], 'justification' => 'Sessions are active user runtime state and have no FK.'],
            ['key' => 'user_avatar_file', 'type' => 'non_sql', 'surface' => 'users.avatar', 'policy' => self::POLICY_RETAIN, 'org_scope' => 'user_organization', 'count' => ['table' => 'users', 'column' => 'id'], 'justification' => 'Avatar files are classified only; file deletion is out of scope.'],
            ['key' => 'blog_files', 'type' => 'non_sql', 'surface' => 'blog_posts.image and embedded blog uploads', 'policy' => self::POLICY_RETAIN, 'org_scope' => 'direct', 'count' => ['table' => 'blog_posts', 'column' => 'user_id'], 'justification' => 'Blog files are classified only; file deletion is out of scope.'],
            ['key' => 'message_files', 'type' => 'non_sql', 'surface' => 'messages.image_path', 'policy' => self::POLICY_RETAIN, 'org_scope' => 'through_transaction', 'count' => ['table' => 'messages', 'column' => 'sender_id'], 'justification' => 'Message files are classified only; file deletion is out of scope.'],
            ['key' => 'service_files', 'type' => 'non_sql', 'surface' => 'service_images.path and request_attachments.path', 'policy' => self::POLICY_RETAIN, 'org_scope' => 'direct', 'count' => ['table' => 'services', 'column' => 'user_id'], 'justification' => 'Service/request files are classified only; file deletion is out of scope.'],
            ['key' => 'dossier_files_storage', 'type' => 'non_sql', 'surface' => 'dossier_files.disk/path', 'policy' => self::POLICY_RETAIN, 'org_scope' => 'through_dossier', 'count' => ['table' => 'dossier_files', 'column' => 'uploaded_by'], 'justification' => 'Dossier file storage is classified only; file deletion is out of scope.'],
            ['key' => 'jobs_payloads', 'type' => 'non_sql', 'surface' => 'jobs.payload failed_jobs.payload/exception job_batches.options', 'policy' => self::POLICY_RETAIN, 'org_scope' => 'none', 'justification' => 'Deferred jobs may contain serialized user/model identifiers.'],
            ['key' => 'dossier_chunks', 'type' => 'non_sql', 'surface' => 'dossier_chunks.content/embedding', 'policy' => self::POLICY_RETAIN, 'org_scope' => 'direct', 'count' => ['table' => 'dossier_chunks', 'column' => 'organization_id', 'matches_organization' => true], 'justification' => 'Embeddings require a dedicated lifecycle policy.'],
            ['key' => 'profile_agent_messages', 'type' => 'non_sql', 'surface' => 'profile_agent_messages.content/metadata', 'policy' => self::POLICY_ANONYMIZE, 'org_scope' => 'through_profile_agent_conversation', 'justification' => 'Profile agent messages are indirectly linked through conversations.'],
            ['key' => 'cache_keys', 'type' => 'non_sql', 'surface' => 'cache/cache_locks organization and indexing keys', 'policy' => self::POLICY_RETAIN, 'org_scope' => 'none', 'justification' => 'Cache keys are runtime/organization-scoped.'],
        ];
    }

    public static function sqlRegistryPairs(): array
    {
        return collect(self::entries())
            ->where('type', 'sql')
            ->map(fn (array $entry) => $entry['table'].'.'.$entry['column'])
            ->values()
            ->all();
    }

    public static function nonSqlEntries(): array
    {
        return collect(self::entries())
            ->where('type', 'non_sql')
            ->values()
            ->all();
    }

    public function preview(User $user, ?Organization $organization = null): array
    {
        $counts = [
            'own' => [],
            'part' => [],
            'audit' => [],
            'delete' => [],
            'retain' => [],
            'block' => [],
            'unclassified' => [],
            'policies' => [],
        ];

        foreach (self::entries() as $entry) {
            $policy = $entry['policy'] ?? 'unclassified';
            $bucket = $this->bucketForPolicy($policy);
            $count = $this->countEntry($entry, $user, $organization);

            $counts[$bucket][$entry['key']] = $count;
            $counts['policies'][$policy][$entry['key']] = $count;
        }

        return $counts;
    }

    public function transferEstimate(User $user, string $transferToId, ?Organization $organization = null): array
    {
        $transferTo = User::assignable()->find($transferToId);
        $organizationId = $organization?->id ?? $user->organization_id;

        if (! $transferTo || $transferTo->organization_id !== $organizationId) {
            return [];
        }

        $counts = [];

        foreach (self::entries() as $entry) {
            if (($entry['policy'] ?? null) === self::POLICY_TRANSFER) {
                $counts[$entry['key']] = $this->countEntry($entry, $user, $organization);
            }
        }

        return $counts;
    }

    private function bucketForPolicy(string $policy): string
    {
        return match ($policy) {
            self::POLICY_TRANSFER => 'own',
            self::POLICY_DETACH => 'part',
            self::POLICY_ANONYMIZE => 'audit',
            self::POLICY_DELETE => 'delete',
            self::POLICY_RETAIN => 'retain',
            self::POLICY_BLOCK => 'block',
            default => 'unclassified',
        };
    }

    private function countEntry(array $entry, User $user, ?Organization $organization): int
    {
        $count = $entry['count'] ?? null;
        $table = $count['table'] ?? $entry['table'] ?? null;
        $column = $count['column'] ?? $entry['column'] ?? null;

        if (! $table || ! $column || ! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return 0;
        }

        $query = DB::table($table);

        if (($count['matches_organization'] ?? false) === true) {
            if (! $organization) {
                return 0;
            }

            $query->where($table.'.'.$column, $organization->id);
        } else {
            $query->where($table.'.'.$column, $user->id);
        }

        $this->applyOrganizationScope($query, $table, $entry['org_scope'] ?? 'none', $organization);

        return $query->count();
    }

    private function applyOrganizationScope(Builder $query, string $table, string $scope, ?Organization $organization): void
    {
        if (! $organization) {
            return;
        }

        match ($scope) {
            'direct' => $this->whereColumnIfExists($query, $table, 'organization_id', $organization->id),
            'user_organization' => $this->whereColumnIfExists($query, $table, 'organization_id', $organization->id),
            'self' => $query->where($table.'.id', $organization->id),
            'through_loop' => $this->joinScoped($query, $table, 'loops', 'loop_id', 'id', $organization->id),
            'through_transaction' => $this->joinScoped($query, $table, 'transactions', 'transaction_id', 'id', $organization->id),
            'through_blog_post' => $this->joinScoped($query, $table, 'blog_posts', 'blog_post_id', 'id', $organization->id),
            'through_blog_annotation' => $this->joinTwoHopScoped($query, $table, 'blog_post_annotations', 'annotation_id', 'id', 'blog_posts', 'blog_post_id', 'id', $organization->id),
            'through_dossier' => $this->joinScoped($query, $table, 'dossiers', 'dossier_id', 'id', $organization->id),
            'through_article_series' => $this->joinScoped($query, $table, 'article_series', 'article_series_id', 'id', $organization->id),
            'through_profile_agent_conversation' => $this->joinScoped($query, $table, 'profile_agent_conversations', 'conversation_id', 'id', $organization->id),
            default => null,
        };
    }

    private function whereColumnIfExists(Builder $query, string $table, string $column, string $value): void
    {
        if (Schema::hasColumn($table, $column)) {
            $query->where($table.'.'.$column, $value);
        }
    }

    private function joinScoped(Builder $query, string $table, string $joinTable, string $localColumn, string $joinColumn, string $organizationId): void
    {
        if (! Schema::hasTable($joinTable) || ! Schema::hasColumn($table, $localColumn) || ! Schema::hasColumn($joinTable, 'organization_id')) {
            return;
        }

        $query->join($joinTable, $table.'.'.$localColumn, '=', $joinTable.'.'.$joinColumn)
            ->where($joinTable.'.organization_id', $organizationId);
    }

    private function joinTwoHopScoped(Builder $query, string $table, string $firstJoinTable, string $localColumn, string $firstJoinColumn, string $secondJoinTable, string $firstJoinLocalColumn, string $secondJoinColumn, string $organizationId): void
    {
        if (! Schema::hasTable($firstJoinTable) || ! Schema::hasTable($secondJoinTable) || ! Schema::hasColumn($table, $localColumn) || ! Schema::hasColumn($firstJoinTable, $firstJoinLocalColumn) || ! Schema::hasColumn($secondJoinTable, 'organization_id')) {
            return;
        }

        $query->join($firstJoinTable, $table.'.'.$localColumn, '=', $firstJoinTable.'.'.$firstJoinColumn)
            ->join($secondJoinTable, $firstJoinTable.'.'.$firstJoinLocalColumn, '=', $secondJoinTable.'.'.$secondJoinColumn)
            ->where($secondJoinTable.'.organization_id', $organizationId);
    }
}
