<?php

namespace Database\Factories;

use App\Services\EmailerService;
use Illuminate\Database\Eloquent\Factories\Factory;

class EmailTemplateFactory extends Factory
{
    /**
     * TASK-1694 bug AJ — la factory ne fabrique plus de variables FANTOMES.
     *
     * ## Ce qu'elle posait
     *
     * `variables => ['{{user_name}}', '{{app_name}}']` — les deux noms qui
     * n'existent dans AUCUNE liste. Mesure du 24/10 : une chaine qui les emploie
     * ressort VERBATIM de `interpolate()`. Ce sont ceux que le bug W a retires
     * de l'aide a la saisie, parce qu'ils faisaient ecrire des emails casses.
     *
     * ## Pourquoi corriger un fixture de test
     *
     * Aucun effet en production — c'est ce qui le rendait facile a laisser. Mais
     * tant que ces noms restent ecrits quelque part, quelqu'un les recopie : ils
     * etaient dans l'aide, dans les tests et ici, comme s'ils etaient reels.
     *
     * Et ils ont deja coute : au bug W, mon assertion cherchait ces noms dans la
     * page et les TROUVAIT — ils venaient du gabarit fabrique ici, pas de la
     * documentation. Un faux rouge qui m'a fait chercher le defaut au mauvais
     * endroit.
     *
     * ## Lues, pas recopiees
     *
     * Depuis `EmailerService::ALLOWED_VARS`, comme la documentation du bug W :
     * une liste ecrite a la main derive le jour ou une variable est ajoutee, et
     * c'est exactement ce qui etait arrive.
     *
     * ## Les noms NUS, sans accolades
     *
     * Convention de la maison, relevee dans le catalogue du seeder
     * (`'variables' => ['name', 'url']`). La version precedente les entourait
     * d'accolades : troisieme incoherence du meme champ.
     */
    public function definition(): array
    {
        $variables = array_slice(EmailerService::ALLOWED_VARS, 0, 3);

        return [
            'slug' => fake()->slug(),
            'name' => fake()->words(3, true),
            'subject' => fake()->sentence(),
            'content_html' => '<p>'.fake()->paragraphs(3, true).'</p>',
            'variables' => $variables,
        ];
    }
}
