<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Invites\Models\Invite;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

test('a guest cannot reach the invite manager', function () {
    $this->get('/staff/invites')->assertRedirect('/login');
});

test('a regular user is forbidden from the invite manager route', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/staff/invites')->assertForbidden();
});

test('an admin can create an invite', function () {
    Permission::create(['name' => 'manage-invites']);
    $admin = User::factory()->create();
    $admin->givePermissionTo('manage-invites');

    Livewire::actingAs($admin)
        ->test('staff.invite-manager')
        ->set('email', 'someone@example.com')
        ->call('createInvite')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('invites', [
        'email' => 'someone@example.com',
        'created_by' => $admin->id,
    ]);
});

test('a regular user cannot even mount the invite manager component directly', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('staff.invite-manager')
        ->assertForbidden();

    expect(Invite::count())->toBe(0);
});

test('the invite manager shows a copyable signed link for each pending invite, so the admin can actually send it', function () {
    Permission::create(['name' => 'manage-invites']);
    $admin = User::factory()->create();
    $admin->givePermissionTo('manage-invites');

    Livewire::actingAs($admin)
        ->test('staff.invite-manager')
        ->set('email', 'someone@example.com')
        ->call('createInvite');

    $invite = Invite::where('email', 'someone@example.com')->firstOrFail();

    Livewire::actingAs($admin)
        ->test('staff.invite-manager')
        ->assertSee($invite->signedUrl());
});

test('a revoked invite no longer shows its link — there is nothing left to send', function () {
    Permission::create(['name' => 'manage-invites']);
    $admin = User::factory()->create();
    $admin->givePermissionTo('manage-invites');
    $invite = Invite::factory()->create();
    $url = $invite->signedUrl();
    $invite->revoke($admin->id);

    Livewire::actingAs($admin)
        ->test('staff.invite-manager')
        ->assertDontSee($url, false);
});

test('the invite list paginates at 24 per page, same as the collection admin table', function () {
    Permission::create(['name' => 'manage-invites']);
    $admin = User::factory()->create();
    $admin->givePermissionTo('manage-invites');
    Invite::factory()->count(30)->create();

    $component = Livewire::actingAs($admin)->test('staff.invite-manager');

    expect($component->viewData('invites')->count())->toBe(24);
    expect($component->viewData('invites')->total())->toBe(30);
});

test('page 2 of the invite list is reachable and shows the remaining invites', function () {
    Permission::create(['name' => 'manage-invites']);
    $admin = User::factory()->create();
    $admin->givePermissionTo('manage-invites');
    Invite::factory()->count(30)->create();

    $component = Livewire::actingAs($admin)
        ->test('staff.invite-manager')
        ->call('gotoPage', 2);

    expect($component->viewData('invites')->count())->toBe(6);
});

test('an admin can revoke an unused invite', function () {
    Permission::create(['name' => 'manage-invites']);
    $admin = User::factory()->create();
    $admin->givePermissionTo('manage-invites');
    $invite = Invite::factory()->create();

    Livewire::actingAs($admin)
        ->test('staff.invite-manager')
        ->call('revokeInvite', $invite->id);

    expect($invite->refresh()->revoked_at)->not->toBeNull();
});

test('an admin cannot invite an email that already has an account', function () {
    Permission::create(['name' => 'manage-invites']);
    $admin = User::factory()->create();
    $admin->givePermissionTo('manage-invites');
    $existing = User::factory()->create(['email' => 'taken@example.com']);

    Livewire::actingAs($admin)
        ->test('staff.invite-manager')
        ->set('email', 'taken@example.com')
        ->call('createInvite')
        ->assertHasErrors('email');

    expect(Invite::count())->toBe(0);
});

test('an admin cannot double-invite an email with an existing usable invite', function () {
    Permission::create(['name' => 'manage-invites']);
    $admin = User::factory()->create();
    $admin->givePermissionTo('manage-invites');
    Invite::factory()->create(['email' => 'pending@example.com']);

    Livewire::actingAs($admin)
        ->test('staff.invite-manager')
        ->set('email', 'pending@example.com')
        ->call('createInvite')
        ->assertHasErrors('email');

    expect(Invite::count())->toBe(1);
});

test('re-inviting an email whose only conflicting invite has expired self-heals instead of permanently locking the email out', function () {
    Permission::create(['name' => 'manage-invites']);
    $admin = User::factory()->create();
    $admin->givePermissionTo('manage-invites');
    // Expired but never revoked — used_at/revoked_at both still null,
    // so it still trips the partial unique index even though isUsable()
    // (and the app-level duplicate check) already treat it as unusable.
    $stale = Invite::factory()->create([
        'email' => 'again@example.com',
        'expires_at' => now()->subDay(),
    ]);

    Livewire::actingAs($admin)
        ->test('staff.invite-manager')
        ->set('email', 'again@example.com')
        ->call('createInvite')
        ->assertHasNoErrors();

    expect($stale->refresh()->revoked_at)->not->toBeNull();
    expect(Invite::where('email', 'again@example.com')->usable()->count())->toBe(1);
});

