<?php

namespace Tests\Unit\Architecture;

use App\Services\Dossiers\DossierChunkEmbeddingService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * TASK-1283 (NIGHT ENDGAME V2, Phase 5) — tripwire : aucun appel provider
 * sans autorite economique ni ledger, PAR CONSTRUCTION.
 *
 * La matrice TASK-1282 a prouve qu'aucun appel provider joignable en
 * production ne contourne AiEconomicGuard ni le ledger — mais deux failles de
 * construction restaient : le resolve() NU de ClarifyUserHelpRequestService
 * ::analyze() (neutralise par TASK-1283) et le repli plateforme silencieux de
 * DossierChunkEmbeddingService::embed() quand $instance etait nullable
 * (supprime par TASK-1283). Ce test fige ces deux fermetures :
 *
 *  (a) SupervisionProviderResolver::resolve() n'est appele NULLE PART dans
 *      app/ en dehors de resolveUnderEconomicAuthority() — le seul point qui
 *      declare le credential plateforme, applique la garde economique AVANT
 *      le provider et ecrit le ledger sur chaque tentative ;
 *  (b) DossierChunkEmbeddingService::embed() exige une $instance string non
 *      nullable et non optionnelle — le credential est celui de
 *      l'Organization (doctrine TASK-1225), jamais un repli plateforme.
 *
 * INTERDICTION d'affaiblir ce test sans etendre la garde : si un nouvel
 * appelant de resolve() devient legitime, il doit passer par
 * resolveUnderEconomicAuthority() (ou une autorite equivalente garde+ledger,
 * a ajouter ICI explicitement) — jamais par un assouplissement du scan. La
 * detection de (a) est textuelle (motifs realistes de reintroduction, dont
 * celui, exact, de l'ancien CHR:78) : si un motif d'appel inedit apparait, on
 * ETEND la liste, on ne la reduit pas.
 */
class AiEconomicAuthorityIsolationTest extends TestCase
{
    private const RESOLVER_FILE = 'app/Services/Ai/SupervisionProviderResolver.php';

    private const CANONICAL_METHOD = 'resolveUnderEconomicAuthority';

    public function test_supervision_resolver_resolve_is_only_called_under_economic_authority(): void
    {
        $appDir = dirname(__DIR__, 3).'/app';
        $violations = [];

        foreach ($this->phpFiles($appDir) as $path) {
            $relative = 'app/'.str_replace($appDir.'/', '', $path);
            $source = (string) file_get_contents($path);

            if ($relative === self::RESOLVER_FILE) {
                $violations = array_merge($violations, $this->scanResolverItself($source, $relative));

                continue;
            }

            $violations = array_merge($violations, $this->scanConsumer($source, $relative));
        }

        $this->assertSame(
            [],
            $violations,
            "Appel NU a SupervisionProviderResolver::resolve() detecte — sans "
            ."autorite economique ni ledger (TASK-1283). Chemin canonique : "
            ."resolveUnderEconomicAuthority().\n".implode("\n", $violations),
        );
    }

    /**
     * ClarifyUserHelpRequestService::analyze() est neutralisee (TASK-1283) :
     * repli deterministe inconditionnel, et le service ne possede plus AUCUNE
     * dependance vers SupervisionProviderResolver. Reintroduire la dependance
     * est le premier pas du raccourci historique (ex-CHR:78) : ce test le
     * bloque des ce premier pas.
     */
    public function test_clarify_service_has_no_supervision_resolver_dependency(): void
    {
        // Le CODE seulement : les commentaires du service documentent
        // legitimement l'histoire du raccourci supprime et peuvent nommer la
        // classe. La garde vise l'import, le type ou l'appel — pas la prose.
        $source = $this->withoutComments((string) file_get_contents(
            dirname(__DIR__, 3).'/app/Services/Ai/ClarifyUserHelpRequestService.php',
        ));

        $this->assertDoesNotMatchRegularExpression(
            '/SupervisionProviderResolver(?!\w)/',
            $source,
            'ClarifyUserHelpRequestService ne doit plus referencer '
            .'SupervisionProviderResolver : analyze() est un repli deterministe '
            .'pur, la clarification gouvernee passe par clarifyInContext() '
            .'(garde economique + ledger) via ProviderResolver tenant.',
        );
    }

    public function test_dossier_chunk_embedding_requires_a_tenant_instance_by_construction(): void
    {
        $parameters = (new ReflectionMethod(DossierChunkEmbeddingService::class, 'embed'))->getParameters();

        $this->assertArrayHasKey(1, $parameters, 'embed() doit garder son parametre $instance.');

        $instance = $parameters[1];

        $this->assertSame('instance', $instance->getName());
        $this->assertTrue($instance->hasType(), '$instance doit rester type (string).');
        $this->assertSame('string', (string) $instance->getType());
        $this->assertFalse(
            $instance->getType()->allowsNull(),
            '$instance ne doit JAMAIS redevenir nullable : nullable = repli '
            .'silencieux sur la famille PLATEFORME, interdit par la doctrine '
            .'TASK-1225 (credential par Organization).',
        );
        $this->assertFalse(
            $instance->isOptional(),
            '$instance ne doit JAMAIS redevenir optionnelle : une valeur par '
            .'defaut recreerait le repli plateforme par construction.',
        );
    }

    /**
     * Dans le resolveur lui-meme, tout appel a resolve() doit se trouver DANS
     * le corps de resolveUnderEconomicAuthority(). Le perimetre du corps est
     * obtenu par comptage d'accolades depuis la signature — suffisant ici : le
     * corps ne contient aucune accolade dans une chaine.
     *
     * @return list<string>
     */
    private function scanResolverItself(string $source, string $relative): array
    {
        $violations = [];
        [$start, $end] = $this->methodSpan($source, self::CANONICAL_METHOD, $relative);

        if (preg_match_all('/(?:->|::)\s*resolve\s*\(/', $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
            foreach ($matches[0] as [$snippet, $offset]) {
                if ($offset < $start || $offset > $end) {
                    $violations[] = sprintf(
                        '%s:%d — appel a resolve() HORS de %s()',
                        $relative,
                        substr_count($source, "\n", 0, $offset) + 1,
                        self::CANONICAL_METHOD,
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * Motifs realistes d'appel nu depuis un consommateur : chaine directe sur
     * app(SupervisionProviderResolver::class), ou variable/propriete TYPEE
     * SupervisionProviderResolver (le motif exact de l'ancien CHR:78 :
     * propriete promue $resolver puis $this->resolver->resolve()).
     *
     * @return list<string>
     */
    private function scanConsumer(string $source, string $relative): array
    {
        if (! str_contains($source, 'SupervisionProviderResolver')) {
            return [];
        }

        $violations = [];

        $patterns = ['/SupervisionProviderResolver::class\s*\)\s*(?:->|\?->)\s*resolve\s*\(/'];

        $names = [];
        preg_match_all('/SupervisionProviderResolver\s+\$([A-Za-z_]\w*)/', $source, $typed);
        preg_match_all('/\$([A-Za-z_]\w*)\s*=\s*app\(\s*(?:\\\\?App\\\\Services\\\\Ai\\\\)?SupervisionProviderResolver::class\s*\)/', $source, $assigned);
        foreach (array_unique(array_merge($typed[1], $assigned[1])) as $name) {
            $names[] = $name;
            $patterns[] = '/(?:\$this\s*->\s*|\$)'.preg_quote($name, '/').'\s*(?:->|\?->)\s*resolve\s*\(/';
        }

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE) > 0) {
                foreach ($matches[0] as [$snippet, $offset]) {
                    $violations[] = sprintf(
                        '%s:%d — %s — resolve() nu, passer par %s()',
                        $relative,
                        substr_count($source, "\n", 0, $offset) + 1,
                        trim($snippet),
                        self::CANONICAL_METHOD,
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * @return array{0: int, 1: int} offsets [debut de signature, fin du corps]
     */
    private function methodSpan(string $source, string $method, string $relative): array
    {
        $signature = strpos($source, 'function '.$method.'(');
        $this->assertIsInt(
            $signature,
            "{$relative} : {$method}() introuvable — si le chemin canonique a "
            .'ete renomme, mettre a jour CE test en meme temps que la garde.',
        );

        $cursor = strpos($source, '{', $signature);
        $this->assertIsInt($cursor, "{$relative} : corps de {$method}() introuvable.");

        $depth = 0;
        $length = strlen($source);

        do {
            $char = $source[$cursor];
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
            }
            $cursor++;
        } while ($depth > 0 && $cursor < $length);

        return [$signature, $cursor];
    }

    private function withoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $dir): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
