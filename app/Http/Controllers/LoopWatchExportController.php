<?php

namespace App\Http\Controllers;

use App\Models\Loop;
use App\Models\LoopWatchItem;
use App\Services\Loops\LoopWatchService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;

/**
 * TASK-1879 (demande Cyril, 22/11) — exporter la Veille d'une Boucle.
 *
 * Trois formats : CSV, Excel (.xlsx, PhpSpreadsheet deja present) et un
 * tableau IMPRIMABLE (« Enregistrer en PDF » du navigateur) — aucune
 * librairie PDF n'est installee, en ajouter une est une decision a part.
 *
 * Memes gardes que la Card : Organization courante = celle de la Boucle,
 * plugin actif, droit `veille.view`. Le filtre de categorie de la Card est
 * repris (`?categorie=<uuid>`), un UUID invalide est simplement ignore.
 *
 * Injection de formule : en CSV, une cellule qui commence par = + - @ est
 * prefixee d'une apostrophe ; en Excel, toutes les cellules sont ecrites
 * comme TEXTE explicite (jamais interpretees comme formule).
 */
class LoopWatchExportController extends Controller
{
    /** Borne de securite : un export n'est pas un dump de base. */
    private const LIMIT = 5000;

    public function __construct(private LoopWatchService $watch) {}

    public function __invoke(Request $request): Response
    {
        $loop = $request->route('loop');
        abort_unless($loop instanceof Loop, 404);

        $organization = currentOrganization();
        abort_unless($organization !== null && $loop->organization_id === $organization->id, 404);

        $user = $request->user();
        abort_unless($user !== null && $this->watch->canView($user, $loop), 403);

        $format = (string) $request->route('format');
        abort_unless(in_array($format, ['csv', 'xlsx', 'imprimer'], true), 404);

        $categorie = $request->query('categorie');
        $categorie = is_string($categorie) && Str::isUuid($categorie) ? $categorie : null;

        $items = $this->watch->itemsFor($loop, $categorie, self::LIMIT);
        $nomFichier = Str::slug(__('veille.export_file').' '.$loop->name).'-'.now()->format('Y-m-d');

        return match ($format) {
            'csv' => $this->csv($items, $nomFichier),
            'xlsx' => $this->xlsx($items, $nomFichier),
            'imprimer' => response()->view('loops.watch-export-print', [
                'loop' => $loop,
                'items' => $items,
                'categorie' => $categorie !== null ? $this->watch->findCategory($loop, $categorie) : null,
            ])->header('Cache-Control', 'no-store'),
        };
    }

    /** @return list<string> */
    private function entetes(): array
    {
        return [
            __('veille.field_date'),
            __('veille.field_category'),
            __('veille.field_subject'),
            __('veille.field_information'),
            __('veille.field_impact'),
            __('veille.field_source'),
            __('veille.export_author'),
        ];
    }

    /** @return list<string> */
    private function ligne(LoopWatchItem $item): array
    {
        return [
            $item->observed_on->format('d/m/Y'),
            (string) ($item->category?->name ?? ''),
            (string) ($item->subject ?? ''),
            (string) $item->information,
            (string) $item->impact,
            (string) $item->source_url,
            (string) ($item->author?->name ?? __('veille.author_unknown')),
        ];
    }

    /** @param  Collection<int, LoopWatchItem>  $items */
    private function csv(Collection $items, string $nomFichier): Response
    {
        return response()->streamDownload(function () use ($items) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF"); // BOM UTF-8 pour Excel
            fputcsv($handle, $this->entetes(), ';');

            foreach ($items as $item) {
                fputcsv($handle, array_map(self::neutraliser(...), $this->ligne($item)), ';');
            }

            fclose($handle);
        }, $nomFichier.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @param  Collection<int, LoopWatchItem>  $items */
    private function xlsx(Collection $items, string $nomFichier): Response
    {
        $classeur = new Spreadsheet;
        $feuille = $classeur->getActiveSheet();
        $feuille->setTitle(Str::limit(__('veille.export_sheet'), 31, ''));

        $lignes = array_merge([$this->entetes()], $items->map(fn (LoopWatchItem $i) => $this->ligne($i))->all());

        foreach ($lignes as $r => $valeurs) {
            foreach (array_values($valeurs) as $c => $valeur) {
                $feuille->getCell([$c + 1, $r + 1])->setValueExplicit($valeur, DataType::TYPE_STRING);
            }
        }

        $feuille->getStyle('A1:G1')->getFont()->setBold(true);
        $feuille->freezePane('A2');
        foreach (['A' => 12, 'B' => 20, 'C' => 40, 'D' => 60, 'E' => 60, 'F' => 40, 'G' => 22] as $col => $largeur) {
            $feuille->getColumnDimension($col)->setWidth($largeur);
        }
        $feuille->getStyle('A:G')->getAlignment()->setWrapText(true)->setVertical('top');

        return response()->streamDownload(function () use ($classeur) {
            (new Xlsx($classeur))->save('php://output');
            $classeur->disconnectWorksheets();
        }, $nomFichier.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** Une cellule CSV ne doit jamais etre lue comme une formule par un tableur. */
    public static function neutraliser(string $valeur): string
    {
        return $valeur !== '' && in_array($valeur[0], ['=', '+', '-', '@', "\t", "\r"], true)
            ? "'".$valeur
            : $valeur;
    }
}
