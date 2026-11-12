<x-admin-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('admin.bulk_email_title') }}
        </h2>
    </x-slot>

    <div class="py-6">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-6">
            @if(session('success'))
                <div class="bg-emerald-50 dark:bg-emerald-900/20 border border-emerald-200 dark:border-emerald-700 rounded-lg p-4 text-sm text-emerald-800 dark:text-emerald-200">
                    {{ session('success') }}
                </div>
            @endif

            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                <div class="p-6">
                    {{-- TASK-1803 — l'ensemble des destinataires est RESOLU une fois
                         pour toutes a la validation de ce formulaire (voir
                         `BulkEmailSendService::resolveRecipients()`), jamais recoche
                         un par un : la cible est un FILTRE (Organization x presence
                         d'une presentation), pas une liste cochee. --}}
                    <form method="POST" action="{{ route('admin.email-bulksending.preview') }}" class="space-y-6"
                          x-data="{
                              scope: '{{ old('organization_id', 'all') === 'all' ? 'all' : 'one' }}',
                              contentSource: '{{ old('content_source', 'custom') }}',
                              format: '{{ old('content_format', 'plain') }}',
                              templates: {{ $templates->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'subject' => $t->subject, 'content_html' => $t->content_html])->values()->toJson() }},
                              selectedTemplateId: '{{ old('email_template_id', '') }}',
                              get selectedTemplate() { return this.templates.find(t => t.id === this.selectedTemplateId) ?? null; },
                          }">
                        @csrf

                        {{-- Cible : toutes les Organizations, ou une seule. --}}
                        <fieldset>
                            <legend class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                {{ __('admin.bulk_email_scope_label') }}
                            </legend>
                            <div class="mt-2 space-y-2">
                                <label class="flex items-center gap-2 text-sm text-gray-800 dark:text-gray-200 cursor-pointer">
                                    <input type="radio" name="organization_id" value="all" x-model="scope"
                                           class="border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500">
                                    {{ __('admin.bulk_email_scope_all') }}
                                </label>
                                <label class="flex items-center gap-2 text-sm text-gray-800 dark:text-gray-200 cursor-pointer">
                                    <input type="radio" value="one" x-model="scope"
                                           class="border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500">
                                    {{ __('admin.bulk_email_scope_one_org') }}
                                </label>
                                {{-- `x-show` ne retire rien du DOM : sans `disabled`, ce
                                     `<select>` partage `name="organization_id"` avec le radio
                                     "all" juste au-dessus et serait soumis EN PLUS de lui —
                                     navigateur comme serveur gardent alors la DERNIERE valeur
                                     du formulaire (celle du select, vide), jamais celle du
                                     radio, meme quand « Toutes les Organizations » est coche.
                                     Trouve en recette navigateur (TASK-1803) : un formulaire
                                     POSTE reellement par un navigateur, pas une requete de
                                     test qui n'envoie que les champs qu'on lui donne. --}}
                                <select name="organization_id" x-show="scope === 'one'" x-cloak
                                        x-bind:disabled="scope !== 'one'"
                                        x-bind:required="scope === 'one'"
                                        class="mt-1 block w-full max-w-sm rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="">—</option>
                                    @foreach($organizations as $org)
                                        <option value="{{ $org->id }}" @selected(old('organization_id') === $org->id)>{{ $org->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @error('organization_id')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </fieldset>

                        {{-- Filtre membres : tous, ou seulement sans presentation. --}}
                        <fieldset>
                            <legend class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                {{ __('admin.bulk_email_filter_label') }}
                            </legend>
                            <div class="mt-2 space-y-2">
                                <label class="flex items-center gap-2 text-sm text-gray-800 dark:text-gray-200 cursor-pointer">
                                    <input type="radio" name="member_filter" value="all"
                                           @checked(old('member_filter', 'all') === 'all')
                                           class="border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500">
                                    {{ __('admin.bulk_email_filter_all') }}
                                </label>
                                <label class="flex items-center gap-2 text-sm text-gray-800 dark:text-gray-200 cursor-pointer">
                                    <input type="radio" name="member_filter" value="no_presentation"
                                           @checked(old('member_filter') === 'no_presentation')
                                           class="border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500">
                                    {{ __('admin.bulk_email_filter_no_presentation') }}
                                </label>
                            </div>
                            @error('member_filter')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </fieldset>

                        {{-- Source du contenu : libre, ou un modele existant. --}}
                        <fieldset>
                            <legend class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                {{ __('admin.bulk_email_source_label') }}
                            </legend>
                            <div class="mt-2 space-y-2">
                                <label class="flex items-center gap-2 text-sm text-gray-800 dark:text-gray-200 cursor-pointer">
                                    <input type="radio" name="content_source" value="custom" x-model="contentSource"
                                           class="border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500">
                                    {{ __('admin.bulk_email_source_custom') }}
                                </label>
                                <label class="flex items-center gap-2 text-sm text-gray-800 dark:text-gray-200 cursor-pointer">
                                    <input type="radio" name="content_source" value="template" x-model="contentSource"
                                           class="border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500">
                                    {{ __('admin.bulk_email_source_template') }}
                                </label>
                            </div>
                        </fieldset>

                        {{-- Contenu libre : sujet + bascule brut/HTML. --}}
                        <div x-show="contentSource === 'custom'" x-cloak class="space-y-4 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                            <div>
                                <label for="subject" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    {{ __('admin.common_subject') }}
                                </label>
                                <input type="text" name="subject" id="subject" value="{{ old('subject') }}"
                                       x-bind:required="contentSource === 'custom'"
                                       class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                @error('subject')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <fieldset>
                                <legend class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    {{ __('admin.bulk_email_format_label') }}
                                </legend>
                                <div class="mt-2 flex gap-4">
                                    <label class="flex items-center gap-2 text-sm text-gray-800 dark:text-gray-200 cursor-pointer">
                                        <input type="radio" name="content_format" value="plain" x-model="format"
                                               class="border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500">
                                        {{ __('admin.bulk_email_format_plain') }}
                                    </label>
                                    <label class="flex items-center gap-2 text-sm text-gray-800 dark:text-gray-200 cursor-pointer">
                                        <input type="radio" name="content_format" value="html" x-model="format"
                                               class="border-gray-300 dark:border-gray-600 text-indigo-600 focus:ring-indigo-500">
                                        {{ __('admin.bulk_email_format_html') }}
                                    </label>
                                </div>
                            </fieldset>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                                    {{ __('admin.bulk_email_content_label') }}
                                </label>
                                {{-- TASK-1803 — un SEUL champ `content_raw`, sa source change
                                     selon `format` : texte brut, ou l'editeur TipTap existant
                                     (`<x-markdown-wysiwyg-editor>`, deja utilise pour la bio),
                                     reutilise TEL QUEL plutot qu'une nouvelle variante qui
                                     sortirait du HTML brut. Converti en HTML au moment de
                                     l'envoi seulement (`BulkEmailSendService::sendOne()`). --}}
                                <template x-if="format === 'plain'">
                                    <textarea name="content_raw" rows="10"
                                              x-bind:required="contentSource === 'custom'"
                                              class="w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('content_raw') }}</textarea>
                                </template>
                                {{-- `x-if` n'accepte qu'un SEUL element racine dans son
                                     `<template>` : `<x-markdown-wysiwyg-editor>` rend DEUX
                                     freres (`<style>` puis `<div data-tiptap-container>`), et
                                     sans ce `<div>` d'enveloppe Alpine ne montait que le
                                     `<style>` en silence — aucune erreur, juste l'editeur
                                     absent. `x-init` reprend le MEME idiome que
                                     `dossiers/show.blade.php`/`roadmap-detail.blade.php` : le
                                     script d'init de l'editeur ne scanne qu'au chargement de
                                     page, un conteneur insere APRES (ici par `x-if`) reste une
                                     simple textarea sans le evenement `bp:markdown-editor:init`.
                                     Trouve en recette navigateur (TASK-1803). --}}
                                <template x-if="format === 'html'">
                                    <div x-init="$nextTick(() => document.dispatchEvent(new CustomEvent('bp:markdown-editor:init')))">
                                        <x-markdown-wysiwyg-editor name="content_raw" :value="old('content_raw')" rows="10" tall />
                                    </div>
                                </template>
                                @error('content_raw')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        {{-- Modele existant : apercu lecture-seule, aucun aller-retour serveur. --}}
                        <div x-show="contentSource === 'template'" x-cloak class="space-y-4 rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                            <div>
                                <label for="email_template_id" class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                                    {{ __('admin.bulk_email_template_label') }}
                                </label>
                                <select name="email_template_id" id="email_template_id" x-model="selectedTemplateId"
                                        x-bind:required="contentSource === 'template'"
                                        class="mt-1 block w-full max-w-md rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                                    <option value="">—</option>
                                    @foreach($templates as $template)
                                        <option value="{{ $template->id }}">{{ $template->name }}</option>
                                    @endforeach
                                </select>
                                @error('email_template_id')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>
                            <template x-if="selectedTemplate">
                                <div class="rounded-md border border-gray-200 dark:border-gray-700 p-3">
                                    <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('admin.common_subject') }}</p>
                                    <p class="text-sm text-gray-900 dark:text-gray-100" x-text="selectedTemplate.subject"></p>
                                    <p class="mt-2 text-xs font-medium text-gray-500 dark:text-gray-400">{{ __('admin.common_content') }}</p>
                                    <div class="mt-1 max-h-64 overflow-auto rounded bg-gray-50 dark:bg-gray-900 p-3 text-sm" x-html="selectedTemplate.content_html"></div>
                                </div>
                            </template>
                        </div>

                        <div class="flex justify-end">
                            <button type="submit" class="px-6 py-2 bg-indigo-600 text-white rounded-md hover:bg-indigo-700 font-medium">
                                {{ __('admin.bulk_email_preview_cta') }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            @if($inProgress->isNotEmpty())
                <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg">
                    <div class="p-6">
                        <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">
                            {{ __('admin.bulk_email_in_progress_title') }}
                        </h3>
                        <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                            @foreach($inProgress as $item)
                                <li class="py-2 flex items-center justify-between text-sm">
                                    <span class="text-gray-700 dark:text-gray-300">
                                        {{ $item->sent_count + $item->failed_count }} / {{ $item->total_recipients }}
                                        — {{ $item->created_at->diffForHumans() }}
                                    </span>
                                    <a href="{{ route('admin.email-bulksending.show', $item) }}" class="text-indigo-600 hover:underline">
                                        {{ __('admin.bulk_email_resume_cta') }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-admin-layout>
