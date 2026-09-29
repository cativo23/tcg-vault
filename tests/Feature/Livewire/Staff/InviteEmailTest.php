<?php

declare(strict_types=1);

use App\Mail\InviteSent;
use App\Models\User;
use App\Modules\Invites\Models\Invite;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

function inviteAdmin(): User
{
    Permission::findOrCreate('manage-invites');
    $admin = User::factory()->create(['username' => 'carlos']);
    $admin->givePermissionTo('manage-invites');

    return $admin;
}

test('creating an invite emails the invited address', function () {
    Mail::fake();
    $admin = inviteAdmin();

    Livewire::actingAs($admin)
        ->test('staff.invite-manager')
        ->set('email', 'someone@example.com')
        ->call('createInvite')
        ->assertHasNoErrors()
        ->assertSee('Invite sent to someone@example.com');

    $invite = Invite::where('email', 'someone@example.com')->firstOrFail();

    Mail::assertQueued(InviteSent::class, fn (InviteSent $mail) => $mail->hasTo('someone@example.com')
        && $mail->invite->is($invite));
    Mail::assertQueuedCount(1);
});

test('no email goes out when the invite is refused', function () {
    Mail::fake();
    $admin = inviteAdmin();
    User::factory()->create(['email' => 'member@example.com']);
    Invite::factory()->create(['email' => 'pending@example.com']);

    foreach (['member@example.com', 'pending@example.com', 'not-an-email'] as $email) {
        Livewire::actingAs($admin)
            ->test('staff.invite-manager')
            ->set('email', $email)
            ->call('createInvite')
            ->assertHasErrors('email');
    }

    Mail::assertNothingQueued();
});

test('re-inviting over an expired invite emails the new invite once', function () {
    Mail::fake();
    $admin = inviteAdmin();
    Invite::factory()->create(['email' => 'again@example.com', 'expires_at' => now()->subDay()]);

    Livewire::actingAs($admin)
        ->test('staff.invite-manager')
        ->set('email', 'again@example.com')
        ->call('createInvite')
        ->assertHasNoErrors();

    $fresh = Invite::where('email', 'again@example.com')->usable()->firstOrFail();

    Mail::assertQueuedCount(1);
    Mail::assertQueued(InviteSent::class, fn (InviteSent $mail) => $mail->invite->is($fresh));
});

test('staff can resend a pending invite', function () {
    Mail::fake();
    $admin = inviteAdmin();
    $invite = Invite::factory()->create(['email' => 'lost@example.com']);

    Livewire::actingAs($admin)
        ->test('staff.invite-manager')
        ->call('resendInvite', $invite->id)
        ->assertSee('Invite sent to lost@example.com');

    Mail::assertQueued(InviteSent::class, fn (InviteSent $mail) => $mail->hasTo('lost@example.com'));
});

test('an invite that can no longer be used is not resent', function (array $state) {
    Mail::fake();
    $admin = inviteAdmin();
    $invite = Invite::factory()->create($state);

    Livewire::actingAs($admin)
        ->test('staff.invite-manager')
        ->call('resendInvite', $invite->id);

    Mail::assertNothingQueued();
})->with([
    'revoked' => [['revoked_at' => now()]],
    'used' => [['used_at' => now()]],
    'expired' => [['expires_at' => now()->subMinute()]],
]);

test('one invite is resent at most three times an hour', function () {
    Mail::fake();
    $admin = inviteAdmin();
    $invite = Invite::factory()->create();

    for ($i = 0; $i < 4; $i++) {
        Livewire::actingAs($admin)
            ->test('staff.invite-manager')
            ->call('resendInvite', $invite->id);
    }

    Mail::assertQueuedCount(3);
});

test('a regular user cannot resend an invite', function () {
    Mail::fake();
    $user = User::factory()->create();
    $invite = Invite::factory()->create();

    Livewire::actingAs($user)
        ->test('staff.invite-manager')
        ->assertForbidden();

    Mail::assertNothingQueued();
    expect($invite->refresh()->revoked_at)->toBeNull();
});

test('an invite revoked before its email leaves the queue is not sent', function () {
    $invite = Invite::factory()->create(['email' => 'late@example.com']);
    $mail = (new InviteSent($invite))->to('late@example.com');

    $invite->revoke();
    $mail->send(app('mail.manager'));

    expect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0);
});

test('a still-usable invite does go out when the queue sends it', function () {
    $invite = Invite::factory()->create(['email' => 'ontime@example.com']);

    (new InviteSent($invite))->to('ontime@example.com')->send(app('mail.manager'));

    expect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())->toHaveCount(1);
});

test('the invite email carries the working signed link, who sent it and when it expires', function () {
    $admin = inviteAdmin();
    $invite = Invite::factory()->create([
        'email' => 'someone@example.com',
        'created_by' => $admin->id,
        'expires_at' => now()->addDays(7),
    ]);

    $mail = new InviteSent($invite);

    $mail->assertHasSubject('You’re invited to tcg-vault');
    $mail->assertSeeInHtml($invite->signedUrl());
    $mail->assertSeeInText($invite->signedUrl());
    $mail->assertSeeInHtml('carlos');
    $mail->assertSeeInText($invite->expires_at->format('F j, Y'));
    $mail->assertSeeInText('no account is created');
});
