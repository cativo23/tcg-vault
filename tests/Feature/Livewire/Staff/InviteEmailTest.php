<?php

declare(strict_types=1);

use App\Mail\InviteSent;
use App\Models\User;
use App\Modules\Invites\Models\Invite;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

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
        && $mail->inviteId === $invite->id);
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
    Mail::assertQueued(InviteSent::class, fn (InviteSent $mail) => $mail->inviteId === $fresh->id);
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

test('resending re-checks the permission, not just loading the page', function () {
    Mail::fake();
    $admin = inviteAdmin();
    $invite = Invite::factory()->create();

    $component = Livewire::actingAs($admin)->test('staff.invite-manager');
    $admin->revokePermissionTo('manage-invites');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $component->call('resendInvite', $invite->id)->assertForbidden();

    Mail::assertNothingQueued();
});

test('the notice for the last sent invite clears when the next one is refused', function () {
    Mail::fake();
    $admin = inviteAdmin();
    User::factory()->create(['email' => 'member@example.com']);

    Livewire::actingAs($admin)
        ->test('staff.invite-manager')
        ->set('email', 'first@example.com')
        ->call('createInvite')
        ->assertSee('Invite sent to first@example.com')
        ->set('email', 'member@example.com')
        ->call('createInvite')
        ->assertHasErrors('email')
        ->assertDontSee('Invite sent to first@example.com');
});

test('the resend limit is explained, not silently ignored', function () {
    Mail::fake();
    $admin = inviteAdmin();
    $invite = Invite::factory()->create();
    $component = Livewire::actingAs($admin)->test('staff.invite-manager');

    for ($i = 0; $i < 3; $i++) {
        $component->call('resendInvite', $invite->id);
    }

    $component->call('resendInvite', $invite->id)->assertHasErrors('resend');
});

test('if the email cannot be queued, the invite is kept and staff are told to resend', function () {
    $admin = inviteAdmin();
    Mail::shouldReceive('to')->andThrow(new RuntimeException('queue down'));

    Livewire::actingAs($admin)
        ->test('staff.invite-manager')
        ->set('email', 'someone@example.com')
        ->call('createInvite')
        ->assertOk()
        ->assertHasErrors('resend')
        ->assertDontSee('Invite sent to someone@example.com');

    expect(Invite::where('email', 'someone@example.com')->usable()->exists())->toBeTrue();
});

