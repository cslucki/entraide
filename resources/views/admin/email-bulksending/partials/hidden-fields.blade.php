{{--
    TASK-1803 — les champs du formulaire d'origine, repassés en inputs
    cachés. Partagé entre le formulaire d'envoi final (`store`) et les
    boutons de pagination de l'aperçu (`preview`, qui re-poste le MÊME
    contrat avec juste `preview_index` en plus) : une seule liste de champs
    à tenir à jour, jamais deux copies qui divergent.
--}}
<input type="hidden" name="organization_id" value="{{ $validated['organization_id'] }}">
<input type="hidden" name="member_filter" value="{{ $validated['member_filter'] }}">
<input type="hidden" name="content_source" value="{{ $validated['content_source'] }}">
@if($validated['content_source'] === \App\Models\BulkEmailSend::CONTENT_SOURCE_TEMPLATE)
    <input type="hidden" name="email_template_id" value="{{ $validated['email_template_id'] }}">
@else
    <input type="hidden" name="subject" value="{{ $validated['subject'] }}">
    <input type="hidden" name="content_format" value="{{ $validated['content_format'] }}">
    <input type="hidden" name="content_raw" value="{{ $validated['content_raw'] }}">
@endif
