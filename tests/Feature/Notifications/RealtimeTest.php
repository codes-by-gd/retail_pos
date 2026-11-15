<?php

use App\Modules\Notifications\Events\AlertPushed;
use App\Modules\Notifications\Notifications\AlertNotification;
use App\Modules\Notifications\NotificationTypes;
use App\Modules\Notifications\Services\Notifier;
use App\Modules\Settings\Models\Branch;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;

it('pushes each new alert to the recipients\' private channels', function () {
    $this->withoutDefer();
    Event::fake([AlertPushed::class]);
    $branch = Branch::factory()->create();
    $manager = userWithRole('Manager', [$branch]);
    $cashier = userWithRole('Cashier', [$branch]);

    app(Notifier::class)->send(new AlertNotification(NotificationTypes::DAILY_SUMMARY, 'Main: summary', '10 bills'), $branch->id);

    Event::assertDispatched(AlertPushed::class, fn ($e) => $e->userId === $manager->id && $e->unread === 1
        && $e->notification['title'] === 'Main: summary'
        && $e->broadcastOn()[0]->name === 'private-App.Models.User.'.$manager->id
        && $e->broadcastAs() === 'alert');
    Event::assertNotDispatched(AlertPushed::class, fn ($e) => $e->userId === $cashier->id);   // cashiers don't get sales summaries
});

it('lets users subscribe only to their own channel', function () {
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'k', 'broadcasting.connections.reverb.secret' => 's',
        'broadcasting.connections.reverb.app_id' => '1']);
    Broadcast::purge('reverb');
    Broadcast::setDefaultDriver('reverb');
    require base_path('routes/channels.php');
    $me = userWithRole('Manager');
    $other = userWithRole('Manager');

    $this->actingAs($me)->post('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-App.Models.User.'.$me->id])
        ->assertOk()->assertJsonStructure(['auth']);
    $this->actingAs($me)->post('/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-App.Models.User.'.$other->id])
        ->assertForbidden();
});

it('sends a test alert to yourself', function () {
    $this->withoutDefer();
    Event::fake([AlertPushed::class]);
    $user = userWithRole('Cashier');

    $this->actingAs($user)->post(route('notifications.test'))->assertRedirect();
    expect($user->fresh()->notifications->first()->data)->title->toBe('Test alert')->level->toBe('success');
    Event::assertDispatched(AlertPushed::class, fn ($e) => $e->userId === $user->id);
});

it('never lets a broken real-time server break the action', function () {
    $this->withoutDefer();
    Event::listen(AlertPushed::class, fn () => throw new RuntimeException('Reverb down'));
    $user = userWithRole('Manager');

    app(Notifier::class)->sendTo($user, new AlertNotification('test', 'Hi', 'There'));
    expect($user->fresh()->notifications)->toHaveCount(1);   // stored even though the push failed
});
