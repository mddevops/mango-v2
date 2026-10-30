<?php

namespace Tests\Feature\Support;

use App\Models\SupportAttachment;
use App\Models\SupportTicket;
use App\Models\User;
use App\Support\PermissionCatalog as P;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsOrganization;
use Tests\TestCase;

class SupportTicketsTest extends TestCase
{
    use BuildsOrganization;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        Storage::fake(SupportAttachment::DISK);
    }

    public function test_request_with_files_is_visible_to_author_and_support_only(): void
    {
        $author = $this->employee('operator');
        $other = $this->employee('operator');
        $staff = $this->staff();

        $this->actingAs($author)->post(route('support.store'), [
            'subject' => '  Не открывается заявка ',
            'body' => 'Ошибка при открытии',
            'files' => [UploadedFile::fake()->image('screen.png'), UploadedFile::fake()->create('log.pdf', 10, 'application/pdf')],
        ])->assertRedirect(route('support.index'));

        $ticket = SupportTicket::query()->sole();
        $this->assertSame('Не открывается заявка', $ticket->subject);
        $attachments = SupportAttachment::query()->get();
        $this->assertCount(2, $attachments);
        Storage::disk(SupportAttachment::DISK)->assertExists($attachments->pluck('path')->all());

        $this->actingAs($author)->get(route('support.index'))->assertInertia(fn (Assert $page) => $page
            ->component('support/index')
            ->has('tickets.data', 1)
            ->where('tickets.data.0.author', null)
            ->where('tickets.data.0.state', SupportTicket::STATE_SENT));

        $this->actingAs($other)->get(route('support.index'))->assertInertia(fn (Assert $page) => $page
            ->has('tickets.data', 0)
            ->where('hasAny', false));
        $this->actingAs($other)->get(route('support.show', $ticket))->assertForbidden();
        $this->actingAs($other)->get(route('support.attachments.show', $attachments[0]))->assertForbidden();
        $this->actingAs($other)->post(route('support.messages.store', $ticket), ['body' => 'Чужое'])->assertForbidden();
        $this->actingAs($other)->delete(route('support.destroy', $ticket))->assertForbidden();

        $this->actingAs($staff)->get(route('support.index'))->assertInertia(fn (Assert $page) => $page
            ->component('support/queue')
            ->has('tickets.data', 1)
            ->where('tickets.data.0.author', $author->name)
            ->where('tickets.data.0.state', SupportTicket::STATE_UNREAD));
        $this->actingAs($staff)->get(route('support.index', ['filter' => [['field' => 'state', 'operator' => 'is', 'values' => ['read']]]]))
            ->assertInertia(fn (Assert $page) => $page->has('tickets.data', 0));
        $this->actingAs($staff)->get(route('support.index', ['filter' => [['field' => 'search', 'operator' => 'contains', 'values' => [(string) $ticket->id]]]]))
            ->assertInertia(fn (Assert $page) => $page->has('tickets.data', 1));
        $this->actingAs($author)->get(route('support.attachments.show', $attachments[0]))->assertOk();
    }

    public function test_support_staff_answer_requests_instead_of_writing_them(): void
    {
        $this->actingAs($this->staff())->post(route('support.store'), ['subject' => 'Тема', 'body' => 'Текст'])
            ->assertForbidden();

        $this->assertSame(0, SupportTicket::query()->count());
    }

    public function test_files_are_limited_by_count_and_type(): void
    {
        $author = $this->employee('operator');

        $this->actingAs($author)->post(route('support.store'), [
            'subject' => 'Тема',
            'body' => 'Текст',
            'files' => [UploadedFile::fake()->create('run.exe', 1, 'application/x-msdownload')],
        ])->assertSessionHasErrors('files.0');

        $this->actingAs($author)->post(route('support.store'), [
            'subject' => 'Тема',
            'body' => 'Текст',
            'files' => array_map(fn (int $i) => UploadedFile::fake()->create("note{$i}.txt", 1, 'text/plain'), range(1, 11)),
        ])->assertSessionHasErrors('files');

        $this->actingAs($author)->post(route('support.store'), ['subject' => '', 'body' => ''])
            ->assertSessionHasErrors(['subject', 'body']);

        $this->assertSame(0, SupportTicket::query()->count());
        $this->assertSame([], Storage::disk(SupportAttachment::DISK)->allFiles());
    }

    public function test_read_states_and_unread_count_follow_the_conversation(): void
    {
        $author = $this->employee('operator');
        $staff = $this->staff();

        $this->actingAs($author)->post(route('support.store'), ['subject' => 'Вопрос', 'body' => 'Как работает?']);
        $ticket = SupportTicket::query()->sole();

        $this->assertSame(1, $this->unread($staff));
        $this->assertSame(0, $this->unread($author));

        $this->actingAs($staff)->get(route('support.show', $ticket))->assertOk();
        $this->assertSame(0, $this->unread($staff));
        $this->assertSame(SupportTicket::STATE_READ, $ticket->fresh()->stateFor($author));

        $this->actingAs($staff)->post(route('support.messages.store', $ticket), ['body' => 'Ответ 1']);
        $this->actingAs($staff)->post(route('support.messages.store', $ticket), ['body' => 'Ответ 2']);
        $this->assertSame(2, $this->unread($author));
        $this->assertSame(SupportTicket::STATE_UNREAD, $ticket->fresh()->stateFor($author));
        $this->assertSame(SupportTicket::STATE_SENT, $ticket->fresh()->stateFor($staff));

        $this->actingAs($author)->get(route('support.show', $ticket))->assertInertia(fn (Assert $page) => $page
            ->component('support/show')
            ->has('messages', 3)
            ->where('messages.0.body', 'Ответ 2')
            ->where('messages.0.from_staff', true));
        $this->assertSame(0, $this->unread($author));
        $this->assertSame(SupportTicket::STATE_READ, $ticket->fresh()->stateFor($staff));

        $this->actingAs($author)->post(route('support.messages.store', $ticket), ['body' => 'Уточнение']);
        $this->assertSame(1, $this->unread($staff));
        $this->assertFalse($ticket->messages()->latest('id')->first()->from_staff);
    }

    public function test_delete_hides_the_request_but_keeps_the_row(): void
    {
        $author = $this->employee('operator');
        $staff = $this->staff();

        $this->actingAs($author)->post(route('support.store'), ['subject' => 'Первое', 'body' => 'Текст']);
        $this->actingAs($author)->post(route('support.store'), ['subject' => 'Второе', 'body' => 'Текст']);
        [$first, $second] = SupportTicket::query()->orderBy('id')->get()->all();

        $this->actingAs($author)->delete(route('support.destroy', $first))->assertRedirect();
        $this->actingAs($staff)->delete(route('support.destroy', $second))->assertRedirect();

        $this->assertSame(0, SupportTicket::query()->count());
        $this->assertSame(2, SupportTicket::withTrashed()->count());
        $this->assertSame(0, $this->unread($staff));
        $this->actingAs($author)->get(route('support.show', $first))->assertNotFound();
    }

    private function staff(): User
    {
        $user = $this->employee('operator');
        $user->givePermissionTo(P::SUPPORT_MANAGE);

        return $user->fresh();
    }

    private function unread(User $user): int
    {
        $count = 0;

        $this->actingAs($user)->get(route('support.index'))->assertInertia(function (Assert $page) use (&$count) {
            $count = $page->toArray()['props']['supportUnread'];
        });

        return $count;
    }
}
