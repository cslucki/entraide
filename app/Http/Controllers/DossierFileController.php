<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDossierFileRequest;
use App\Models\Dossier;
use App\Models\DossierFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DossierFileController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureCurrentUserBelongsToCurrentOrganization();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);
        $this->authorize('viewFiles', $dossier);

        $files = DossierFile::query()
            ->where('dossier_id', $dossier->id)
            ->where('organization_id', $organization->id)
            ->with('uploader:id,first_name,name,email')
            ->latest()
            ->paginate(20);

        $usedBytes = (int) DossierFile::query()
            ->where('organization_id', $organization->id)
            ->whereNull('deleted_at')
            ->sum('size_bytes');

        return response()->json([
            'files' => $files,
            'quota' => [
                'used_bytes' => $usedBytes,
                'limit_bytes' => $organization->dossierStorageQuotaBytes(),
                'remaining_bytes' => $organization->dossierStorageRemainingBytes(),
            ],
        ]);
    }

    public function store(StoreDossierFileRequest $request): JsonResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureCurrentUserBelongsToCurrentOrganization();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);

        $uploadedFiles = $request->file('files');
        $disk = 'dossier_files';
        $createdFiles = [];
        $storedPaths = [];

        DB::beginTransaction();

        try {
            $quota = $organization->dossierStorageQuotaBytes();

            if ($quota !== null) {
                $usedBytes = (int) DossierFile::query()
                    ->where('organization_id', $organization->id)
                    ->whereNull('deleted_at')
                    ->lockForUpdate()
                    ->sum('size_bytes');

                $newTotalBytes = $usedBytes;
                foreach ($uploadedFiles as $file) {
                    $newTotalBytes += $file->getSize();
                }

                if ($newTotalBytes > $quota) {
                    DB::rollBack();

                    return response()->json([
                        'message' => __('dossiers.storage_quota_exceeded'),
                    ], 422);
                }
            }

            foreach ($uploadedFiles as $file) {
                $path = $file->store('dossier-files/'.$dossier->id, $disk);
                $storedPaths[] = $path;
                $checksum = hash_file('sha256', $file->getRealPath());

                $dossierFile = DossierFile::create([
                    'organization_id' => $organization->id,
                    'dossier_id' => $dossier->id,
                    'uploaded_by' => $request->user()->id,
                    'disk' => $disk,
                    'path' => $path,
                    'original_name' => $file->getClientOriginalName(),
                    'display_name' => $file->getClientOriginalName(),
                    'mime_type' => $file->getMimeType(),
                    'size_bytes' => $file->getSize(),
                    'checksum_sha256' => $checksum,
                    'source' => 'upload',
                ]);

                $dossierFile->load('uploader:id,first_name,name,email');
                $createdFiles[] = $dossierFile;
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();

            foreach ($storedPaths as $storedPath) {
                try {
                    Storage::disk($disk)->delete($storedPath);
                } catch (\Exception) {
                    // Storage cleanup failure logged but not rethrown
                }
            }

            return response()->json([
                'message' => __('dossiers.file_upload_failed'),
            ], 500);
        }

        return response()->json([
            'message' => __('dossiers.file_uploaded'),
            'files' => $createdFiles,
        ], 201);
    }

    public function show(Request $request): RedirectResponse|StreamedResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $file = $this->resolveFile($request->route('file'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureCurrentUserBelongsToCurrentOrganization();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);

        if ($file->dossier_id !== $dossier->id || $file->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('viewFiles', $dossier);

        try {
            if (config("filesystems.disks.{$file->disk}.driver") === 'local') {
                return Storage::disk($file->disk)->download($file->path, $file->original_name, [
                    'Content-Type' => $file->mime_type,
                ]);
            }

            $url = Storage::disk($file->disk)->temporaryUrl($file->path, now()->addMinutes(30));

            return redirect()->away($url);
        } catch (\Exception $e) {
            abort(404);
        }
    }

    public function destroy(Request $request): JsonResponse
    {
        $dossier = $this->resolveDossier($request->route('dossier'));
        $file = $this->resolveFile($request->route('file'));
        $organization = $this->currentOrganizationOrFail();
        $this->ensureCurrentUserBelongsToCurrentOrganization();
        $this->ensureDossierBelongsToCurrentOrganization($dossier);

        if ($file->dossier_id !== $dossier->id || $file->organization_id !== $organization->id) {
            abort(404);
        }

        $this->authorize('deleteFile', $dossier);

        try {
            Storage::disk($file->disk)->delete($file->path);
        } catch (\Exception) {
            // Storage deletion failure doesn't prevent DB cleanup
        }

        $file->delete();

        return response()->json([
            'message' => __('dossiers.file_deleted'),
        ]);
    }

    private function resolveDossier(string $dossier): Dossier
    {
        return Dossier::query()->whereKey($dossier)->firstOrFail();
    }

    private function resolveFile(string $file): DossierFile
    {
        return DossierFile::query()->whereKey($file)->firstOrFail();
    }

    private function currentOrganizationOrFail()
    {
        $organization = currentOrganization();

        if (! $organization) {
            abort(404);
        }

        return $organization;
    }

    private function ensureDossierBelongsToCurrentOrganization(Dossier $dossier): void
    {
        $organization = $this->currentOrganizationOrFail();

        if ($dossier->organization_id !== $organization->id) {
            abort(404);
        }
    }

    private function ensureCurrentUserBelongsToCurrentOrganization(): void
    {
        $organization = currentOrganization();
        $user = auth()->user();

        if (! $organization || ! $user || $user->organization_id !== $organization->id) {
            abort(404);
        }
    }
}
