<?php

namespace App\Http\Controllers\Api;

use App\Models\AdminSupportTicket;
use App\Models\SupportAttachment;
use App\Models\SupportMailReceipt;
use App\Services\Support\SupportOperations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class SupportTicketController
{
    public function store(Request $request, SupportOperations $operations)
    {
        if (array_diff(array_keys($request->all()), ['email', 'description', 'images', 'voice', 'website', 'idempotency_key']) !== []) {
            throw ValidationException::withMessages(['request' => 'Запрос содержит недопустимые поля.']);
        }
        $input = [...$request->all(), 'idempotency_key' => $request->header('Idempotency-Key', $request->input('idempotency_key'))];
        $data = validator($input, [
            'email' => ['required', 'email:rfc', 'max:254'], 'description' => ['nullable', 'string', 'max:20000'],
            'voice' => ['sometimes', 'file'],
            'idempotency_key' => ['required', 'string', 'max:128', 'min:8'],
            'website' => ['nullable', 'string', 'max:0'], 'images' => ['sometimes', 'array', 'max:5'],
            'user_id' => ['prohibited'], 'assignee_id' => ['prohibited'],
            'status' => ['prohibited'], 'delivery_status' => ['prohibited'], 'metadata' => ['prohibited'],
        ])->validate();

        $body = $data['description'] ?? '';
        if (trim($body) === '' && ! $request->hasFile('voice')) {
            throw ValidationException::withMessages(['description' => 'Напишите сообщение или добавьте голосовую запись.']);
        }
        $body = trim($body) === '' ? '' : $body;

        return response()->json($operations->submit($data['email'], $body, $data['idempotency_key'], $request->file('images', []), $request->file('voice')), 201)->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, int $id, SupportOperations $operations)
    {
        $operations->authorize($request->user());

        return response()->json(['data' => AdminSupportTicket::with('messages.attachments')->findOrFail($id)])->header('Cache-Control', 'private, no-store');
    }

    public function reply(Request $request, int $id, SupportOperations $operations)
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:20000'], 'revision' => ['required', 'integer', 'min:1']]);

        return response()->json(['data' => $operations->reply($request->user(), $id, $data['body'], $data['revision'], (string) $request->header('Idempotency-Key'))], 201)->header('Cache-Control', 'private, no-store');
    }

    public function attachment(Request $request, int $ticket, int $attachment, SupportOperations $operations)
    {
        $operations->authorize($request->user());
        $file = SupportAttachment::whereKey($attachment)->whereHas('message', fn ($q) => $q->where('ticket_id', $ticket))->firstOrFail();
        abort_unless($file->status === 'ready' && ! $file->media_deletion_id && ! $file->message->media_deletion_id, 404);
        $audio = in_array($file->mime, ['audio/webm', 'audio/mp4'], true);
        $key = ! $audio && $request->boolean('thumbnail') ? $file->thumbnail_key : $file->preview_key;
        abort_unless($key, 404);
        $mime = $audio ? $file->mime : 'image/webp';
        $extension = $audio ? ($mime === 'audio/webm' ? 'webm' : 'm4a') : 'webp';

        return Storage::disk($file->disk)->response($key, 'support-attachment-'.$file->id.'.'.$extension, ['Content-Type' => $mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'], 'inline');
    }

    public function original(Request $request, int $receipt, SupportOperations $operations)
    {
        $operations->authorize($request->user());
        $receipt = SupportMailReceipt::findOrFail($receipt);
        abort_unless($receipt->original_key && ! $receipt->media_deletion_id, 404);

        return Storage::disk($receipt->disk)->download($receipt->original_key, 'support-original-'.$receipt->id.'.eml', ['Content-Type' => 'application/octet-stream', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
