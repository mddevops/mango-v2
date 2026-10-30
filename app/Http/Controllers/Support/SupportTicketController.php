<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Http\Requests\Support\SupportMessageRequest;
use App\Http\Requests\Support\SupportTicketRequest;
use App\Models\SupportAttachment;
use App\Models\SupportMessage;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\SupportDesk;
use App\Support\PermissionCatalog as P;
use App\Support\TableFilter;
use App\Support\TableFilters;
use App\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SupportTicketController extends Controller
{
    private const PER_PAGE = 20;

    /** Types a browser shows by itself; everything else is downloaded. */
    private const INLINE_TYPES = ['image/', 'video/', 'application/pdf', 'text/plain'];

    /** An employee wrote after the support staff last opened the request. */
    private const UNREAD_FOR_STAFF = 'coalesce(last_author_message_id, 0) > coalesce(staff_read_message_id, 0)';

    public function __construct(private readonly SupportDesk $desk) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', SupportTicket::class);

        /** @var User $user */
        $user = $request->user();

        if ($user->can(P::SUPPORT_MANAGE)) {
            return $this->queue($request, $user);
        }

        $search = Str::limit(trim((string) $request->query('search', '')), 200, '');
        $sort = $request->query('sort') === 'old' ? 'old' : 'new';
        $direction = $sort === 'new' ? 'desc' : 'asc';

        $paginator = SupportTicket::query()
            ->visibleTo($user)
            ->when($search !== '', fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('subject', 'like', '%'.addcslashes($search, '%_\\').'%');

                if (ctype_digit($search)) {
                    $query->orWhere('id', (int) $search);
                }
            }))
            ->orderBy('last_message_at', $direction)
            ->orderBy('id', $direction)
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $hasAny = $paginator->total() > 0 || ($search !== '' && SupportTicket::query()->visibleTo($user)->exists());

        return Inertia::render('support/index', [
            'tickets' => TableQuery::paginated($paginator, fn (SupportTicket $ticket) => [
                'id' => $ticket->id,
                'subject' => $ticket->subject,
                'author' => $ticket->isAuthor($user) ? null : $ticket->author_name,
                'last_message_at' => $ticket->last_message_at->toISOString(),
                'state' => $ticket->stateFor($user),
            ]),
            'query' => ['search' => $search, 'sort' => $sort],
            'hasAny' => $hasAny,
            'upload' => $this->uploadLimits(),
        ]);
    }

    public function store(SupportTicketRequest $request): RedirectResponse
    {
        $this->authorize('create', SupportTicket::class);

        /** @var User $user */
        $user = $request->user();

        $this->desk->open($user, $request->subject(), $request->body(), $request->attachments());

        TableQuery::flashSuccess('Обращение отправлено.');

        return to_route('support.index');
    }

    public function show(Request $request, SupportTicket $ticket): Response
    {
        $this->authorize('view', $ticket);

        /** @var User $user */
        $user = $request->user();

        $this->desk->markRead($ticket, $user);

        $messages = $ticket->messages()->with('attachments')->orderByDesc('id')->get();

        return Inertia::render('support/show', [
            'ticket' => [
                'id' => $ticket->id,
                'subject' => $ticket->subject,
                'author' => $ticket->author_name,
                'created_at' => $ticket->created_at->toISOString(),
                'own' => $ticket->isAuthor($user),
            ],
            'messages' => $messages->map(fn (SupportMessage $message) => [
                'id' => $message->id,
                'author' => $message->author_name,
                'own' => $message->author_id === $user->id,
                'from_staff' => $message->from_staff,
                'body' => $message->body,
                'created_at' => $message->created_at->toISOString(),
                'attachments' => $message->attachments->map(fn (SupportAttachment $file) => [
                    'id' => $file->id,
                    'name' => $file->original_name,
                    'size' => $file->size,
                    'mime_type' => $file->mime_type,
                ])->values(),
            ])->values(),
            'staff' => $user->can(P::SUPPORT_MANAGE),
            'upload' => $this->uploadLimits(),
        ]);
    }

    public function reply(SupportMessageRequest $request, SupportTicket $ticket): RedirectResponse
    {
        $this->authorize('reply', $ticket);

        /** @var User $user */
        $user = $request->user();

        $this->desk->reply($ticket, $user, $request->body(), $request->attachments());

        TableQuery::flashSuccess('Сообщение отправлено.');

        return back();
    }

    public function destroy(SupportTicket $ticket): RedirectResponse
    {
        $this->authorize('delete', $ticket);

        $this->desk->delete($ticket);

        TableQuery::flashSuccess('Обращение удалено.');

        return back();
    }

    public function attachment(SupportAttachment $attachment): StreamedResponse
    {
        $ticket = $attachment->message->ticket;

        abort_if($ticket === null, 404);

        $this->authorize('view', $ticket);

        $disk = Storage::disk(SupportAttachment::DISK);

        abort_unless($disk->exists($attachment->path), 404);

        $inline = Str::startsWith($attachment->mime_type, self::INLINE_TYPES);

        return $disk->response($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ], $inline ? 'inline' : 'attachment');
    }

    /** Everyone's requests for the support staff, filtered and paginated on the server. */
    private function queue(Request $request, User $user): Response
    {
        $filters = TableFilters::fromRequest($request, [
            'search' => TableFilter::search(function (Builder $query, string $value): void {
                $query->where(function (Builder $inner) use ($value): void {
                    $pattern = '%'.addcslashes($value, '%_\\').'%';
                    $inner->where('subject', 'like', $pattern)->orWhere('author_name', 'like', $pattern);

                    if (ctype_digit($value)) {
                        $inner->orWhere('id', (int) $value);
                    }
                });
            }),
            'state' => TableFilter::choice([
                SupportTicket::STATE_UNREAD => fn (Builder $query) => $query->whereRaw(self::UNREAD_FOR_STAFF),
                SupportTicket::STATE_SENT => fn (Builder $query) => $query
                    ->whereRaw('not ('.self::UNREAD_FOR_STAFF.')')
                    ->whereRaw('coalesce(last_staff_message_id, 0) > coalesce(last_author_message_id, 0)')
                    ->whereRaw('coalesce(last_staff_message_id, 0) > coalesce(author_read_message_id, 0)'),
                SupportTicket::STATE_READ => fn (Builder $query) => $query
                    ->whereRaw('not ('.self::UNREAD_FOR_STAFF.')')
                    ->where(fn (Builder $inner) => $inner
                        ->whereRaw('coalesce(last_staff_message_id, 0) <= coalesce(last_author_message_id, 0)')
                        ->orWhereRaw('coalesce(last_staff_message_id, 0) <= coalesce(author_read_message_id, 0)')),
            ]),
        ]);

        $paginator = $filters->apply(SupportTicket::query()->visibleTo($user))
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate(TableQuery::perPage($request))
            ->withQueryString();

        return Inertia::render('support/queue', [
            'tickets' => TableQuery::paginated($paginator, fn (SupportTicket $ticket) => [
                'id' => $ticket->id,
                'subject' => $ticket->subject,
                'author' => $ticket->author_name,
                'created_at' => $ticket->created_at->toISOString(),
                'last_message_at' => $ticket->last_message_at->toISOString(),
                'state' => $ticket->stateFor($user),
            ]),
            'filters' => [
                'filter' => $filters->conditions,
                'per_page' => TableQuery::perPage($request),
            ],
        ]);
    }

    /**
     * @return array{max_files: int, types: list<string>}
     */
    private function uploadLimits(): array
    {
        return ['max_files' => SupportMessageRequest::MAX_FILES, 'types' => SupportMessageRequest::FILE_TYPES];
    }
}
