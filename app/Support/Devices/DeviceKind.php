<?php

namespace App\Support\Devices;

/**
 * TASK-1694 bug AK — de quel appareil vient une connexion.
 *
 * ## La demande
 *
 * Cyril : « il faudrait que je puisse savoir qui se connecte avec un
 * smartphone, qui se connecte avec son ordinateur. Donc ajouter une colonne
 * pour cela, et un menu de filtre. »
 *
 * ## Rien a capturer : `login_logs.user_agent` existe deja
 *
 * 404 lignes au 24/10, aucune vide. La categorie est donc DERIVEE a la lecture,
 * sans migration — et elle s'applique retroactivement a tout l'historique. Une
 * colonne calculee n'aurait rien donne de plus et aurait laisse les anciennes
 * lignes vides.
 *
 * ## Quatre categories, pas deux — parce que les donnees en disent quatre
 *
 * Releve du 24/10 sur la base reelle :
 *
 *   · `Windows NT`, `X11; Linux`            -> ordinateur ;
 *   · `Android ... Mobile Safari`           -> telephone ;
 *   · `Android ... SamsungBrowser/30.0`     -> SANS jeton `Mobile` : c'est une
 *                                              TABLETTE, pas un telephone ;
 *   · `curl/7.81.0` (6 connexions)          -> ni l'un ni l'autre.
 *
 * Les six connexions en `curl` sont la raison de la quatrieme categorie : les
 * ranger dans « ordinateur » serait FAUX, et c'est exactement le genre de ligne
 * qu'on veut pouvoir isoler dans un journal de connexions.
 *
 * ## Le jeton qui decide, et pourquoi dans cet ordre
 *
 * `Mobile` avant `Android` : un Android AVEC `Mobile` est un telephone, le meme
 * SANS est une tablette. Tester `Android` d'abord les confondrait tous les deux.
 */
enum DeviceKind: string
{
    case Phone = 'phone';
    case Tablet = 'tablet';
    case Desktop = 'desktop';
    case Other = 'other';

    /**
     * Les fragments qui designent chaque categorie, dans l'ORDRE d'examen.
     *
     * L'ordre est porteur de sens : voir le docblock de la classe. Les outils
     * (`curl`, robots) sont examines EN PREMIER — un robot peut tres bien
     * annoncer « Windows » dans sa chaine, et serait alors compte comme un
     * ordinateur.
     *
     * @var array<string, list<string>>
     */
    private const SIGNATURES = [
        'other' => ['curl/', 'wget/', 'python-requests', 'bot', 'spider', 'headlesschrome', 'postman'],
        'tablet' => ['ipad', 'tablet', 'playbook', 'kindle', 'silk/'],
        'phone' => ['iphone', 'ipod', 'mobile', 'windows phone', 'blackberry'],
        'desktop' => ['windows nt', 'macintosh', 'x11', 'cros', 'linux'],
    ];

    /**
     * Classe une chaine d'agent utilisateur.
     *
     * Une chaine vide rend `Other` et non `Desktop` : ne pas savoir n'est pas
     * la meme chose que savoir que c'est un ordinateur, et un journal qui
     * confond les deux ment sur ses propres lacunes.
     */
    public static function fromUserAgent(?string $userAgent): self
    {
        $chaine = mb_strtolower(trim((string) $userAgent));

        if ($chaine === '') {
            return self::Other;
        }

        // `Android` sans jeton `Mobile` est une TABLETTE — regle que les
        // signatures seules ne peuvent pas exprimer, parce qu'elle porte sur
        // une ABSENCE.
        if (str_contains($chaine, 'android') && ! str_contains($chaine, 'mobile')) {
            foreach (self::SIGNATURES['other'] as $outil) {
                if (str_contains($chaine, $outil)) {
                    return self::Other;
                }
            }

            return self::Tablet;
        }

        foreach (self::SIGNATURES as $categorie => $fragments) {
            foreach ($fragments as $fragment) {
                if (str_contains($chaine, $fragment)) {
                    return self::from($categorie);
                }
            }
        }

        return self::Other;
    }

    /**
     * Les fragments SQL qui retrouvent cette categorie en base.
     *
     * Exposes ici, a cote de la classification, pour que le filtre et
     * l'affichage lisent la MEME regle. Deux listes separees finiraient par
     * dire deux choses differentes du meme journal — et c'est le filtre qui
     * aurait tort, en silence.
     *
     * @return list<string>
     */
    public function fragments(): array
    {
        return self::SIGNATURES[$this->value];
    }

