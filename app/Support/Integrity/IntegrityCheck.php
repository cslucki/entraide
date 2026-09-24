<?php

namespace App\Support\Integrity;

/**
 * TASK-1632 — le resultat d'UN controle.
 *
 * Une valeur, pas un moteur de regles. Le mandat mettait en garde contre le
 * surdimensionnement, et il a raison : un cockpit de six controles n'a pas
 * besoin d'un DSL.
 *
 * `$count` est volontairement nu — c'est a la description de dire ce qu'il
 * compte, parce que « 14 » ne veut pas la meme chose selon qu'on parle de
 * lignes cassees ou d'Organizations historiques. `$detailKey` n'est rempli
 * que si une vue detail existe reellement : un lien qui ouvre une page vide
 * est pire que pas de lien.
 */
class IntegrityCheck
{
    /**
     * @param  string  $key  identifiant stable, utilise dans les URLs de detail
     * @param  string  $group  la carte du cockpit ou ce controle s'affiche
     * @param  array<string, scalar>  $replacements  valeurs injectees dans la description
     */
    public function __construct(
        public readonly string $key,
        public readonly string $group,
        public readonly IntegrityStatus $status,
        public readonly int $count,
        public readonly array $replacements = [],
        public readonly ?string $detailKey = null,
    ) {}

    public function labelKey(): string
    {
        return 'admin.integrity.check_'.$this->key;
    }

    public function descriptionKey(): string
    {
        return 'admin.integrity.check_'.$this->key.'_desc';
    }

    public function hasDetail(): bool
    {
        return $this->detailKey !== null && $this->count > 0;
    }
}
