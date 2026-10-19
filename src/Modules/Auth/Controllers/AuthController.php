<?php

declare(strict_types=1);

namespace App\Modules\Auth\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Modules\Auth\Services\AuthService;
use App\Modules\Auth\Services\PasswordResetService;

/** Admin authentication — /api/v1/admin/auth/* (story BE-11). */
final class AuthController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly PasswordResetService $passwordReset,
    ) {
    }

    public function login(Request $request): Response
    {
        return Response::data($this->auth->login($request->all(), $request->ip()));
    }

    public function me(Request $request): Response
    {
        return Response::data($this->auth->me($request->attribute('admin')));
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout($request->attribute('admin'), $request->ip());

        return Response::noContent();
    }

    public function changePassword(Request $request): Response
    {
        $this->auth->changePassword($request->attribute('admin'), $request->all(), $request->ip());

        return Response::data(['message' => 'Your password has been changed.']);
    }

    public function forgotPassword(Request $request): Response
    {
        $this->passwordReset->requestLink($request->all(), $request->ip());

        return Response::data(['message' => 'If that email belongs to an admin account, a reset link has been sent.']);
    }

    public function resetPassword(Request $request): Response
    {
        $this->passwordReset->reset($request->all(), $request->ip());

        return Response::data(['message' => 'Your password has been reset. You can now log in.']);
    }
}