test('an invite deleted before its email leaves the queue is skipped, not failed', function () {
    $invite = Invite::factory()->create(['email' => 'gone@example.com']);
    $mail = (new InviteSent($invite))->to('gone@example.com');

    $invite->delete();
    $restored = unserialize(serialize($mail));
    $restored->send(app('mail.manager'));

    expect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())->toHaveCount(0);
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

test('revoking and re-inviting cannot be used to flood one address', function () {
    Mail::fake();
    $admin = inviteAdmin();
    $component = Livewire::actingAs($admin)->test('staff.invite-manager');

    for ($i = 0; $i < 4; $i++) {
        $component->set('email', 'victim@example.com')->call('createInvite');
        Invite::where('email', 'victim@example.com')->usable()->first()?->revoke();
    }

    Mail::assertQueuedCount(3);
    $component->assertHasErrors('email');
});

test('creating and resending share the per-address limit', function () {
    Mail::fake();
    $admin = inviteAdmin();
    $component = Livewire::actingAs($admin)->test('staff.invite-manager')
        ->set('email', 'shared@example.com')
        ->call('createInvite');
    $invite = Invite::where('email', 'shared@example.com')->firstOrFail();

    for ($i = 0; $i < 3; $i++) {
        $component->call('resendInvite', $invite->id);
    }

    Mail::assertQueuedCount(3);
});

test('one staff account can send at most 50 invite emails a day', function () {
    Mail::fake();
    $admin = inviteAdmin();
    $component = Livewire::actingAs($admin)->test('staff.invite-manager');

    // Real invites, in batches under the 20-a-minute creation limit.
    for ($i = 0; $i < 50; $i++) {
        if ($i > 0 && $i % 20 === 0) {
            $this->travel(61)->seconds();
        }
        $component->set('email', "person{$i}@example.com")->call('createInvite')->assertHasNoErrors();
    }
    $this->travel(61)->seconds();

    $component->set('email', 'fifty-first@example.com')->call('createInvite')->assertHasErrors('email');

    Mail::assertQueuedCount(50);
    expect(Invite::where('email', 'fifty-first@example.com')->exists())->toBeFalse();
});

test('an invite email that could not be queued does not use up the limit', function () {
    $admin = inviteAdmin();
    $invite = Invite::factory()->create(['email' => 'retry@example.com']);
    $component = Livewire::actingAs($admin)->test('staff.invite-manager');

    // The first three sends fail to reach the queue; the rest succeed.
    $calls = 0;
    $queued = 0;
    $pending = Mockery::mock();
    $pending->shouldReceive('queue')->andReturnUsing(function () use (&$queued) {
        $queued++;
    });
    Mail::shouldReceive('to')->andReturnUsing(function () use (&$calls, $pending) {
        if (++$calls <= 3) {
            throw new RuntimeException('queue down');
        }

        return $pending;
    });

    for ($i = 0; $i < 6; $i++) {
        $component->call('resendInvite', $invite->id);
    }
    expect($queued)->toBe(3);

    $component->call('resendInvite', $invite->id)->assertHasErrors('resend');
    expect($queued)->toBe(3);
});

test('giving back a slot after the limit window expired never adds an extra one', function () {
    $admin = inviteAdmin();
    $invite = Invite::factory()->create(['email' => 'late-fail@example.com']);
    $component = Livewire::actingAs($admin)->test('staff.invite-manager');

    // The first send fails only after the hour has run out, so its slot
    // is given back to a counter that has already expired.
    $calls = 0;
    $queued = 0;
    $pending = Mockery::mock();
    $pending->shouldReceive('queue')->andReturnUsing(function () use (&$queued) {
        $queued++;
    });
    Mail::shouldReceive('to')->andReturnUsing(function () use (&$calls, $pending) {
        if (++$calls === 1) {
            $this->travel(3601)->seconds();

            throw new RuntimeException('queue down');
        }

        return $pending;
    });

    for ($i = 0; $i < 5; $i++) {
        $component->call('resendInvite', $invite->id);
    }

    expect($queued)->toBe(3);
});

test('a slot given back after the window rolled over is not taken from the new window', function () {
    $admin = inviteAdmin();
    $invite = Invite::factory()->create(['email' => 'rollover@example.com']);

    // The first send fails only after the hour has run out, and by then
    // another send has already opened the next hour's window.
    $calls = 0;
    $queued = 0;
    $pending = Mockery::mock();
    $pending->shouldReceive('queue')->andReturnUsing(function () use (&$queued) {
        $queued++;
    });
    Mail::shouldReceive('to')->andReturnUsing(function () use (&$calls, $pending, $admin, $invite) {
        if (++$calls === 1) {
            $this->travel(3601)->seconds();
            Livewire::actingAs($admin)->test('staff.invite-manager')->call('resendInvite', $invite->id);

            throw new RuntimeException('queue down');
        }

        return $pending;
    });

    Livewire::actingAs($admin)->test('staff.invite-manager')->call('resendInvite', $invite->id);

    for ($i = 0; $i < 5; $i++) {
        Livewire::actingAs($admin)->test('staff.invite-manager')->call('resendInvite', $invite->id);
    }

    expect($queued)->toBe(3);
});

test('no invite email is counted or sent while another request holds the limit lock', function () {
    Mail::fake();
    $admin = inviteAdmin();
    $invite = Invite::factory()->create();
    $lock = Cache::lock('invite-mail-limits', 30);
    $lock->get();

    Livewire::actingAs($admin)
        ->test('staff.invite-manager')
        ->call('resendInvite', $invite->id)
        ->assertHasErrors('resend');

    $lock->release();
    Mail::assertNothingQueued();
});
test('a count left by the previous limiter format still counts after deploy', function () {
    Mail::fake();
    $admin = inviteAdmin();
    $invite = Invite::factory()->create(['email' => 'carried@example.com']);
    Cache::put('invite-mail-address:'.sha1('carried@example.com'), 3, 3600);

    Livewire::actingAs($admin)
        ->test('staff.invite-manager')
        ->call('resendInvite', $invite->id)
        ->assertHasErrors('resend');

    Mail::assertNothingQueued();
});
