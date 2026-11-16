<?php

use App\Models\User;
use App\Modules\Settings\Models\Branch;
use App\Modules\Settings\Services\MailSettings;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\WelcomeNotification;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

/** Messages captured by the in-memory "array" mailer. */
function sentMails(): array
{
    return app('mailer')->getSymfonyTransport()->messages()->all();
}

it('saves SMTP settings with an encrypted password and applies them', function () {
    $admin = userWithRole('Admin');
    $this->actingAs($admin)->put(route('settings.mail.update'), [
        'enabled' => true, 'host' => 'smtp.example.com', 'port' => 465, 'encryption' => 'ssl', 'username' => 'billing@shop.in',
        'password' => 'app-secret-123', 'from_address' => 'billing@shop.in', 'from_name' => 'Shree Fashions',
    ])->assertSessionHas('success');

    expect(settings('mail.password'))->not->toBe('app-secret-123')
        ->and(Crypt::decryptString(settings('mail.password')))->toBe('app-secret-123')
        ->and(config('mail.default'))->toBe('smtp')
        ->and(config('mail.mailers.smtp'))->host->toBe('smtp.example.com')->port->toBe(465)->scheme->toBe('smtps')->password->toBe('app-secret-123')
        ->and(config('mail.from.address'))->toBe('billing@shop.in');

    // the page never sends the password back; empty password keeps the saved one
    $this->actingAs($admin)->get(route('settings.mail.edit'))->assertInertia(fn ($p) => $p->where('mail.has_password', true)->missing('mail.password'));
    $this->actingAs($admin)->put(route('settings.mail.update'), ['enabled' => true, 'host' => 'smtp.example.com', 'port' => 465, 'encryption' => 'ssl',
        'from_address' => 'billing@shop.in'])->assertSessionHas('success');
    expect(MailSettings::password())->toBe('app-secret-123');

    $this->actingAs(userWithRole('Manager'))->get(route('settings.mail.edit'))->assertForbidden();
});

it('sends a test email and reports SMTP failures', function () {
    $admin = userWithRole('Admin');
    config(['mail.default' => 'array']);
    $this->actingAs($admin)->post(route('settings.mail.test'), ['to' => 'me@shop.in'])->assertSessionHas('success');
    expect(sentMails())->toHaveCount(1)->and(sentMails()[0]->getEnvelope()->getRecipients()[0]->getAddress())->toBe('me@shop.in');

    // unreachable server → the error is shown, nothing crashes
    settings()->set(['mail.enabled' => true, 'mail.host' => '127.0.0.1', 'mail.port' => 1, 'mail.encryption' => 'none', 'mail.from_address' => 'x@shop.in']);
    $this->actingAs($admin)->post(route('settings.mail.test'), ['to' => 'me@shop.in'])->assertSessionHas('error', fn ($m) => str_starts_with($m, 'Sending failed'));
});

it('emails a reset link only to active accounts and never reveals who has one', function () {
    Notification::fake();
    $active = User::factory()->create(['email' => 'priya@shop.in', 'is_active' => true]);
    User::factory()->create(['email' => 'old@shop.in', 'is_active' => false]);

    $msg = 'If an account exists for that email, a link to reset the password is on its way. Check your inbox (and spam folder).';
    foreach (['priya@shop.in', 'old@shop.in', 'nobody@shop.in'] as $email) {
        $this->post(route('password.email'), ['email' => $email])->assertSessionHas('status', $msg)->assertSessionHasNoErrors();
    }
    Notification::assertSentTo($active, ResetPasswordNotification::class);
    Notification::assertCount(1);
});

it('hides "Forgot password" and refuses links when email is not set up', function () {
    config(['mail.default' => 'log']);
    $this->get(route('login'))->assertInertia(fn ($p) => $p->where('canResetPassword', false));
    $this->post(route('password.email'), ['email' => 'a@b.in'])->assertSessionHasErrors('email');

    config(['mail.default' => 'array']);
    $this->get(route('login'))->assertInertia(fn ($p) => $p->where('canResetPassword', true));
});

it('resets the password from the emailed link', function () {
    $user = User::factory()->create(['email' => 'ravi@shop.in', 'is_active' => true]);
    $token = Password::broker()->createToken($user);

    $this->post(route('password.store'), ['token' => $token, 'email' => 'ravi@shop.in', 'password' => 'NewPass123', 'password_confirmation' => 'NewPass123'])
        ->assertRedirect(route('login'));
    $this->post(route('login'), ['email' => 'ravi@shop.in', 'password' => 'NewPass123'])->assertRedirect();
    $this->assertAuthenticatedAs($user->fresh());
});

it('creates staff with a welcome email whose link lasts 3 days', function () {
    Notification::fake();
    config(['mail.default' => 'array']);
    $admin = userWithRole('Admin');
    $branch = Branch::factory()->create();

    $this->actingAs($admin)->post(route('users.store'), [
        'name' => 'New Cashier', 'email' => 'cashier2@shop.in', 'is_active' => true, 'role' => 'Cashier', 'branch_ids' => [$branch->id], 'send_invite' => true,
    ])->assertRedirect(route('users.index'))->assertSessionHas('success');

    $user = User::where('email', 'cashier2@shop.in')->first();
    $token = null;
    Notification::assertSentTo($user, WelcomeNotification::class, function ($n) use (&$token) {
        $token = $n->token;

        return true;
    });

    // two days later the welcome link still works (a normal reset link would have expired after 60 min)
    $this->travel(2)->days();
    auth()->logout();
    $this->post(route('password.store'), ['token' => $token, 'email' => 'cashier2@shop.in', 'password' => 'Counter123', 'password_confirmation' => 'Counter123'])
        ->assertRedirect(route('login'))->assertSessionHasNoErrors();
});

it('lets an admin email a reset link to a user', function () {
    Notification::fake();
    config(['mail.default' => 'array']);
    $admin = userWithRole('Admin');
    $user = userWithRole('Cashier');

    $this->actingAs($admin)->post(route('users.password-reset', $user))->assertSessionHas('success');
    Notification::assertSentTo($user, ResetPasswordNotification::class);

    config(['mail.default' => 'log']);
    $this->actingAs($admin)->post(route('users.password-reset', $user))->assertSessionHas('error');
    $this->actingAs(userWithRole('Cashier'))->post(route('users.password-reset', $user))->assertForbidden();
});

it('renders the branded reset email', function () {
    settings()->set(['business.name' => 'Shree Fashions']);
    $user = User::factory()->create(['name' => 'Priya']);
    $mail = (new ResetPasswordNotification('tok123'))->toMail($user);
    $html = (string) $mail->render();

    expect($mail->subject)->toBe('Shree Fashions: reset your password')
        ->and($html)->toContain('Shree Fashions')->toContain('Hello Priya')->toContain('tok123')->not->toContain('Laravel');
});
