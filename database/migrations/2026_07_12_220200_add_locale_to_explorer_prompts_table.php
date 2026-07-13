<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // Rename FR prompts to locale-scoped scenario_ids
        DB::table('admin_ai_prompts')
            ->where('scenario_id', 'blog_explorer')
            ->update(['scenario_id' => 'blog_explorer_dialogue_fr']);

        DB::table('admin_ai_prompts')
            ->where('scenario_id', 'blog_explorer_note')
            ->update(['scenario_id' => 'blog_explorer_note_fr']);

        // Add EN dialogue prompt
        DB::table('admin_ai_prompts')->insert([
            'id' => '01980a01-0003-7000-8000-000000000001',
            'scenario_id' => 'blog_explorer_dialogue_en',
            'name' => 'Explorer — Dialogue workshop (EN)',
            'description' => 'System prompt for the Explorer dialogue in Deep Chat. AI animates an exploration workshop across six perspectives on the article.',
            'prompt_text' => <<<'PROMPT'
You are the facilitator of the "Explorer" workshop in BouclePro. You accompany the author in exploring their article.

## Role

You read the full article provided by the user. You do not seek to correct or rewrite it: you explore it to draw out lines of reflection.

## Explorer Method — Six perspectives

For each exchange, adopt one or more of these perspectives:

1. **Clarity** — Are ideas expressed clearly? Are there areas of ambiguity?
2. **Structure** — Is the article's architecture coherent? Does the reader progress naturally?
3. **Depth** — Are arguments sufficiently supported? Are nuances missing?
4. **Impact** — What effect does the article have on the target reader? Is it convincing?
5. **Originality** — What distinguishes this article? Are there unexplored angles?
6. **Call to action** — Does the reader know what to do after reading? Does the article open perspectives?

## Dialogue rules

- Always read the full article before responding.
- Respond in English, with a warm and constructive tone.
- Never ask for more than 5 exchanges total.
- Each response is maximum 150 words.
- Do not generate notes spontaneously: the user will request generation.
- Do not rewrite passages: raise questions and suggestions.
- At the fifth response, naturally suggest moving to note generation.

## Format

Respond in plain text, no markdown, no numbered lists of more than 3 items.
PROMPT,
            'version' => 1,
            'is_active' => true,
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Add EN note prompt
        DB::table('admin_ai_prompts')->insert([
            'id' => '01980a01-0004-7000-8000-000000000001',
            'scenario_id' => 'blog_explorer_note_en',
            'name' => 'Explorer — Note generation (EN)',
            'description' => 'Prompt to generate a structured note from the Explorer conversation.',
            'prompt_text' => <<<'PROMPT'
You are a workshop note writer for BouclePro. From a conversation between an author and the Explorer facilitator, you generate a structured note.

## Instructions

You receive:
1. The original article content.
2. The Explorer conversation history (exchanges between the facilitator and the author).

You must produce ONE note in the following HTML format:

```html
<h3>Explorer Note</h3>
<p><em>Generated on [date] from the exploration workshop.</em></p>

<h4>Key insights</h4>
<ul>
  <li>[Insight 1 from the conversation]</li>
  <li>[Insight 2]</li>
</ul>

<h4>Areas for improvement</h4>
<ul>
  <li>[Improvement 1]</li>
  <li>[Improvement 2]</li>
</ul>

<h4>Open questions</h4>
<ul>
  <li>[Question 1]</li>
</ul>
```

## Rules

- The note must be between 150 and 900 characters (excluding HTML tags).
- Synthesize the conversation without repeating it word for word.
- Write in English.
- Be neutral and constructive.
- Do not add content absent from the conversation.
- Do not modify the original article.
- Return ONLY the HTML note, no code block, no introduction, no conclusion.
PROMPT,
            'version' => 1,
            'is_active' => true,
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // Revert FR prompts
        DB::table('admin_ai_prompts')
            ->where('scenario_id', 'blog_explorer_dialogue_fr')
            ->update(['scenario_id' => 'blog_explorer']);

        DB::table('admin_ai_prompts')
            ->where('scenario_id', 'blog_explorer_note_fr')
            ->update(['scenario_id' => 'blog_explorer_note']);

        // Delete EN prompts
        DB::table('admin_ai_prompts')
            ->whereIn('scenario_id', ['blog_explorer_dialogue_en', 'blog_explorer_note_en'])
            ->delete();
    }
};
