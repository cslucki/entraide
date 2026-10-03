<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Proxies de confiance
    |--------------------------------------------------------------------------
    |
    | TASK-1663 — Laravel ne croit `X-Forwarded-Proto` que si la requete vient
    | d'un proxy declare de confiance. Sans cela, une requete HTTPS terminee par
    | un reverse proxy est vue comme `http` : les URL absolues sortent en `http`
    | sur une page servie en `https`, et le navigateur bloque le contenu actif.
    |
    | `Illuminate\Http\Middleware\TrustProxies` lit cette clef **en repli**, et
    | seulement si aucun proxy n'a ete declare par ailleurs :
    |
    |     $trustedIps = $this->proxies() ?: config('trustedproxy.proxies');
    |
    |     if (is_null($trustedIps) && (laravel_cloud() || …on-forge… || …on-vapor…)) {
    |         $trustedIps = '*';
    |     }
    |
    | Laisser cette valeur a `null` est donc un choix ACTIF, pas un oubli : c'est
    | ce qui preserve l'auto-detection ci-dessus. En production sur Laravel Cloud,
    | `laravel_cloud()` vaut `true` et le proxy est deja de confiance — y mettre
    | une valeur explicite court-circuiterait ce chemin.
    |
    | On ne renseigne donc `TRUSTED_PROXIES` que la ou l'auto-detection ne
    | s'applique pas : un banc local expose par un tunnel, dans un `.env` dedie
    | et non commite. La valeur `*` n'est pas un blanc-seing — elle fait
    | confiance a la SEULE IP APPELANTE
    | (`setTrustedProxyIpAddressesToTheCallingIp()`), donc au proxy qui tourne sur
    | la machine meme.
    |
    | Aucun host n'est jamais code ici.
    |
    */

    'proxies' => env('TRUSTED_PROXIES'),

];
