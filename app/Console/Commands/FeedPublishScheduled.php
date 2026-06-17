<?php

namespace App\Console\Commands;

use App\Models\FeedPost;
use Illuminate\Console\Command;

class FeedPublishScheduled extends Command
{
    protected $signature = 'feed:publish-scheduled';

    protected $description = 'Publish scheduled feed announcements whose scheduled date has passed';

    public function handle(): int
    {
        $posts = FeedPost::dueForPublication()->get();

        if ($posts->isEmpty()) {
            $this->info('No scheduled announcements due for publication.');

            return Command::SUCCESS;
        }

        $now = now();
        $count = 0;

        foreach ($posts as $post) {
            $post->update([
                'status' => FeedPost::STATUS_PUBLISHED,
                'published_at' => $now,
            ]);
            $count++;
        }

        $this->info("Published {$count} scheduled announcement(s).");

        return Command::SUCCESS;
    }
}
