<?php

namespace App\Http\Controllers;

use App\Exceptions\TenancyException;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

abstract class Controller
{
    /** The signed-in user. Only for routes behind the `auth` middleware, where there always is one. */
    protected function user(Request $request): User
    {
        return $request->user() ?? throw new AuthenticationException;
    }

    /** The team the signed-in user is working in. Only for routes behind the `team` middleware. */
    protected function team(Request $request): Team
    {
        return $this->user($request)->currentTeam ?? throw TenancyException::noCurrentTeam();
    }
}
