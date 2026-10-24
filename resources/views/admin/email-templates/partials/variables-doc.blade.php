{{--
    TASK-1694 bug W — la documentation des variables, LUE a la source.

    ## La demande, et ce qu'elle a decouvert

    Cyril : « il faudrait une documentation pour indiquer les variables
    disponibles, sachant qu'on l'a sur /admin/email-templates/create ».

    Celle de la page de creation etait FAUSSE. Elle annoncait les variables
    `user_name` et `app_name` — mesure du 24/10 : ni l'une ni l'autre n'est dans
    `EmailerService::ALLOWED_VARS`, et une chaine qui les emploie ressort
    VERBATIM de `interpolate()`, accolades comprises. Quelqu'un qui suivait
    cette aide ecrivait un email casse.

    (Les noms sont ecrits ici SANS accolades, a dessein : un commentaire Blade
    qui en contient les laisse fuir dans le HTML rendu — mesure faite, le test
    de ce bug l'a attrape sur ma propre premiere version.)

    La recopier telle quelle sur la page d'edition aurait DOUBLE le defaut.

    ## Pourquoi ce fichier, et pas deux listes

    La liste n'est pas ecrite ici : elle est LUE dans
    `EmailerService::ALLOWED_VARS`, c'est-a-dire dans la constante que
    l'interpolation consulte elle-meme. Une documentation recopiee a la main
    derive le jour ou une variable est ajoutee — c'est exactement ce qui est
    arrive. Ici, ajouter une variable au service la fait apparaitre sur les
    deux ecrans, sans toucher a une vue.

    Un test mesure les deux moities : chaque variable de la constante est
    presente, et les deux noms fantomes ont disparu.

    ## Les libelles

    `admin.emailer_var_<nom>` quand il existe, sinon le nom brut. Une variable
    ajoutee au service s'affiche donc immediatement, meme avant d'avoir sa
    traduction — mieux vaut un nom seul qu'une variable invisible. Un test
    garde la couverture des libelles.
--}}
@php
    $_vars = \App\Services\EmailerService::ALLOWED_VARS;
@endphp

<div class="rounded-xl border border-indigo-200 bg-indigo-50/60 p-4 dark:border-indigo-800 dark:bg-indigo-900/20"
     data-emailer-variables-doc>
    <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">
        {{ __('admin.emailer_vars_title') }}
    </p>
    <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">
        {{ __('admin.emailer_vars_hint') }}
    </p>

    <dl class="mt-3 grid gap-x-6 gap-y-1.5 sm:grid-cols-2">
        @foreach($_vars as $_var)
            <div class="flex items-baseline gap-2">
                <dt>
                    <code class="rounded bg-white px-1.5 py-0.5 text-xs font-mono text-indigo-700 dark:bg-gray-900 dark:text-indigo-300"
                          data-emailer-variable="{{ $_var }}">@{{ {{ $_var }} }}</code>
                </dt>
                <dd class="text-xs text-gray-600 dark:text-gray-400">
                    {{ __('admin.emailer_var_'.$_var) === 'admin.emailer_var_'.$_var ? $_var : __('admin.emailer_var_'.$_var) }}
                </dd>
            </div>
        @endforeach
    </dl>

    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
        {{ __('admin.emailer_vars_unknown_note') }}
    </p>
</div>
