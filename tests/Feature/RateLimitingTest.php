<?php

use Illuminate\Support\Facades\Mail;

// Pin the limits so a local .env override (used for browser E2E runs) cannot change these tests.
beforeEach(fn () => config(['throttle.registration_per_hour' => 10, 'throttle.invitations_per_minute' => 5]));

it('throttles team invitations per user', function () {
    Mail::fake();
    $owner = ownerWithTeam('business');

    foreach (range(1, 5) as $i) {
        $this->actingAs($owner)->post('/team/invitations', ['email' => "p{$i}@example.com", 'role' => 'member'])->assertRedirect();
    }

    $this->actingAs($owner)->post('/team/invitations', ['email' => 'p6@example.com', 'role' => 'member'])
        ->assertStatus(429)->assertHeader('Retry-After');
});

it('shows a banner instead of an error page for throttled Inertia requests', function () {
    Mail::fake();
    $owner = ownerWithTeam('business');

    foreach (range(1, 5) as $i) {
        $this->actingAs($owner)->post('/team/invitations', ['email' => "p{$i}@example.com", 'role' => 'member']);
    }

    $this->actingAs($owner)->withHeader('X-Inertia', 'true')
        ->post('/team/invitations', ['email' => 'p6@example.com', 'role' => 'member'])
        ->assertRedirect()
        ->assertSessionHas('error', fn ($message) => str_contains($message, 'Too many attempts'));
});

it('keeps invitation limits separate per user', function () {
    Mail::fake();
    $a = ownerWithTeam('business');
    $b = ownerWithTeam('business');

    foreach (range(1, 5) as $i) {
        $this->actingAs($a)->post('/team/invitations', ['email' => "a{$i}@example.com", 'role' => 'member']);
    }

    $this->actingAs($a)->post('/team/invitations', ['email' => 'a6@example.com', 'role' => 'member'])->assertStatus(429);
    $this->actingAs($b)->post('/team/invitations', ['email' => 'b1@example.com', 'role' => 'member'])->assertRedirect();
});

it('throttles registration per IP', function () {
    foreach (range(1, 10) as $i) {
        $this->post('/register', [
            'name' => "User {$i}", 'email' => "user{$i}@example.com",
            'password' => 'password-123', 'password_confirmation' => 'password-123',
        ])->assertRedirect();
        $this->app['auth']->forgetGuards();
    }

    $this->post('/register', [
        'name' => 'Eleven', 'email' => 'eleven@example.com',
        'password' => 'password-123', 'password_confirmation' => 'password-123',
    ])->assertStatus(429);
});

it('throttles password reset requests per IP and email', function () {
    foreach (range(1, 5) as $i) {
        $this->post('/forgot-password', ['email' => 'someone@example.com']);
    }

    $this->post('/forgot-password', ['email' => 'someone@example.com'])->assertStatus(429);
    $this->post('/forgot-password', ['email' => 'other@example.com'])->assertStatus(302);
});

it('throttles billing actions per user', function () {
    $owner = ownerWithTeam('free');

    foreach (range(1, 10) as $i) {
        $this->actingAs($owner)->post('/billing/change', ['plan' => $i % 2 ? 'pro' : 'business'])->assertRedirect();
    }

    $this->actingAs($owner)->post('/billing/change', ['plan' => 'pro'])->assertStatus(429);
});

it('throttles the webhook endpoint per IP but allows normal bursts', function () {
    foreach (range(1, 300) as $i) {
        $this->postJson('/webhooks/stripe', [])->assertForbidden();
    }

    $this->postJson('/webhooks/stripe', [])->assertStatus(429);
});
