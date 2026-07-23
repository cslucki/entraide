<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LocaleController extends Controller
{
    public function switch(Request $request, string $locale): RedirectResponse
    {
        abort_unless(in_array($locale, ['fr', 'en'], true), 404);

        $request->session()->put('locale', $locale);

        $redirectTo = $request->string('redirect_to')->toString();

        if ($redirectTo === url('/') || Str::startsWith($redirectTo, url('/').'/')) {
            return redirect()->to($redirectTo);
        }

        return redirect('/');
    }
}
