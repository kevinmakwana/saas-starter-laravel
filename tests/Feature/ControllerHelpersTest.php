<?php

use App\Exceptions\TenancyException;
use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

/** A controller that exposes the base helpers, which routes behind `auth` and `team` rely on. */
function controllerHelpers(): object
{
    return new class extends Controller
    {
        public function signedIn(Request $request): User
        {
            return $this->user($request);
        }

        public function team(Request $request): Team
        {
            return parent::team($request);
        }
    };
}

function requestFor(?User $user): Request
{
    $request = Request::create('/');
    $request->setUserResolver(fn () => $user);

    return $request;
}

it('hands back the signed-in user and their current team', function () {
    $user = ownerWithTeam();
    $request = requestFor($user);

    expect(controllerHelpers()->signedIn($request))->toBe($user)
        ->and(controllerHelpers()->team($request)->is($user->currentTeam))->toBeTrue();
});

it('refuses when nobody is signed in', function () {
    expect(fn () => controllerHelpers()->signedIn(requestFor(null)))->toThrow(AuthenticationException::class);
});

it('says so plainly when the signed-in user has no current team', function () {
    expect(fn () => controllerHelpers()->team(requestFor(User::factory()->create())))
        ->toThrow(TenancyException::class, 'The signed-in user has no current team.');
});