test('self-healing a stale invite fails gracefully, not with a raw database error, if a genuinely usable invite lands for the same email in between', function () {
    // The self-heal path (see Invite::revoke() and the catch block in
    // createInvite()) only re-checks the ONE conflicting row it already
    // knows about. A true race has a second window it doesn't cover:
    // between that first INSERT failing and the retry INSERT running,
    // some other request can land a genuinely usable invite for the
    // same email — the retry then collides with THAT row instead, and
    // nothing catches the second failure.
    Permission::create(['name' => 'manage-invites']);
    $admin = User::factory()->create();
    $admin->givePermissionTo('manage-invites');

    $stale = Invite::factory()->create([
        'email' => 'interleaved@example.com',
        'expires_at' => now()->subDay(),
    ]);

    $creatingCalls = 0;
    Invite::creating(function () use (&$creatingCalls) {
        $creatingCalls++;

        // Only on the retry (the 2nd attempt this request makes) —
        // simulate another admin's request winning the same race
        // window right after this one's self-heal revoked the stale
        // row but before its own retry INSERT lands. Written directly
        // through the query builder so it doesn't re-fire this same
        // 'creating' listener.
        if ($creatingCalls === 2) {
            DB::table('invites')->insert([
                'email' => 'interleaved@example.com',
                'created_by' => auth()->id(),
                'expires_at' => now()->addDays(7),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    });

    Livewire::actingAs($admin)
        ->test('staff.invite-manager')
        ->set('email', 'interleaved@example.com')
        ->call('createInvite')
        ->assertHasErrors('email');

    expect($stale->refresh()->revoked_at)->not->toBeNull();
});

test('invite creation is rate-limited per admin', function () {
    Permission::create(['name' => 'manage-invites']);
    $admin = User::factory()->create();
    $admin->givePermissionTo('manage-invites');

    // Livewire actions bypass the route's own throttle middleware
    // entirely (they run through /livewire/update, not this
    // component's GET route) — the limit has to live inside the
    // action itself.
    for ($i = 0; $i < 20; $i++) {
        Livewire::actingAs($admin)
            ->test('staff.invite-manager')
            ->set('email', "person{$i}@example.com")
            ->call('createInvite');
    }

    Livewire::actingAs($admin)
        ->test('staff.invite-manager')
        ->set('email', 'one-too-many@example.com')
        ->call('createInvite')
        ->assertHasErrors('email');

    expect(Invite::where('email', 'one-too-many@example.com')->exists())->toBeFalse();
});

test('a regular user cannot revoke an invite', function () {
    $user = User::factory()->create();
    $invite = Invite::factory()->create();

    Livewire::actingAs($user)
        ->test('staff.invite-manager')
        ->assertForbidden();

    expect($invite->refresh()->revoked_at)->toBeNull();
});

function normalisingAdmin(): User
{
    Permission::findOrCreate('manage-invites');
    $admin = User::factory()->create();
    $admin->givePermissionTo('manage-invites');

    return $admin;
}

test('an invited address is stored lowercased and trimmed', function () {
    Livewire::actingAs(normalisingAdmin())
        ->test('staff.invite-manager')
        ->set('email', '  New.Person@Example.COM ')
        ->call('createInvite')
        ->assertHasNoErrors();

    expect(Invite::sole()->email)->toBe('new.person@example.com');
});

test('an admin cannot invite a different-case spelling of an existing account', function () {
    User::factory()->create(['email' => 'member@example.com']);

    Livewire::actingAs(normalisingAdmin())
        ->test('staff.invite-manager')
        ->set('email', 'Member@Example.com')
        ->call('createInvite')
        ->assertHasErrors('email');

    expect(Invite::count())->toBe(0);
});

test('an admin cannot double-invite an address by changing its case', function () {
    Invite::factory()->create(['email' => 'pending@example.com']);

    Livewire::actingAs(normalisingAdmin())
        ->test('staff.invite-manager')
        ->set('email', 'PENDING@example.com')
        ->call('createInvite')
        ->assertHasErrors('email');

    expect(Invite::count())->toBe(1);
});

test('a quoted local part is refused, since it is the same mailbox as the unquoted one', function () {
    Livewire::actingAs(normalisingAdmin())
        ->test('staff.invite-manager')
        ->set('email', '"victim"@example.com')
        ->call('createInvite')
        ->assertHasErrors('email');

    expect(Invite::count())->toBe(0);
});

test('the database refuses two usable invites whose emails differ only in case', function () {
    Invite::factory()->create(['email' => 'race@example.com']);

    expect(fn () => DB::transaction(fn () => Invite::factory()->create(['email' => 'Race@example.com'])))
        ->toThrow(QueryException::class, 'invites_usable_email_unique');
});

test('self-healing finds a stale invite stored with different case', function () {
    $stale = Invite::factory()->create([
        'email' => 'Legacy@example.com',
        'expires_at' => now()->subDay(),
    ]);

    Livewire::actingAs(normalisingAdmin())
        ->test('staff.invite-manager')
        ->set('email', 'legacy@example.com')
        ->call('createInvite')
        ->assertHasNoErrors();

    expect($stale->refresh()->revoked_at)->not->toBeNull();
});

test('the case-insensitive index migration keeps one pending invite per address instead of failing on duplicates', function () {
    DB::statement('DROP INDEX invites_usable_email_unique');
    $older = Invite::factory()->create(['email' => 'Dup@example.com', 'created_at' => now()->subDays(2)]);
    $newer = Invite::factory()->create(['email' => 'dup@example.com', 'created_at' => now()->subDay()]);
    $expired = Invite::factory()->create(['email' => 'DUP@example.com', 'expires_at' => now()->subHour()]);
    $other = Invite::factory()->create(['email' => 'other@example.com']);

    (require database_path('migrations/2026_09_29_180000_make_usable_invite_email_index_case_insensitive.php'))->up();

    expect($newer->refresh()->revoked_at)->toBeNull()
        ->and($older->refresh()->revoked_at)->not->toBeNull()
        ->and($expired->refresh()->revoked_at)->not->toBeNull()
        ->and($other->refresh()->revoked_at)->toBeNull()
        ->and(DB::selectOne("select indexdef from pg_indexes where indexname = 'invites_usable_email_unique'")->indexdef)
        ->toContain('lower');
});
