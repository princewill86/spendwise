<?php

namespace App\Http\Controllers;

use App\Domain\Platform\Settings;
use App\Models\Transaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AttachmentController extends Controller
{
    public function upload(Request $request, Settings $settings, Transaction $transaction): RedirectResponse
    {
        Gate::authorize('update', $transaction);
        $limit = $settings->get('security', [])['upload_limit'] ?? 5120;
        $request->validate(['file' => ['required', 'file', "max:{$limit}", 'mimetypes:image/jpeg,image/png,image/webp,application/pdf']]);
        $file = $request->file('file');
        $path = $file->store('receipts', 'local');
        abort_unless($path, 500);
        $filename = mb_substr(preg_replace('/[[:cntrl:]\\\\\/]/u', '_', mb_scrub($file->getClientOriginalName())) ?: 'receipt', 0, 180);
        try {
            DB::transaction(function () use ($transaction, $path, $filename): void {
                Transaction::lockForUpdate()->findOrFail($transaction->id);
                $old = DB::table('transaction_attachments')->where('transaction_id', $transaction->id)->value('path');
                DB::table('transaction_attachments')->upsert([
                    ['transaction_id' => $transaction->id, 'path' => $path, 'filename' => $filename, 'created_at' => now(), 'updated_at' => now()],
                ], ['transaction_id'], ['path', 'filename', 'updated_at']);
                if ($old) {
                    DB::afterCommit(fn () => Storage::disk('local')->delete($old));
                }
            });
        } catch (Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }

        return back()->with('status', 'Attachment uploaded.');
    }

    public function download(Transaction $transaction): StreamedResponse
    {
        Gate::authorize('update', $transaction);
        $attachment = DB::table('transaction_attachments')->where('transaction_id', $transaction->id)->firstOrFail();
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->download($attachment->path, $attachment->filename, ['Content-Type' => 'application/octet-stream']);
    }

    public function remove(Transaction $transaction): RedirectResponse
    {
        Gate::authorize('update', $transaction);
        DB::transaction(function () use ($transaction): void {
            Transaction::lockForUpdate()->findOrFail($transaction->id);
            $query = DB::table('transaction_attachments')->where('transaction_id', $transaction->id);
            $path = (clone $query)->value('path');
            if ($path) {
                $query->delete();
                DB::afterCommit(fn () => Storage::disk('local')->delete($path));
            }
        });

        return back()->with('status', 'Attachment removed.');
    }
}
