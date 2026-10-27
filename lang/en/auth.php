<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines are used during authentication for various
    | messages that we need to display to the user. You are free to modify
    | these language lines according to your application's requirements.
    |
    */

    'failed' => 'These credentials do not match our records.',
    'deactivated' => 'Your account has been deactivated. Please contact the administrator.',
    'password' => 'The provided password is incorrect.',
    'throttle' => 'Too many login attempts. Please try again in :seconds seconds.',

    // Registration
    'register_title' => 'Create an account',
    'register_title_org' => 'Join :name',
    'register_subtitle' => 'Join the Entraide community',
    'register_subtitle_org' => 'Create your account on :name',
    'register_button' => 'Create my account',

    // TASK-1714 volet 1 — the organization menu, after the first name.
    'organization_choice_label' => 'Your organization',
    'organization_choice_hint' => 'Changing organization takes you to its sign-up page.',
    'first_name_label' => 'Your first name',
    'first_name_placeholder' => 'John',
    'name_label' => 'Your last name',
    'name_placeholder' => 'Doe',
    'email_label' => 'Email address',
    'phone_label' => 'Phone number',
    'phone_placeholder' => '+1 (555) 123-4567',
    'country_label' => 'Country',
    'country_placeholder' => 'Select your country',
    'password_label' => 'Password',
    'password_placeholder' => '8 characters minimum',
    'password_confirm_label' => 'Confirm password',
    'has_account' => 'Already have an account?',
    'login_link' => 'Sign in',

    // Login
    'login_title' => 'Sign in',
    'login_subtitle_org' => 'Sign in to :name',
    'login_button' => 'Sign in',
    'remember_me' => 'Remember me',
    'forgot_password_link' => 'Forgot your password?',
    'no_account' => 'Don\'t have an account yet?',
    'register_link' => 'Create an account',

    // Forgot password
    'forgot_password_title' => 'Forgot password',
    'forgot_password_subtitle' => 'Enter your email and we\'ll send you a reset link.',
    'forgot_password_button' => 'Send reset link',
    'back_to_login' => 'Back to sign in',
    'logout' => 'Logout',

    // TASK-1714 volet 2 — waiting for an administrator's approval.
    'approval_pending_title' => 'Your account is awaiting approval',
    'approval_pending_intro' => 'An administrator must approve your account before you can access the platform.',
    'approval_pending_intro_org' => 'An administrator of :name must approve your account before you can access it.',
    'approval_pending_email_done' => 'Your email address is confirmed: you have not missed anything, and nothing else is required from you.',
    'approval_pending_next' => 'You will receive an email as soon as your account is approved. You will then be able to sign in normally.',
    'account_pending_approval' => 'Your account is awaiting an administrator\'s approval.',

    // Reset password
    'reset_password_title' => 'New password',
    'reset_password_subtitle' => 'Choose a new password for your account.',
    'new_password_label' => 'New password',
    'reset_password_button' => 'Reset password',

];
