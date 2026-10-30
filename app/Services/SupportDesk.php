<?php

namespace App\Services;

use App\Models\SupportAttachment;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\PermissionCatalog as P;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Opens support requests, adds messages with their files and keeps each side's read state.
 * Files are written inside the transaction and removed again if it fails, so a failed
 * request leaves neither rows nor orphaned files.
 */
class SupportDesk
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  list<UploadedFile>  $files
     */
    public function open(User $author, string $subject, string $body, array $files): SupportTicket
    {
        return $this->withFiles(function (array &$stored) use ($author, $subject, $body, $files) {
            $ticket = SupportTicket::create([
                'author_id' => $author->id,
                'author_name' => $author->name,
                'subject' => $subject,
                'last_message_at' => now(),
            ]);

            $this->addMessage($ticket, $author, $body, $files, $stored);

            return $ticket;
        });
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    public function reply(SupportTicket $ticket, User $user, string $body, array $files): SupportMessage
    {
        return $this->withFiles(function (array &$stored) use ($ticket, $user, $body, $files) {
            $locked = SupportTicket::query()->lockForUpdate()->findOrFail($ticket->id);

            return $this->addMessage($locked, $user, $body, $files, $stored);
        });
    }

    /**
     * Marks every message up to now as seen by the viewer's side. Never moves the mark back,
     * even when two tabs of the same request are opened at once.
     */
    public function markRead(SupportTicket $ticket, User $user): void
    {
        $column = $ticket->isAuthor($user) ? 'author_read_message_id' : 'staff_read_message_id';
        $latest = max($ticket->last_author_message_id ?? 0, $ticket->last_staff_message_id ?? 0);

        if ($latest === 0 || ($ticket->{$column} ?? 0) >= $latest) {
            return;
        }

        SupportTicket::query()
            ->whereKey($ticket->id)
            ->where(fn ($query) => $query->whereNull($column)->orWhere($column, '<', $latest))
            ->update([$column => $latest]);

        $ticket->{$column} = $latest;
    }

    public function delete(SupportTicket $ticket): void
    {
        DB::transaction(function () use ($ticket) {
            $ticket->delete();

            $this->audit->record('support_ticket.deleted', 'support_ticket', $ticket->id, "Обращение №{$ticket->id}", 'Обращение удалено', [
                'subject' => [$ticket->subject, null],
                'author' => [$ticket->author_name, null],
            ]);
        });
    }

    /**
     * The sidebar count: answers the user has not read in their own requests and, for
     * support staff, requests of others with messages nobody from support has read.
     */
    public function unreadCount(User $user): int
    {
        $answers = SupportMessage::query()
            ->join('support_tickets', 'support_tickets.id', '=', 'support_messages.support_ticket_id')
            ->whereNull('support_tickets.deleted_at')
            ->where('support_tickets.author_id', $user->id)
            ->where('support_messages.from_staff', true)
            ->whereRaw('support_messages.id > COALESCE(support_tickets.author_read_message_id, 0)')
            ->count();

        if (! $user->can(P::SUPPORT_MANAGE)) {
            return $answers;
        }

        $waiting = SupportTicket::query()
            ->where(fn ($query) => $query->whereNull('author_id')->orWhere('author_id', '!=', $user->id))
            ->whereRaw('last_author_message_id > COALESCE(staff_read_message_id, 0)')
            ->count();

        return $answers + $waiting;
    }

    /**
     * @param  list<UploadedFile>  $files
     * @param  list<string>  $stored
     */
    private function addMessage(SupportTicket $ticket, User $user, string $body, array $files, array &$stored): SupportMessage
    {
        $fromStaff = ! $ticket->isAuthor($user);

        /** @var SupportMessage $message */
        $message = $ticket->messages()->create([
            'author_id' => $user->id,
            'author_name' => $user->name,
            'from_staff' => $fromStaff,
            'body' => $body,
        ]);

        foreach ($files as $file) {
            $path = $file->store("support/{$ticket->id}", SupportAttachment::DISK);

            if ($path === false) {
                throw new RuntimeException('Не удалось сохранить файл обращения.');
            }

            $stored[] = $path;

            $message->attachments()->create([
                'path' => $path,
                'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
                'mime_type' => Str::limit($file->getMimeType() ?? 'application/octet-stream', 150, ''),
                'size' => (int) $file->getSize(),
            ]);
        }

        $ticket->forceFill([
            $fromStaff ? 'last_staff_message_id' : 'last_author_message_id' => $message->id,
            'last_message_at' => $message->created_at,
        ])->save();

        return $message;
    }

    /**
     * @template T
     *
     * @param  callable(list<string>&): T  $work
     * @return T
     */
    private function withFiles(callable $work): mixed
    {
        $stored = [];

        try {
            return DB::transaction(function () use ($work, &$stored) {
                return $work($stored);
            });
        } catch (Throwable $exception) {
            if ($stored !== []) {
                Storage::disk(SupportAttachment::DISK)->delete($stored);
            }

            throw $exception;
        }
    }
}
