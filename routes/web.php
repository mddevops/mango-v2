<?php

use App\Http\Controllers\Distribution\DistributionController;
use App\Http\Controllers\Orders\CallRecordingController;
use App\Http\Controllers\Orders\OrderController;
use App\Http\Controllers\Orders\OrderReasonController;
use App\Http\Controllers\Orders\OrdersListPreferenceController;
use App\Http\Controllers\Orders\OrderStatusController;
use App\Http\Controllers\Organization\AuditLogController;
use App\Http\Controllers\Organization\DepartmentController;
use App\Http\Controllers\Organization\RoleController;
use App\Http\Controllers\Organization\ShowroomController;
use App\Http\Controllers\Organization\UserController;
use App\Http\Controllers\Sources\IntegrationConnectionController;
use App\Http\Controllers\Sources\MangoIntakeController;
use App\Http\Controllers\Sources\SiteIntakeController;
use App\Http\Controllers\Sources\SourceController;
use App\Http\Controllers\ReferenceSectionController;
use App\Http\Controllers\Sources\SourceReferenceController;
use App\Http\Controllers\Support\SupportTicketController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', WorkspaceController::class)->name('dashboard');

    Route::get('showrooms', [ShowroomController::class, 'index'])->name('showrooms.index');
    Route::post('showrooms', [ShowroomController::class, 'store'])->name('showrooms.store');
    Route::put('showrooms/{showroom}', [ShowroomController::class, 'update'])->name('showrooms.update');
    Route::patch('showrooms/{showroom}/status', [ShowroomController::class, 'status'])->name('showrooms.status');

    Route::get('departments', [DepartmentController::class, 'index'])->name('departments.index');
    Route::post('departments', [DepartmentController::class, 'store'])->name('departments.store');
    Route::put('departments/{department}', [DepartmentController::class, 'update'])->name('departments.update');
    Route::patch('departments/{department}/status', [DepartmentController::class, 'status'])->name('departments.status');

    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::get('users/create', [UserController::class, 'create'])->name('users.create');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::patch('users/{user}/status', [UserController::class, 'status'])->name('users.status');
    Route::put('users/{user}/password', [UserController::class, 'password'])->name('users.password');
    Route::patch('users/{user}/global-access', [UserController::class, 'globalAccess'])->name('users.global-access');

    Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
    Route::get('roles/create', [RoleController::class, 'create'])->name('roles.create');
    Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
    Route::get('roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
    Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');

    Route::get('sources', [SourceController::class, 'index'])->name('sources.index');
    Route::post('sources', [SourceController::class, 'store'])->name('sources.store');
    Route::put('sources/{source}', [SourceController::class, 'update'])->name('sources.update');
    Route::patch('sources/{source}/status', [SourceController::class, 'status'])->name('sources.status');
    Route::get('sources/{source}/site-intake', [SiteIntakeController::class, 'show'])->name('sources.site-intake.show');
    Route::post('sources/{source}/site-intake/key', [SiteIntakeController::class, 'issueKey'])->name('sources.site-intake.key');
    Route::delete('sources/{source}/site-intake/key', [SiteIntakeController::class, 'revokeKey'])->name('sources.site-intake.revoke');
    Route::post('site-requests/{siteRequest}/retry', [SiteIntakeController::class, 'retry'])->name('site-requests.retry');

    Route::get('references', ReferenceSectionController::class)->name('references.index');

    Route::get('references/order-statuses', [OrderStatusController::class, 'index'])->name('order-statuses.index');
    Route::post('references/order-statuses', [OrderStatusController::class, 'store'])->name('order-statuses.store');
    Route::patch('references/order-statuses/order', [OrderStatusController::class, 'reorder'])->name('order-statuses.reorder');
    Route::put('references/order-statuses/{id}', [OrderStatusController::class, 'update'])->whereNumber('id')->name('order-statuses.update');
    Route::patch('references/order-statuses/{id}/status', [OrderStatusController::class, 'status'])->whereNumber('id')->name('order-statuses.status');
    Route::patch('references/order-statuses/{id}/initial', [OrderStatusController::class, 'initial'])->whereNumber('id')->name('order-statuses.initial');

    Route::get('references/order-reasons/{type}', [OrderReasonController::class, 'index'])->name('order-reasons.index');
    Route::post('references/order-reasons/{type}', [OrderReasonController::class, 'store'])->name('order-reasons.store');
    Route::patch('references/order-reasons/{type}/order', [OrderReasonController::class, 'reorder'])->name('order-reasons.reorder');
    Route::put('references/order-reasons/{type}/{id}', [OrderReasonController::class, 'update'])->whereNumber('id')->name('order-reasons.update');
    Route::patch('references/order-reasons/{type}/{id}/status', [OrderReasonController::class, 'status'])->whereNumber('id')->name('order-reasons.status');

    Route::redirect('source-references', 'source-references/kinds');
    Route::get('source-references/{type}', [SourceReferenceController::class, 'index'])->name('source-references.index');
    Route::post('source-references/{type}', [SourceReferenceController::class, 'store'])->name('source-references.store');
    Route::patch('source-references/{type}/order', [SourceReferenceController::class, 'reorder'])->name('source-references.reorder');
    Route::put('source-references/{type}/{id}', [SourceReferenceController::class, 'update'])->whereNumber('id')->name('source-references.update');
    Route::patch('source-references/{type}/{id}/status', [SourceReferenceController::class, 'status'])->whereNumber('id')->name('source-references.status');

    Route::get('connections', [IntegrationConnectionController::class, 'index'])->name('connections.index');
    Route::post('connections', [IntegrationConnectionController::class, 'store'])->name('connections.store');
    Route::put('connections/{connection}', [IntegrationConnectionController::class, 'update'])->name('connections.update');
    Route::patch('connections/{connection}/status', [IntegrationConnectionController::class, 'status'])->name('connections.status');
    Route::get('connections/{connection}/mango', [MangoIntakeController::class, 'show'])->name('connections.mango.show');
    Route::post('connections/{connection}/extensions', [MangoIntakeController::class, 'storeExtension'])->name('connections.extensions.store');
    Route::delete('connections/{connection}/extensions/{extension}', [MangoIntakeController::class, 'destroyExtension'])->name('connections.extensions.destroy');
    Route::post('connections/{connection}/calls/{call}/rematch', [MangoIntakeController::class, 'rematch'])->name('connections.calls.rematch');
    Route::post('connections/{connection}/events/{event}/retry', [MangoIntakeController::class, 'retry'])->name('connections.events.retry');
    Route::redirect('sites', 'sources');
    Route::redirect('site-types', 'source-references/classifications');
    Route::redirect('agencies', 'source-references/agencies');

    Route::get('orders', [OrderController::class, 'index'])->name('orders.index');
    Route::post('orders', [OrderController::class, 'store'])->name('orders.store');
    Route::get('orders/create/sources', [OrderController::class, 'createSources'])->name('orders.create-sources');
    Route::put('orders/list-preferences', [OrdersListPreferenceController::class, 'update'])->name('orders.list-preferences');
    Route::get('orders/{order}', [OrderController::class, 'show'])->whereNumber('order')->name('orders.show');
    Route::put('orders/{order}', [OrderController::class, 'update'])->whereNumber('order')->name('orders.update');
    Route::get('orders/{order}/access', [OrderController::class, 'access'])->whereNumber('order')->name('orders.access');
    Route::get('orders/{order}/previous', [OrderController::class, 'previous'])->whereNumber('order')->name('orders.previous');
    Route::post('orders/{order}/comments', [OrderController::class, 'storeComment'])->whereNumber('order')->name('orders.comments.store');
    Route::post('orders/{order}/distribution', [DistributionController::class, 'distribute'])->whereNumber('order')->name('orders.distribute');
    Route::post('orders/{order}/take', [OrderController::class, 'take'])->whereNumber('order')->name('orders.take');
    Route::post('orders/{order}/transfer', [OrderController::class, 'transfer'])->whereNumber('order')->name('orders.transfer');
    Route::get('orders/{order}/recordings/{recording}/audio', [CallRecordingController::class, 'audio'])->whereNumber(['order', 'recording'])->middleware('throttle:120,1')->name('orders.recordings.audio');
    Route::post('orders/{order}/recordings/{recording}/play', [CallRecordingController::class, 'play'])->whereNumber(['order', 'recording'])->middleware('throttle:60,1')->name('orders.recordings.play');

    Route::redirect('distribution', 'distribution/schedules');
    Route::get('distribution/schedules', [DistributionController::class, 'schedules'])->name('distribution.schedules');
    Route::put('distribution/schedules/{showroom}/{user}', [DistributionController::class, 'updateSchedule'])->name('distribution.schedules.update');
    Route::post('distribution/schedules/{showroom}/{user}/copy', [DistributionController::class, 'copySchedule'])->name('distribution.schedules.copy');

    Route::get('audit', [AuditLogController::class, 'index'])->name('audit.index');

    Route::get('support', [SupportTicketController::class, 'index'])->name('support.index');
    Route::post('support', [SupportTicketController::class, 'store'])->name('support.store');
    Route::get('support/attachments/{attachment}', [SupportTicketController::class, 'attachment'])->whereNumber('attachment')->name('support.attachments.show');
    Route::get('support/{ticket}', [SupportTicketController::class, 'show'])->whereNumber('ticket')->name('support.show');
    Route::post('support/{ticket}/messages', [SupportTicketController::class, 'reply'])->whereNumber('ticket')->name('support.messages.store');
    Route::delete('support/{ticket}', [SupportTicketController::class, 'destroy'])->whereNumber('ticket')->name('support.destroy');
});

require __DIR__.'/settings.php';
