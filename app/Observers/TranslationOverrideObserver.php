<?php

namespace App\Observers;

use App\Models\TranslationOverride;
use Illuminate\Support\Facades\Cache;

class TranslationOverrideObserver
{
    public function created(TranslationOverride $override): void
    {
        $this->clearCache($override);
    }

    public function updated(TranslationOverride $override): void
    {
        $this->clearCache($override);
    }

    public function deleted(TranslationOverride $override): void
    {
        $this->clearCache($override);
    }

    public function restored(TranslationOverride $override): void
    {
        $this->clearCache($override);
    }

    protected function clearCache(TranslationOverride $override): void
    {
        $orgKey = $override->organization_id ?? 'global';

        Cache::forget("translation_overrides:{$orgKey}:{$override->locale}");
    }
}
