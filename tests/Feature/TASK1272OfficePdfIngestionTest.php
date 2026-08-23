<?php

namespace Tests\Feature;

use App\Jobs\IndexDossierFileChunks;
use App\Models\Dossier;
use App\Models\DossierChunk;
use App\Models\DossierFile;
use App\Models\Organization;
use App\Models\OrganizationAiSetting;
use App\Models\User;
use App\Services\Dossiers\DossierFileIndexer;
use App\Services\Dossiers\Extractors\DocumentTextExtractor;
use App\Services\Dossiers\Extractors\PdfTextExtractor;
use App\Services\Dossiers\Extractors\SpreadsheetTextExtractor;
use App\Services\Dossiers\Extractors\WordTextExtractor;
use App\Services\Dossiers\FileContentExtractor;
use App\Services\Dossiers\OrganizationRagOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Prompts\EmbeddingsPrompt;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpWord\PhpWord;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-1272 — ingestion RAG des documents Office et PDF par le pipeline
 * TASK-1216 inchange. Trois extracteurs (DOCX, PDF, XLSX) derriere une
 * frontiere minimale (DocumentTextExtractor), resolus par MIME dans
 * FileContentExtractor ; deux gardes de taille a deux endroits distincts
 * (fichier brut 50 Mo AVANT extraction, texte extrait 5 Mo APRES), le
 * comportement TXT/Markdown preserve a l'identique.
 */
class TASK1272OfficePdfIngestionTest extends TestCase
{
    use RefreshDatabase;

    private const DOCX = WordTextExtractor::MIME_TYPE;

    private const PDF = PdfTextExtractor::MIME_TYPE;

    private const XLSX = SpreadsheetTextExtractor::MIME_TYPE;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('dossier_files');