    /**
     * Borne une requete a cette categorie.
     *
     * ## Pourquoi passer par les agents DISTINCTS
     *
     * Traduire la classification en `LIKE` donnerait deux regles pour une seule
     * idee — et c'est le filtre qui aurait tort, en silence, le jour ou l'une
     * des deux evolue. Certaines regles ne s'expriment d'ailleurs pas en SQL
     * simple : « Android SANS le jeton Mobile » porte sur une ABSENCE.
     *
     * Ici, on classe les chaines distinctes avec `fromUserAgent()` — la MEME
     * fonction que la colonne — puis on borne sur ces chaines. Le filtre et
     * l'affichage ne peuvent pas diverger : c'est le meme code.
     *
     * ## Le cout, mesure
     *
     * 404 lignes pour **33 agents distincts** au 24/10. Le rapport est ce qui
     * rend l'approche viable : les chaines d'agent se repetent enormement. Si
     * ce nombre devenait tres grand, c'est ici qu'il faudrait regarder — d'ou
     * la borne explicite plutot qu'une confiance tacite.
     *
     * ## `null` appartient a `Other`
     *
     * `whereIn` ne rattrape jamais un NULL. Ne pas savoir n'est pas savoir que
     * c'est un ordinateur : la categorie « autre » doit donc les reprendre
     * explicitement, sinon ces lignes disparaitraient de TOUS les filtres.
     */
    public static function scope(\Illuminate\Database\Eloquent\Builder $query, self $categorie): void
    {
        $agents = \Illuminate\Support\Facades\DB::table('login_logs')
            ->distinct()
            ->whereNotNull('user_agent')
            ->limit(self::BORNE_AGENTS)
            ->pluck('user_agent')
            ->filter(fn (string $agent) => self::fromUserAgent($agent) === $categorie)
            ->values()
            ->all();

        $query->where(function ($q) use ($agents, $categorie) {
            $q->whereIn('user_agent', $agents);

            if ($categorie === self::Other) {
                $q->orWhereNull('user_agent');
            }
        });
    }

    /**
     * Plafond du nombre de chaines d'agent examinees par le filtre.
     *
     * 33 distinctes pour 404 connexions au 24/10. Le plafond est large, et
     * nomme plutot que suppose : si la base en portait davantage, le filtre
     * deviendrait incomplet SANS RIEN DIRE, et c'est cette ligne qu'il faudrait
     * relire.
     */
    public const BORNE_AGENTS = 5000;

    public function labelKey(): string
    {
        return 'admin.device_'.$this->value;
    }

    /**
     * TASK-1812 correctif 10 — demande de Cyril : des compteurs par type de
     * navigateur sur `/admin/stats/login-history`, distinguant « smartphone
     * Android » et « smartphone iOS ».
     *
     * Le FILTRE ci-dessus n'a jamais eu besoin de ce detail — « Smartphone »
     * lui suffisait — donc `Phone` ne porte qu'UNE categorie. Les statistiques,
     * elles, le demandent : cette methode affine `fromUserAgent()` pour ce
     * seul cas, avec les MEMES jetons ('android', 'iphone', 'ipod') plutot que
     * d'en inventer une seconde liste qui finirait par diverger.
     *
     * Les autres categories (Tablet, Desktop, Other) n'ont qu'un systeme
     * possible d'apres les donnees relevees au 24/10 (docblock de la classe) :
     * les distinguer par OS aurait ajoute des categories vides, qu'une
     * statistique ne doit jamais presenter comme un fait.
     *
     * @return string Une des cinq cles : desktop, phone_android, phone_ios, tablet, other.
     */
    public static function statKey(?string $userAgent): string
    {
        $categorie = self::fromUserAgent($userAgent);

        if ($categorie !== self::Phone) {
            return $categorie->value;
        }

        $chaine = mb_strtolower(trim((string) $userAgent));

        return match (true) {
            str_contains($chaine, 'android') => 'phone_android',
            str_contains($chaine, 'iphone'), str_contains($chaine, 'ipod') => 'phone_ios',
            // Windows Phone, BlackBerry : ni Android ni iOS. Les y ranger de
            // force mentirait sur le systeme ; `Other` reste honnete.
            default => self::Other->value,
        };
    }

    /**
     * L'ordre d'affichage des statistiques, et les seules cles qu'elles
     * rendent. Fige ici pour que le controleur et la vue s'accordent sur
     * « quelles categories existent », la meme raison que `SIGNATURES` plus
     * haut fait deja autorite sur la classification.
     *
     * @return list<string>
     */
    public static function statKeysOrdonnees(): array
    {
        return ['desktop', 'phone_android', 'phone_ios', 'tablet', 'other'];
    }
}
