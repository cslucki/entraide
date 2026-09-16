<?php

namespace App\Console\Commands;

use App\Models\LoopMessage;
use App\Models\Organization;
use App\Support\Ai\AiConversationTrace;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * TASK-1579 / CDC-02 TRACE-1A — `ai:inspect-conversation` : relier les tours.
 *
 * READ ONLY absolu, comme le mode EXPLAIN d'`ai:inspect-turn` (V0-H0) :
 * aucun provider, aucun moteur, aucune ecriture, aucune liaison de tenant
 * dans le conteneur. Le perimetre est l'Organization donnee — une chaine qui
 * en sort s'arrete (`CROSS_TENANT_LINK_REFUSED`, J7).
 *
 *   --message=<loop_messages.id>          n'importe quel maillon d'une chaine de reply (LoopChat)
 *   --shell-conversation=<conversation_id> les lignes assistant d'un fil Shell
 *   --depth=N                              borne de lecture (defaut 6 = celle du produit)
 *
 * La sortie dit, tour par tour, ce que le tour a VU (`turn.history`, V0-L) et
 * les derives du CDC-02 §5.1 — `YES | NO | UNAVAILABLE`, jamais une inference.
 */
class AiInspectConversationCommand extends Command
{
    protected $signature = 'ai:inspect-conversation
        {--organization= : Slug ou UUID de l\'Organization}
        {--message= : uuid d\'un loop_message de la chaine (LoopChat, strategie reply_chain)}
        {--shell-conversation= : conversation_id d\'un fil Shell (strategie shell_thread)}
        {--depth=6 : Nombre maximal de maillons lus (defaut = MAX_THREAD_DEPTH du produit)}
        {--json : Sortie JSON machine-readable}';

    protected $description = 'Relit une suite de tours IA (chaine de reply LoopChat ou fil Shell) et dit ce que chaque tour a vu des precedents. Lecture pure.';

    public function handle(): int
    {
        $message = trim((string) $this->option('message'));
        $conversation = trim((string) $this->option('shell-conversation'));

        if (($message === '') === ($conversation === '')) {
            return $this->refuser('Exactement UNE cle : --message (LoopChat) ou --shell-conversation (Shell). J3 : les deux strategies ne se melangent pas.');
        }

        $depth = (int) $this->option('depth');

        if ($depth < 1 || $depth > 50) {
            return $this->refuser('--depth doit etre entre 1 et 50.');
        }

        $organization = $this->resoudreOrganization();

        if ($organization === null) {
            return $this->refuser('Organization introuvable.');
        }

        if ($message !== '') {
            if (! Str::isUuid($message)) {
                return $this->refuser('--message doit etre un uuid.');
            }

            $ancre = LoopMessage::query()->whereKey($message)->where('organization_id', (string) $organization->id)->first();

            if (! $ancre instanceof LoopMessage) {
                return $this->refuser('Aucun message ne correspond a cette cle dans cette Organization.');
            }

            return $this->rendre(AiConversationTrace::fromLoopMessage($ancre, (string) $organization->id, $depth));
        }

        if (! Str::isUuid($conversation)) {
            return $this->refuser('--shell-conversation doit etre un uuid.');
        }

        $trace = AiConversationTrace::fromShellConversation($conversation, (string) $organization->id, $depth);

        if ($trace['turns'] === []) {
            return $this->refuser('Aucune ligne assistant pour cette conversation dans cette Organization.');
        }

        return $this->rendre($trace);
    }

    /** @param  array<string, mixed>  $trace */
    private function rendre(array $trace): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->line('');
        $this->info(sprintf('── CONVERSATION (%s, depth %d)', $trace['strategy'], $trace['depth']));
        foreach ($trace['anchor'] as $cle => $valeur) {
            $this->line(sprintf('  %-16s %s', $cle, $valeur));
        }
        $this->line(sprintf('  %-16s %d maillons, %d tours', 'chain', count($trace['chain']), count($trace['turns'])));
        $this->line(sprintf('  %-16s %s', 'stopped', $trace['stopped'] === null ? '(non)' : $trace['stopped']['reason_code']));

        foreach ($trace['turns'] as $tour) {
            $this->line('');
            $this->info(sprintf('── TOUR %d  %s', $tour['position'], $tour['turn_available'] ? '' : '(UNAVAILABLE : aucun bloc turn)'));
            foreach (['message_id', 'turn_id', 'execution_path', 'mode', 'status', 'reason_code'] as $cle) {
                $this->line(sprintf('  %-32s %s', $cle, $this->afficher($tour[$cle])));
            }
            foreach (['strategy', 'count', 'trigger_id', 'input_message_id'] as $cle) {
                $this->line(sprintf('  %-32s %s', 'history.'.$cle, $this->afficher($tour['history'][$cle] ?? null)));
            }
            foreach ($tour['derived'] as $cle => $valeur) {
                if ($cle === 'unavailable_reasons') {
                    continue;
                }
                $raison = $tour['derived']['unavailable_reasons'][$cle] ?? null;
                $this->line(sprintf('  %-32s %s%s', $cle, $valeur, $raison !== null ? "  ({$raison})" : ''));
            }
        }
        $this->line('');

        return self::SUCCESS;
    }

    private function resoudreOrganization(): ?Organization
    {
        $cle = trim((string) $this->option('organization'));

        if ($cle === '') {
            return null;
        }

        return Organization::query()->where('slug', $cle)->first()
            ?? (Str::isUuid($cle) ? Organization::query()->find($cle) : null);
    }

    private function refuser(string $message): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(['refused' => true, 'message' => $message], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }

    private function afficher(mixed $valeur): string
    {
        return match (true) {
            $valeur === null => 'null',
            is_bool($valeur) => $valeur ? 'true' : 'false',
            is_scalar($valeur) => (string) $valeur,
            default => (string) json_encode($valeur, JSON_UNESCAPED_UNICODE),
        };
    }
}
