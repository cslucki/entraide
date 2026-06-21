<?php

namespace App\Console\Commands;

use App\Models\BlogPost;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

#[Signature('blog:convert-to-html')]
#[Description('Convert existing Markdown blog posts to HTML')]
class BlogConvertToHtml extends Command
{
    public function handle()
    {
        if (! Schema::hasColumn('blog_posts', 'content_format')) {
            $this->info('Column content_format does not exist. Nothing to convert.');

            return;
        }

        $posts = BlogPost::whereNull('content_format')
            ->orWhere('content_format', '!=', 'html')
            ->get();

        if ($posts->isEmpty()) {
            $this->info('No posts to convert.');

            return;
        }

        $count = 0;
        foreach ($posts as $post) {
            if (preg_match('/<[a-z][\s>]/i', $post->content)) {
                $this->warn("Skipping post {$post->id} — content appears to be HTML already.");

                continue;
            }

            $post->content = markdown($post->content);
            $post->content_format = 'html';
            $post->save();
            $count++;
        }

        $this->info("Converted {$count} post(s) to HTML.");
    }
}