        config()->set('ai.dossiers.semantic_search.enabled', true);
        config()->set('ai.default_for_embeddings', 'openai');
        config()->set('ai.caching.embeddings.cache', false);
        config()->set('ai.providers.openai.driver', 'openai');
        config()->set('ai.providers.openai.key', 'platform-should-not-be-used');
        config()->set('ai.providers.openai.models.embeddings.default', 'text-embedding-3-small');
        config()->set('ai.providers.openai.models.embeddings.dimensions', $this->dimensions());
    }

    // ---- Contrat : MIME acceptes ----

    public function test_the_three_office_pdf_mime_types_join_the_supported_contract_without_removing_the_old_ones(): void
    {
        $this->assertSame(
            ['text/plain', 'text/markdown', self::DOCX, self::PDF, self::XLSX],
            FileContentExtractor::SUPPORTED_MIME_TYPES,
        );
        $this->assertSame(['txt', 'md', 'markdown', 'docx', 'pdf', 'xlsx'], FileContentExtractor::SUPPORTED_EXTENSIONS);
    }

    public function test_each_document_extractor_supports_exactly_its_own_mime_type(): void
    {
        $extractors = [new WordTextExtractor, new PdfTextExtractor, new SpreadsheetTextExtractor];
        $mimes = [self::DOCX, self::PDF, self::XLSX];

        foreach ($extractors as $index => $extractor) {
            foreach ($mimes as $candidate) {
                $this->assertSame($candidate === $mimes[$index], $extractor->supports($candidate), get_class($extractor).' / '.$candidate);
            }
            $this->assertFalse($extractor->supports('text/plain'));
            $this->assertFalse($extractor->supports('image/png'));
        }
    }

    // ---- Extraction reelle par les bibliotheques ----

    public function test_a_docx_is_extracted_by_phpword_with_headings_tables_and_lists(): void
    {
        $text = app(FileContentExtractor::class)->extract($this->docx(), self::DOCX, 'lyra.docx');

        $this->assertNotNull($text);
        $this->assertStringContainsString('Station Lyra', $text);
        $this->assertStringContainsString('exactly 18 green modules', $text);
        $this->assertStringContainsString('cell A cell B', $text);
        $this->assertStringContainsString('first item', $text);
        // PHPWord echappe a la lecture (htmlspecialchars) : le texte doit
        // retrouver sa forme d'origine.
        $this->assertStringContainsString('R&D', $text);
        $this->assertStringNotContainsString('&amp;', $text);
        $this->assertTrue(mb_check_encoding($text, 'UTF-8'));
    }

    public function test_a_pdf_is_extracted_by_pdfparser(): void
    {
        $text = app(FileContentExtractor::class)->extract($this->pdf('The Vega Station has exactly 41 amber panels.'), self::PDF, 'vega.pdf');

        $this->assertSame('The Vega Station has exactly 41 amber panels.', $text);
    }

    public function test_an_xlsx_is_extracted_by_phpspreadsheet_one_line_per_row(): void
    {
        $text = app(FileContentExtractor::class)->extract($this->xlsx(), self::XLSX, 'categories.xlsx');

        $this->assertNotNull($text);
        $this->assertStringContainsString('Catalog', $text);
        $this->assertStringContainsString('Category | Sub', $text);
        $this->assertStringContainsString('Design | Mini logo | 42', $text);
    }

    public function test_a_docx_guessed_as_zip_by_libmagic_is_still_extracted_thanks_to_its_extension(): void
    {
        $text = app(FileContentExtractor::class)->extract($this->docx(), 'application/zip', 'lyra.docx');

        $this->assertNotNull($text);
        $this->assertStringContainsString('Station Lyra', $text);
    }

    // ---- Robustesse : pas de texte, pas d'exception ----

    public function test_corrupt_office_or_pdf_bytes_yield_null_and_never_throw(): void
    {
        $extractor = app(FileContentExtractor::class);

        $this->assertNull($extractor->extract('not a pdf at all', self::PDF, 'x.pdf'));
        $this->assertNull($extractor->extract("PK\x03\x04garbage", self::DOCX, 'x.docx'));
        $this->assertNull($extractor->extract("PK\x03\x04garbage", self::XLSX, 'x.xlsx'));
        $this->assertNull($extractor->extract('', self::PDF, 'empty.pdf'));
    }

    public function test_a_document_without_extractable_text_produces_no_chunk_and_no_embedding(): void
    {
        // Un PDF valide mais vide de texte (scanne, image seule) : null,
        // aucun chunk partiel, aucun appel provider, aucune exception.
        [$org, $dossier, $file] = $this->fixture($this->pdf(''), 'scan.pdf', self::PDF);
        $this->tenantSetting($org, 'openai', 'sk-tenant');
        Embeddings::fake(fn (): array => throw new RuntimeException('Empty document must not embed.'))
            ->preventStrayEmbeddings();

        $count = app(DossierFileIndexer::class)->synchronize($org->id, $dossier->id, $file->id);

        $this->assertSame(0, $count);
        $this->assertSame(0, DossierChunk::query()->where('dossier_file_id', $file->id)->count());
        Embeddings::assertNothingGenerated();
    }

    public function test_a_document_that_lost_its_text_has_its_stale_chunks_removed(): void
    {
        [$org, $dossier, $file] = $this->fixture($this->pdf('The Vega Station has exactly 41 amber panels.'), 'vega.pdf', self::PDF);
        $this->tenantSetting($org, 'openai', 'sk-tenant');
        $seen = [];
        $this->fakeEmbeddings($seen);
        app(DossierFileIndexer::class)->synchronize($org->id, $dossier->id, $file->id);
        $this->assertSame(1, DossierChunk::query()->where('dossier_file_id', $file->id)->count());

        Storage::disk('dossier_files')->put($file->path, 'corrupted bytes');

        $count = app(DossierFileIndexer::class)->synchronize($org->id, $dossier->id, $file->id);

        $this->assertSame(0, $count);
        $this->assertSame(0, DossierChunk::query()->where('dossier_file_id', $file->id)->count());
    }

    // ---- G3 : deux gardes, deux endroits ----

    public function test_text_plain_and_markdown_keep_the_raw_5mb_guard_unchanged(): void
    {
        $extractor = app(FileContentExtractor::class);
        $justUnder = str_repeat('a', 5_000_000);
        $justOver = $justUnder.'a';

        $this->assertNotNull($extractor->extract($justUnder, 'text/plain', 'under.txt'));
        $this->assertNull($extractor->extract($justOver, 'text/plain', 'over.txt'));
        $this->assertNotNull($extractor->extract($justUnder, 'text/markdown', 'under.md'));
        $this->assertNull($extractor->extract($justOver, 'text/markdown', 'over.md'));
        // Les autres refus TASK-1216 sont intacts : binaire deguise, UTF-8 invalide.
        $this->assertNull($extractor->extract("text\0with nul", 'text/plain', 'nul.txt'));
        $this->assertNull($extractor->extract("\xff\xfe invalid", 'text/plain', 'bad.txt'));
    }

    public function test_a_raw_office_document_above_the_upload_limit_is_refused_before_any_extraction(): void
    {
        $spy = $this->spyExtractor(self::DOCX, 'short text');
        $extractor = new FileContentExtractor([$spy]);
        $raw = str_repeat('x', 51_200 * 1024 + 1);

        $this->assertNull($extractor->extract($raw, self::DOCX, 'huge.docx'));
        $this->assertSame(0, $spy->calls, 'la bibliotheque ne doit jamais voir un fichier au-dessus de la limite d’upload');
    }

    public function test_a_raw_office_document_above_5mb_is_accepted_when_its_text_is_small(): void
    {
        // Le cas que G3 debloque : un PDF de 5 Mo fait surtout d'images,
        // dont le texte tient en quelques Ko. La garde 5 Mo ne porte plus
        // sur le fichier.
        $spy = $this->spyExtractor(self::PDF, 'A 5 MB PDF whose text is tiny.');
        $extractor = new FileContentExtractor([$spy]);
        $raw = str_repeat('%', 5_162_325);

        $this->assertSame('A 5 MB PDF whose text is tiny.', $extractor->extract($raw, self::PDF, 'articles.pdf'));
        $this->assertSame(1, $spy->calls);
    }

    public function test_the_5mb_guard_applies_to_the_extracted_text_of_office_documents(): void
    {
        $tooMuchText = str_repeat('word ', 1_000_001); // 5 000 005 octets
        $extractor = new FileContentExtractor([$this->spyExtractor(self::DOCX, $tooMuchText)]);

        $this->assertNull($extractor->extract('small raw', self::DOCX, 'verbose.docx'));

        $enoughText = str_repeat('word ', 1_000_000); // 5 000 000 octets, limite incluse
        $extractor = new FileContentExtractor([$this->spyExtractor(self::DOCX, $enoughText)]);

        $this->assertNotNull($extractor->extract('small raw', self::DOCX, 'dense.docx'));
    }

    public function test_the_document_extractor_receives_a_real_temporary_file_that_is_cleaned_afterwards(): void
    {
        $spy = $this->spyExtractor(self::DOCX, 'ok');
        $extractor = new FileContentExtractor([$spy]);

        $extractor->extract('raw bytes', self::DOCX, 'any.docx');

        $this->assertNotNull($spy->lastPath);
        $this->assertSame('raw bytes', $spy->lastContents, 'le fichier temporaire porte exactement le contenu brut');
        $this->assertFileDoesNotExist($spy->lastPath, 'le fichier temporaire est supprime apres extraction');
    }

    public function test_extracted_text_is_sanitized_control_characters_and_invalid_utf8(): void
    {
        $dirty = "Title\x00\x07 with\x1B control\r\nand bad byte \xff here";
        $extractor = new FileContentExtractor([$this->spyExtractor(self::PDF, $dirty)]);

        $text = $extractor->extract('raw', self::PDF, 'dirty.pdf');

        $this->assertNotNull($text);
        $this->assertTrue(mb_check_encoding($text, 'UTF-8'));
        $this->assertSame(0, preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text));
        $this->assertStringContainsString('Title with control', $text);
    }

    // ---- Pipeline complet ----

    public function test_a_docx_is_indexed_end_to_end_through_the_unchanged_pipeline(): void
    {
        [$org, $dossier, $file] = $this->fixture($this->docx(), 'lyra.docx', self::DOCX);
        $this->tenantSetting($org, 'openai', 'sk-tenant-docx');
        $seen = [];
        $this->fakeEmbeddings($seen);

        $count = app(DossierFileIndexer::class)->synchronize($org->id, $dossier->id, $file->id);

        $this->assertGreaterThan(0, $count);
        $this->assertSame(['org:'.$org->id.':openai'], array_values(array_unique($seen)));
        $chunk = DossierChunk::query()->where('dossier_file_id', $file->id)->firstOrFail();
        $this->assertStringContainsString('18 green modules', $chunk->content);
        $this->assertNull($chunk->blog_post_id);
    }

    public function test_an_xlsx_is_indexed_end_to_end(): void
    {
        [$org, $dossier, $file] = $this->fixture($this->xlsx(), 'categories.xlsx', self::XLSX);
        $this->tenantSetting($org, 'openai', 'sk-tenant-xlsx');
        $seen = [];
        $this->fakeEmbeddings($seen);

        $count = app(DossierFileIndexer::class)->synchronize($org->id, $dossier->id, $file->id);

        $this->assertGreaterThan(0, $count);
        $this->assertStringContainsString('Mini logo', DossierChunk::query()->where('dossier_file_id', $file->id)->firstOrFail()->content);
    }

    public function test_reindexing_an_unchanged_pdf_is_idempotent_without_a_second_embedding(): void
    {
        [$org, $dossier, $file] = $this->fixture($this->pdf('The Vega Station has exactly 41 amber panels.'), 'vega.pdf', self::PDF);
        $this->tenantSetting($org, 'openai', 'sk-tenant');
        $seen = [];
        $this->fakeEmbeddings($seen);

        app(DossierFileIndexer::class)->synchronize($org->id, $dossier->id, $file->id);
        $first = DossierChunk::query()->where('dossier_file_id', $file->id)->count();
        $seen = [];
        app(DossierFileIndexer::class)->synchronize($org->id, $dossier->id, $file->id);

        $this->assertSame($first, DossierChunk::query()->where('dossier_file_id', $file->id)->count());
        $this->assertSame([], $seen);
    }

    public function test_a_pdf_without_tenant_credential_is_never_embedded_with_the_platform_key(): void
    {
        [$org, $dossier, $file] = $this->fixture($this->pdf('secret content'), 'vega.pdf', self::PDF);
        Embeddings::fake(fn (): array => throw new RuntimeException('Platform embedding must not be called.'))
            ->preventStrayEmbeddings();

        $count = app(DossierFileIndexer::class)->synchronize($org->id, $dossier->id, $file->id);

        $this->assertSame(0, $count);
        Embeddings::assertNothingGenerated();
    }

    // ---- Tenant ----

    public function test_cross_tenant_office_file_is_not_indexed_for_another_organization(): void
    {
        [$orgA, $dossierA, $fileA] = $this->fixture($this->docx(), 'lyra.docx', self::DOCX);
        $orgB = Organization::factory()->create();
        $this->tenantSetting($orgA, 'openai', 'sk-tenant-a');
        $this->tenantSetting($orgB, 'openai', 'sk-tenant-b');
        $seen = [];
        $this->fakeEmbeddings($seen);

        $count = app(DossierFileIndexer::class)->synchronize($orgB->id, $dossierA->id, $fileA->id);

        $this->assertSame(0, $count);
        $this->assertSame(0, DossierChunk::query()->where('dossier_file_id', $fileA->id)->count());
        $this->assertSame([], $seen);
    }

    // ---- Surfaces qui partagent le contrat ----

    public function test_the_index_files_command_selects_office_and_pdf_files_on_the_dedicated_queue(): void
    {
        [$org, $dossier] = $this->organizationAndDossier();
        $docx = $this->storedFile($org, $dossier, 'a.docx', self::DOCX, 'raw');
        $pdf = $this->storedFile($org, $dossier, 'b.pdf', self::PDF, 'raw');
        $xlsx = $this->storedFile($org, $dossier, 'c.xlsx', self::XLSX, 'raw');
        $png = $this->storedFile($org, $dossier, 'd.png', 'image/png', 'raw');
        Queue::fake();

        $this->artisan('dossiers:index-files', ['organization' => $org->id])
            ->expectsOutputToContain('Fichiers éligibles : 3')
            ->assertExitCode(0);

        Queue::assertPushed(IndexDossierFileChunks::class, 3);
        foreach ([$docx, $pdf, $xlsx] as $expected) {
            Queue::assertPushed(IndexDossierFileChunks::class, fn (IndexDossierFileChunks $job): bool => $job->fileId === $expected->id && $job->queue === 'dossier-files-indexing');
        }
        Queue::assertNotPushed(IndexDossierFileChunks::class, fn (IndexDossierFileChunks $job): bool => $job->fileId === $png->id);
    }

    public function test_the_rag_overview_counts_office_files_as_sources_with_their_real_format(): void
    {
        [$org, $dossier] = $this->organizationAndDossier(Dossier::VISIBILITY_ORGANIZATION);
        $this->storedFile($org, $dossier, 'notes.txt', 'text/plain', 'raw');
        $this->storedFile($org, $dossier, 'brief.docx', self::DOCX, 'raw');
        $this->storedFile($org, $dossier, 'paper.pdf', self::PDF, 'raw');
        $this->storedFile($org, $dossier, 'grid.xlsx', self::XLSX, 'raw');
        $this->storedFile($org, $dossier, 'zipped.docx', 'application/zip', 'raw');
        $this->storedFile($org, $dossier, 'photo.png', 'image/png', 'raw');

        $overview = app(OrganizationRagOverview::class);
        $sources = collect($overview->sources($org->id))->keyBy('title');

        $this->assertSame(5, $overview->summary($org->id)['files']);
        $this->assertSame('txt', $sources['notes.txt']['format']);
        $this->assertSame('docx', $sources['brief.docx']['format']);
        $this->assertSame('pdf', $sources['paper.pdf']['format']);
        $this->assertSame('xlsx', $sources['grid.xlsx']['format']);
        $this->assertSame('docx', $sources['zipped.docx']['format']);
        $this->assertArrayNotHasKey('photo.png', $sources);
        foreach (['docx', 'pdf', 'xlsx'] as $format) {
            $this->assertNotSame('ai.observatory_format_'.$format, __('ai.observatory_format_'.$format));
        }
    }

    // ---- helpers ----

    private function dimensions(): int
    {
        return config('database.default') === 'pgsql' ? 1536 : 8;
    }

    private function docx(): string
    {
        $word = new PhpWord;
        $section = $word->addSection();
        $section->addTitle('Station Lyra', 1);
        // PHPWord attend du texte deja echappe a l'ecriture (symetrique de
        // sa lecture) : `R&amp;D` dans le fichier = « R&D » dans Word.
        $section->addText('The Lyra Station contains exactly 18 green modules, R&amp;D wing included.');
        $table = $section->addTable();
        $table->addRow();
        $table->addCell()->addText('cell A');
        $table->addCell()->addText('cell B');
        $section->addListItem('first item');

        $path = tempnam(sys_get_temp_dir(), 'task1272-').'.docx';
        WordIOFactory::createWriter($word, 'Word2007')->save($path);
        $raw = (string) file_get_contents($path);
        @unlink($path);

        return $raw;
    }

    private function xlsx(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Catalog');
        $sheet->setCellValue('A1', 'Category');
        $sheet->setCellValue('B1', 'Sub');
        $sheet->setCellValue('A2', 'Design');
        $sheet->setCellValue('B2', 'Mini logo');
        $sheet->setCellValue('C2', 42);

        $path = tempnam(sys_get_temp_dir(), 'task1272-').'.xlsx';
        (new XlsxWriter($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();
        $raw = (string) file_get_contents($path);
        @unlink($path);

        return $raw;
    }

    /**
     * PDF 1.4 minimal mais complet (xref + trailer) — une fixture, pas un
     * parseur : une page, une police standard, un objet texte.
     */
    private function pdf(string $text): string
    {
        $stream = $text === '' ? '' : 'BT /F1 12 Tf 72 720 Td ('.$text.') Tj ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $index => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$body."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    /**
     * Un DocumentTextExtractor de test : rend un texte impose, compte ses
     * appels, retient le chemin temporaire recu et son contenu.
     */
    private function spyExtractor(string $mime, ?string $returns): DocumentTextExtractor
    {
        return new class($mime, $returns) implements DocumentTextExtractor
        {
            public int $calls = 0;

            public ?string $lastPath = null;

            public ?string $lastContents = null;

            public function __construct(private readonly string $mime, private readonly ?string $returns) {}

            public function supports(string $mimeType): bool
            {
                return $mimeType === $this->mime;
            }

            public function extract(string $absolutePath): ?string
            {
                $this->calls++;
                $this->lastPath = $absolutePath;
                $this->lastContents = (string) file_get_contents($absolutePath);

                return $this->returns;
            }
        };
    }

    /** @return array{0: Organization, 1: Dossier} */
    private function organizationAndDossier(string $visibility = Dossier::VISIBILITY_PRIVATE): array
    {
        $org = Organization::factory()->create();
        config()->set(
            'ai.dossiers.semantic_search.organization_ids',
            array_unique(array_merge(config('ai.dossiers.semantic_search.organization_ids', []), [$org->id])),
        );
        $owner = User::factory()->create(['organization_id' => $org->id]);
        $dossier = Dossier::create([
            'organization_id' => $org->id,
            'owner_id' => $owner->id,
            'name' => 'TASK1272 folder '.$org->id,
            'visibility' => $visibility,
        ]);

        return [$org, $dossier];
    }

    private function storedFile(Organization $org, Dossier $dossier, string $filename, string $mime, string $content): DossierFile
    {
        $path = 'dossier-files/'.$dossier->id.'/'.$filename;
        Storage::disk('dossier_files')->put($path, $content);

        return DossierFile::create([
            'organization_id' => $org->id,
            'dossier_id' => $dossier->id,
            'uploaded_by' => $dossier->owner_id,
            'disk' => 'dossier_files',
            'path' => $path,
            'original_name' => $filename,
            'display_name' => $filename,
            'mime_type' => $mime,
            'size_bytes' => strlen($content),
            'checksum_sha256' => hash('sha256', $content),
            'source' => 'upload',
        ]);
    }

    /** @return array{0: Organization, 1: Dossier, 2: DossierFile} */
    private function fixture(string $content, string $filename, string $mime): array
    {
        [$org, $dossier] = $this->organizationAndDossier();

        return [$org, $dossier, $this->storedFile($org, $dossier, $filename, $mime, $content)];
    }

    private function tenantSetting(Organization $org, string $provider, string $key): void
    {
        OrganizationAiSetting::factory()->create([
            'organization_id' => $org->id,
            'provider' => $provider,
            'model' => 'gpt-4o-mini',
            'api_key' => $key,
        ]);
    }

    /** @param array<int,string> $seen */
    private function fakeEmbeddings(array &$seen): void
    {
        Embeddings::fake(function (EmbeddingsPrompt $prompt) use (&$seen): array {
            $seen[] = $prompt->provider->name();

            return array_map(fn (int $i): array => array_fill(0, $prompt->dimensions, ($i + 1) / 10), array_keys($prompt->inputs));
        })->preventStrayEmbeddings();
    }
}
